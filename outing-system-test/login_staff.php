<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
start_secure_session();

$error = '';

if (isset($_GET['expired'])) {
    $error = 'Your session expired. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $staffId = trim((string)($_POST['staff_id'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($staffId === '' || $password === '') {
        $error = 'Please enter both staff ID and password.';
    } else {
        $result = attempt_staff_login($staffId, $password);
        if ($result === LOGIN_OK) {
            header('Location: ' . home_url_for_session());
            exit;
        }
        if ($result === LOGIN_LOCKED) {
            $error = 'Too many failed attempts. Please try again in ' . LOGIN_WINDOW_MINUTES . ' minutes.';
        } else {
            // Deliberately vague: never reveal whether the ID or the
            // password was the wrong one.
            $error = 'Invalid staff ID or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff login - Outing System</title>
    <link rel="stylesheet" href="assets/login.css">
</head>
<body>
    <div id="lg-particles"></div>

    <div class="lg-wrap">
        <div class="lg-brand">
            <img src="images/logo.png" alt="Outing System">
            <h2>Outing System</h2>
            <p>Student outing monitoring</p>
        </div>

        <div class="lg-card">
            <div class="lg-tabs">
                <a class="lg-tab is-active" href="login_staff.php">Staff</a>
                <a class="lg-tab" href="login_student.php">Student</a>
            </div>

            <div class="lg-body">
                <h1>Staff login</h1>

                <?php if ($error !== ''): ?>
                    <p class="lg-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <form method="post" action="login_staff.php">
                    <?= csrf_field() ?>
                    <label for="staff_id">Staff ID</label>
                    <input type="text" id="staff_id" name="staff_id" autocomplete="username" autofocus required>

                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>

                    <button type="submit">Sign in</button>
                </form>
            </div>

            <div class="lg-foot">&copy; <?= date('Y') ?> Outing System</div>
        </div>
    </div>

    <script src="assets/particles.min.js"></script>
    <script src="assets/login-particles.js"></script>
</body>
</html>
