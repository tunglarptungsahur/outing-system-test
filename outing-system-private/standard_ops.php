<?php
// Standard/QR track: no rules, no approval workflow. A guard logs a
// check-out, and later a check-in -- that's the whole lifecycle.
// The only thing worth capturing after the fact is whether the
// check-in landed during curfew hours.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/curfew.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/outing.php'; // find_active_checkin_approval(), fulfill_checkin_approval()
require_once __DIR__ . '/notifications.php';

/** The open (not yet checked back in) standard-track row for this student, if any. */
function get_open_standard_outing(string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM standard_outing
         WHERE std_no = :std_no AND checked_in_at IS NULL
         ORDER BY checked_out_at DESC LIMIT 1'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Logs a standard check-out. Throws if it's currently curfew hours --
 * this is enforced here too, not just in the gate.php UI, since the
 * UI guard alone isn't a real guarantee against a stale page or a
 * direct POST.
 */
function create_standard_checkout(string $stdNo, string $guardId, string $gateLocation): int
{
    if (is_within_curfew()) {
        throw new InvalidArgumentException(
            'Curfew is active (' . curfew_window_label() . '). This student needs an approved special request to leave now.'
        );
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'INSERT INTO standard_outing (std_no, checked_out_at, checked_out_by, gate_location_out)
         VALUES (:std_no, NOW(), :guard_id, :gate_location)'
    );
    $stmt->execute([
        'std_no'        => $stdNo,
        'guard_id'      => $guardId,
        'gate_location' => $gateLocation,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Logs a standard check-in. Flags is_late_return if this moment falls
 * within curfew hours -- UNLESS the student has an approved
 * checkin-type request covering this arrival (e.g. a pre-cleared
 * midnight bus), in which case it's a planned arrival, not a
 * violation, and that request is closed out alongside this check-in.
 * A late return is never blocked, only flagged, since you don't want
 * to lock a student out at the gate.
 */
function standard_check_in(int $id, string $stdNo, string $guardId, string $gateLocation): bool
{
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $approval = find_active_checkin_approval($stdNo);
        $isLate   = is_within_curfew() && !$approval;

        $stmt = $pdo->prepare(
            'UPDATE standard_outing
             SET checked_in_at = NOW(), checked_in_by = :guard_id,
                 gate_location_in = :gate_location, is_late_return = :is_late
             WHERE id = :id AND std_no = :std_no AND checked_in_at IS NULL'
        );
        $stmt->execute([
            'guard_id'      => $guardId,
            'gate_location' => $gateLocation,
            'is_late'       => $isLate ? 1 : 0,
            'id'            => $id,
            'std_no'        => $stdNo,
        ]);
        $ok = $stmt->rowCount() > 0;

        if ($ok && $approval) {
            fulfill_checkin_approval($pdo, (int) $approval['id'], $stdNo, $guardId, $gateLocation);
        }

        if ($ok && $isLate) {
            // Student-facing violation notification -- best-effort, same
            // transaction is fine here since create_notification() does
            // its own dedupe SELECT + INSERT rather than nesting a
            // transaction of its own.
            create_notification(
                $stdNo,
                'violation',
                'A late return was recorded on your account.',
                'standard_outing',
                $id
            );
        }

        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Everyone currently out via the standard track. */
function get_standard_currently_out(): array
{
    $pdo  = get_db();
    $stmt = $pdo->query(
        'SELECT so.*, s.std_name
         FROM standard_outing so
         JOIN student s ON s.std_no = so.std_no
         WHERE so.checked_in_at IS NULL
         ORDER BY so.checked_out_at DESC'
    );
    return $stmt->fetchAll();
}

/**
 * Full standard-track history, most recent checkout first. Pass
 * $lateOnly = true to see only the flagged late returns -- this is
 * the data that had no page to view it on until now.
 */
function get_standard_history(bool $lateOnly = false, ?string $from = null, ?string $to = null): array
{
    $pdo    = get_db();
    $params = [];
    $sql = 'SELECT so.*, s.std_name, gl.group_id, gs.std_name AS group_lead_name
            FROM standard_outing so
            JOIN student s ON s.std_no = so.std_no
            LEFT JOIN outing_group_link gl ON gl.kind = "standard" AND gl.ref_id = so.id
         LEFT JOIN outing_group og ON og.id = gl.group_id
         LEFT JOIN student gs ON gs.std_no = og.lead_std_no
            WHERE 1=1';
    if ($lateOnly) {
        $sql .= ' AND so.is_late_return = 1';
    }
    $sql .= date_range_sql('so.checked_out_at', $from, $to, $params);
    $sql .= ' ORDER BY so.checked_out_at DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Count of late returns in the last N days, for a quick frequency signal. */
function count_late_returns(int $days = 7): int
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM standard_outing
         WHERE is_late_return = 1 AND checked_in_at >= NOW() - INTERVAL :days DAY'
    );
    $stmt->execute(['days' => $days]);
    return (int) $stmt->fetchColumn();
}
