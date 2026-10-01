<?php
// Dynamic QR codes. The student's phone shows a token that changes
// every 30-60 s (setting: qr_refresh_seconds); a screenshot goes stale
// almost immediately and can never be used twice.
//
// Token format:  OQ1|S|<std_no>|<6-digit code>   (student)
//                OQ1|G|<group_id>|<6-digit code> (group Master QR)
//
// The secret never leaves the server: the student's page fetches a
// fresh token from qr_token.php (needs their login session), so a
// friend holding only a screenshot -- or even the page source -- has
// nothing reusable.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';

const QR_TOKEN_PREFIX  = 'OQ1';
const GATE_VERIFY_TTL  = 180; // seconds a guard's verified scan stays usable

function qr_period(): int
{
    return min(60, max(30, (int) get_setting('qr_refresh_seconds')));
}

function manual_gate_entry_allowed(): bool
{
    return get_setting('allow_manual_gate_entry') !== '0';
}

/** RFC 4226 HOTP (HMAC-SHA1, 6 digits) over the time step. */
function totp_code(string $secretHex, int $step): string
{
    $hash   = hash_hmac('sha1', pack('J', $step), (string) hex2bin($secretHex), true);
    $offset = ord($hash[19]) & 0x0F;
    $bin    = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            |  ord($hash[$offset + 3]);
    return str_pad((string) ($bin % 1000000), 6, '0', STR_PAD_LEFT);
}

function qr_secret_for(string $subject): string
{
    $pdo = get_db();
    $pdo->prepare('INSERT IGNORE INTO qr_secret (subject, secret) VALUES (:s, :secret)')
        ->execute(['s' => $subject, 'secret' => bin2hex(random_bytes(20))]);
    $stmt = $pdo->prepare('SELECT secret FROM qr_secret WHERE subject = :s');
    $stmt->execute(['s' => $subject]);
    return (string) $stmt->fetchColumn();
}

/** $kind = 'S' (student, $id = std_no) or 'G' (group, $id = group id). */
function qr_issue_token(string $kind, string $id): array
{
    $period = qr_period();
    $now    = time();
    $step   = intdiv($now, $period);
    $secret = qr_secret_for($kind . ':' . $id);

    return [
        'token'      => QR_TOKEN_PREFIX . '|' . $kind . '|' . $id . '|' . totp_code($secret, $step),
        'period'     => $period,
        'expires_in' => ($step + 1) * $period - $now,
    ];
}

/**
 * Checks a scanned token and CONSUMES it (a code window can be
 * accepted once). Accepts the current window plus one either side to
 * tolerate clock drift and a slow scan.
 *
 * @return array{ok:bool, kind?:string, id?:string, error?:string}
 */
function qr_verify_token(string $token): array
{
    $bad = fn(string $m) => ['ok' => false, 'error' => $m];

    $parts = explode('|', trim($token), 4);
    if (count($parts) !== 4 || $parts[0] !== QR_TOKEN_PREFIX || !in_array($parts[1], ['S', 'G'], true)
        || !preg_match('/^\d{6}$/', $parts[3]) || $parts[2] === '' || strlen($parts[2]) > 30) {
        return $bad('Not a valid outing QR code. Ask the student to open "My QR code" in the app (printed/saved QR images no longer work).');
    }
    [, $kind, $id, $code] = $parts;
    $subject = $kind . ':' . $id;

    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT secret, last_ts FROM qr_secret WHERE subject = :s');
    $stmt->execute(['s' => $subject]);
    $row = $stmt->fetch();
    if (!$row) {
        return $bad('Unknown QR code.');
    }

    $period = qr_period();
    $cur    = intdiv(time(), $period);
    foreach ([$cur, $cur - 1, $cur + 1] as $step) {
        if (!hash_equals(totp_code($row['secret'], $step), $code)) {
            continue;
        }
        $ts = $step * $period;
        if ($ts <= (int) $row['last_ts']) {
            return $bad('This QR code was already used. Ask the student to show the current code.');
        }
        // Atomic: only one scan can win a given window.
        $upd = $pdo->prepare('UPDATE qr_secret SET last_ts = :ts WHERE subject = :s AND last_ts < :ts2');
        $upd->execute(['ts' => $ts, 's' => $subject, 'ts2' => $ts]);
        if ($upd->rowCount() !== 1) {
            return $bad('This QR code was already used. Ask the student to show the current code.');
        }
        return ['ok' => true, 'kind' => $kind, 'id' => $id];
    }
    return $bad('QR code expired or invalid. Ask the student to hold up the live code (a screenshot will not work).');
}

// --- Guard-side "this scan was verified" marker (per guard session) ------

function gate_mark_verified(string $subject): void
{
    start_secure_session();
    $_SESSION['gate_verified'][$subject] = time() + GATE_VERIFY_TTL;
}

function gate_is_verified(string $subject): bool
{
    start_secure_session();
    return ($_SESSION['gate_verified'][$subject] ?? 0) > time();
}

function gate_clear_verified(string $subject): void
{
    start_secure_session();
    unset($_SESSION['gate_verified'][$subject]);
}
