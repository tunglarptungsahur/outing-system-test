<?php
// Shared auth helpers used by every protected page.
// Hashed passwords, prepared statements, session role checks, CSRF tokens,
// plus: login throttling, idle/absolute session expiry, per-request account
// re-check, and forced password change.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

// --- Tunables -------------------------------------------------------------
const LOGIN_MAX_FAILS_PER_ACCOUNT = 5;    // failed attempts per ID within the window
const LOGIN_MAX_FAILS_PER_IP      = 20;   // failed attempts per IP within the window
const LOGIN_WINDOW_MINUTES        = 15;   // lockout window
const SESSION_IDLE_SECONDS        = 1800; // 30 min idle (staff/students)
const SESSION_IDLE_SECONDS_GUARD  = 3600; // 60 min idle (gate PC)
const SESSION_MAX_SECONDS         = 28800; // 8 h hard cap since login
const PASSWORD_MIN_LENGTH         = 8;
const PASSWORD_MAX_LENGTH         = 72;   // bcrypt ignores anything past 72 bytes

const LOGIN_OK      = 'ok';
const LOGIN_INVALID = 'invalid';
const LOGIN_LOCKED  = 'locked';

// Pages a user with must_change_password = 1 may still open.
const FORCE_CHANGE_EXEMPT = ['change_password.php', 'logout.php'];

// Used to keep response time equal when the ID does not exist.
const DUMMY_PASSWORD_HASH = '$2y$10$Uevc9hWZ4qIpzwSDpAylgupJJAmG5HShssB5HU6GbRMCHVI6lLRKW';

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params([
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax', // Lax so links in notification emails keep the session
            'secure'   => $https,
        ]);
        session_start();
    }
}

// --- Login throttling -----------------------------------------------------

function client_ip(): string
{
    // REMOTE_ADDR only: X-Forwarded-For is client-controlled and spoofable.
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function normalise_login_key(string $id): string
{
    return strtolower(substr(trim($id), 0, 64));
}

function login_is_throttled(PDO $pdo, string $type, string $key, string $ip): bool
{
    $window = (int)LOGIN_WINDOW_MINUTES;

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_failure
         WHERE account_type = :type AND identifier = :id
           AND attempted_at > DATE_SUB(NOW(), INTERVAL {$window} MINUTE)"
    );
    $stmt->execute(['type' => $type, 'id' => $key]);
    if ((int)$stmt->fetchColumn() >= LOGIN_MAX_FAILS_PER_ACCOUNT) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_failure
         WHERE ip = :ip
           AND attempted_at > DATE_SUB(NOW(), INTERVAL {$window} MINUTE)"
    );
    $stmt->execute(['ip' => $ip]);
    return (int)$stmt->fetchColumn() >= LOGIN_MAX_FAILS_PER_IP;
}

function record_login_failure(PDO $pdo, string $type, string $key, string $ip): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_failure (account_type, identifier, ip) VALUES (:type, :id, :ip)'
    );
    $stmt->execute(['type' => $type, 'id' => $key, 'ip' => $ip]);

    // Housekeeping: occasionally drop rows older than a day.
    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM login_failure WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

function clear_login_failures(PDO $pdo, string $type, string $key): void
{
    $stmt = $pdo->prepare('DELETE FROM login_failure WHERE account_type = :type AND identifier = :id');
    $stmt->execute(['type' => $type, 'id' => $key]);
}

// --- Login ------------------------------------------------------------

/**
 * Verify staff credentials and start a session on success.
 * Returns LOGIN_OK, LOGIN_INVALID or LOGIN_LOCKED.
 */
function attempt_staff_login(string $staffId, string $password): string
{
    start_secure_session();
    $pdo = get_db();
    $key = normalise_login_key($staffId);
    $ip  = client_ip();

    if (login_is_throttled($pdo, 'staff', $key, $ip)) {
        return LOGIN_LOCKED;
    }

    $stmt = $pdo->prepare(
        'SELECT staff_id, name, email, role, password_hash, is_active
         FROM staff WHERE staff_id = :staff_id LIMIT 1'
    );
    $stmt->execute(['staff_id' => $staffId]);
    $staff = $stmt->fetch();

    // Always run password_verify so unknown IDs cost the same time as known ones.
    $passwordOk = password_verify($password, $staff ? (string)$staff['password_hash'] : DUMMY_PASSWORD_HASH);

    if (!$staff || !$passwordOk || !$staff['is_active']) {
        record_login_failure($pdo, 'staff', $key, $ip);
        return LOGIN_INVALID;
    }

    if (password_needs_rehash((string)$staff['password_hash'], PASSWORD_DEFAULT)) {
        $upd = $pdo->prepare('UPDATE staff SET password_hash = :h WHERE staff_id = :id');
        $upd->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $staff['staff_id']]);
    }

    clear_login_failures($pdo, 'staff', $key);

    session_regenerate_id(true); // prevent session fixation
    $_SESSION = [];
    $_SESSION['user_type']     = 'staff';
    $_SESSION['staff_id']      = $staff['staff_id'];
    $_SESSION['name']          = $staff['name'];
    $_SESSION['email']         = $staff['email'];
    $_SESSION['role']          = $staff['role']; // 'admin' | 'warden' | 'guard'
    $_SESSION['login_time']    = time();
    $_SESSION['last_activity'] = time();

    return LOGIN_OK;
}

function attempt_student_login(string $stdNo, string $password): string
{
    start_secure_session();
    $pdo = get_db();
    $key = normalise_login_key($stdNo);
    $ip  = client_ip();

    if (login_is_throttled($pdo, 'student', $key, $ip)) {
        return LOGIN_LOCKED;
    }

    $stmt = $pdo->prepare(
        'SELECT std_no, std_name, email, password_hash, is_active
         FROM student WHERE std_no = :std_no LIMIT 1'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $student = $stmt->fetch();

    $passwordOk = password_verify($password, $student ? (string)$student['password_hash'] : DUMMY_PASSWORD_HASH);

    if (!$student || !$passwordOk || !$student['is_active']) {
        record_login_failure($pdo, 'student', $key, $ip);
        return LOGIN_INVALID;
    }

    if (password_needs_rehash((string)$student['password_hash'], PASSWORD_DEFAULT)) {
        $upd = $pdo->prepare('UPDATE student SET password_hash = :h WHERE std_no = :id');
        $upd->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $student['std_no']]);
    }

    clear_login_failures($pdo, 'student', $key);

    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_type']     = 'student';
    $_SESSION['std_no']        = $student['std_no'];
    $_SESSION['name']          = $student['std_name'];
    $_SESSION['email']         = $student['email'];
    $_SESSION['login_time']    = time();
    $_SESSION['last_activity'] = time();

    return LOGIN_OK;
}

function logout(): void
{
    start_secure_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function home_url_for_session(): string
{
    if (($_SESSION['user_type'] ?? '') === 'student') {
        return 'student_dashboard.php';
    }
    return ($_SESSION['role'] ?? '') === 'guard' ? 'gate.php' : 'dashboard.php';
}

// --- Session limits / account re-check -------------------------------------

/**
 * Idle timeout + absolute lifetime. Define('SKIP_ACTIVITY_TOUCH', true) before
 * requiring auth.php in background polling endpoints (e.g. the 45s notification
 * poll) so they do not keep an idle session alive.
 */
function enforce_session_limits(string $loginPage): void
{
    $now     = time();
    $idleMax = (($_SESSION['role'] ?? '') === 'guard') ? SESSION_IDLE_SECONDS_GUARD : SESSION_IDLE_SECONDS;
    $last    = (int)($_SESSION['last_activity'] ?? 0);
    $started = (int)($_SESSION['login_time'] ?? 0);

    if ($last === 0 || $started === 0 || ($now - $last) > $idleMax || ($now - $started) > SESSION_MAX_SECONDS) {
        logout();
        header('Location: ' . $loginPage . '?expired=1');
        exit;
    }
    if (!defined('SKIP_ACTIVITY_TOUCH')) {
        $_SESSION['last_activity'] = $now;
    }
}

function redirect_if_password_change_due(bool $due): void
{
    if (!$due) {
        return;
    }
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, FORCE_CHANGE_EXEMPT, true)) {
        return;
    }
    header('Location: change_password.php');
    exit;
}

// --- Access control -----------------------------------------------------

/**
 * Call at the top of any staff-only page. Optionally restrict to specific
 * roles, e.g. require_staff(['warden', 'admin']) keeps guards out.
 */
function require_staff(array $allowedRoles = []): void
{
    start_secure_session();
    header('Cache-Control: no-store');

    if (($_SESSION['user_type'] ?? null) !== 'staff') {
        header('Location: login_staff.php');
        exit;
    }
    enforce_session_limits('login_staff.php');

    // Re-check the account on every request: deactivation and role changes apply immediately.
    $stmt = get_db()->prepare(
        'SELECT role, is_active, must_change_password FROM staff WHERE staff_id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $_SESSION['staff_id'] ?? '']);
    $row = $stmt->fetch();
    if (!$row || !$row['is_active']) {
        logout();
        header('Location: login_staff.php');
        exit;
    }
    $_SESSION['role'] = $row['role'];

    redirect_if_password_change_due((bool)$row['must_change_password']);

    if ($allowedRoles && !in_array($_SESSION['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo 'You do not have access to this page.';
        exit;
    }
}

function require_student(): void
{
    start_secure_session();
    header('Cache-Control: no-store');

    if (($_SESSION['user_type'] ?? null) !== 'student') {
        header('Location: login_student.php');
        exit;
    }
    enforce_session_limits('login_student.php');

    $stmt = get_db()->prepare(
        'SELECT is_active, must_change_password FROM student WHERE std_no = :id LIMIT 1'
    );
    $stmt->execute(['id' => $_SESSION['std_no'] ?? '']);
    $row = $stmt->fetch();
    if (!$row || !$row['is_active']) {
        logout();
        header('Location: login_student.php');
        exit;
    }

    redirect_if_password_change_due((bool)$row['must_change_password']);
}

// --- Password change ---------------------------------------------------------

/**
 * Change the logged-in user's own password (staff or student).
 * Returns an error message, or null on success (also clears must_change_password).
 */
function change_own_password(string $current, string $new, string $confirm): ?string
{
    start_secure_session();
    $type = $_SESSION['user_type'] ?? null;
    if ($type === 'staff') {
        $select = 'SELECT password_hash FROM staff WHERE staff_id = :id LIMIT 1';
        $update = 'UPDATE staff SET password_hash = :h, must_change_password = 0 WHERE staff_id = :id';
        $id     = $_SESSION['staff_id'] ?? '';
    } elseif ($type === 'student') {
        $select = 'SELECT password_hash FROM student WHERE std_no = :id LIMIT 1';
        $update = 'UPDATE student SET password_hash = :h, must_change_password = 0 WHERE std_no = :id';
        $id     = $_SESSION['std_no'] ?? '';
    } else {
        return 'You are not logged in.';
    }

    if ($new !== $confirm) {
        return 'New password and confirmation do not match.';
    }
    if (strlen($new) < PASSWORD_MIN_LENGTH) {
        return 'New password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (strlen($new) > PASSWORD_MAX_LENGTH) {
        return 'New password must be at most ' . PASSWORD_MAX_LENGTH . ' characters.';
    }
    if ($new === $current) {
        return 'New password must be different from the current one.';
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare($select);
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, (string)$row['password_hash'])) {
        return 'Current password is incorrect.';
    }

    $upd = $pdo->prepare($update);
    $upd->execute(['h' => password_hash($new, PASSWORD_DEFAULT), 'id' => $id]);

    session_regenerate_id(true);
    return null;
}

// --- CSRF -----------------------------------------------------------------

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Echo this inside every <form> that changes data. */
function csrf_field(): string
{
    $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    return "<input type=\"hidden\" name=\"csrf_token\" value=\"{$token}\">";
}

/** Call at the top of every POST handler before touching the database. */
function require_valid_csrf(): void
{
    start_secure_session();
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted)) {
        http_response_code(403);
        echo 'Invalid or expired form submission. Please go back and try again.';
        exit;
    }
}
