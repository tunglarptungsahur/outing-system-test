<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
start_secure_session();
header('Cache-Control: no-store');

// Any logged-in user (staff or student) may open this page.
$type = $_SESSION['user_type'] ?? null;
if ($type !== 'staff' && $type !== 'student') {
    header('Location: login_staff.php');
    exit;
}
enforce_session_limits($type === 'staff' ? 'login_staff.php' : 'login_student.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $error = change_own_password(
        (string)($_POST['current_password'] ?? ''),
        (string)($_POST['new_password'] ?? ''),
        (string)($_POST['confirm_password'] ?? '')
    ) ?? '';

    if ($error === '') {
        header('Location: ' . home_url_for_session());
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change password - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <div class="login-page">
        <div class="login-card">
            <img src="images/logo.png" alt="Outing System" class="login-logo">
            <h1>Change password</h1>
            <p>Set a new password before continuing.</p>

            <?php if ($error !== ''): ?>
                <p class="login-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <form method="post" action="change_password.php">
                <?= csrf_field() ?>
                <label for="current_password">Current password</label>
                <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

                <label for="new_password">New password (min <?= PASSWORD_MIN_LENGTH ?> characters)</label>
                <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" required>

                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>

                <button type="submit">Save password</button>
            </form>

            <p class="login-switch"><a href="logout.php">Log out</a></p>
        </div>
    </div>
</body>
</html>
