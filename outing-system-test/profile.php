<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/profile.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

start_secure_session();
$userType = $_SESSION['user_type'] ?? null;
if (!in_array($userType, ['student', 'staff'], true)) {
    header('Location: login_student.php');
    exit;
}

if ($userType === 'student') {
    $profile = get_student_profile($_SESSION['std_no']);
} else {
    $profile = get_staff_profile($_SESSION['staff_id']);
}

if (!$profile) {
    header('Location: logout.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My profile - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php if ($userType === 'staff'): ?>
        <?php require 'menuheader.php'; ?>
        <?php require 'mainmenu.php'; ?>
    <?php else: ?>
        <?php require 'student_header.php'; ?>
    <?php endif; ?>

    <main style="padding: 16px; max-width: 480px;">
        <h1>My profile</h1>
        <?= flash_render() ?>
        <p style="color: #666;">This information comes from the university database and can't be edited here.
           Contact your administrator if anything needs correcting.</p>

        <?php if ($userType === 'student'): ?>
            <p>
                <strong>Student number:</strong> <?= h($profile['std_no']) ?><br>
                <strong>Name:</strong> <?= h($profile['std_name']) ?><br>
                <strong>IC number:</strong> <?= h($profile['ic_no']) ?><br>
                <strong>Program:</strong> <?= h($profile['program'] ?? '-') ?><br>
                <strong>Email:</strong> <?= h($profile['email'] ?? '-') ?><br>
                <strong>Phone:</strong> <?= h($profile['phone'] ?? '-') ?><br>
                <strong>Emergency contact:</strong> <?= h($profile['emergency_contact_name'] ?? '-') ?>
                <?php if (!empty($profile['emergency_contact_phone'])): ?>
                    (<?= h($profile['emergency_contact_phone']) ?>)
                <?php endif; ?>
            </p>

        <?php else: ?>
            <p>
                <strong>Staff ID:</strong> <?= h($profile['staff_id']) ?><br>
                <strong>Name:</strong> <?= h($profile['name']) ?><br>
                <strong>Role:</strong> <?= h(ucfirst($profile['role'])) ?><br>
                <strong>Email:</strong> <?= h($profile['email'] ?? '-') ?>
            </p>
        <?php endif; ?>

        <h2>Change password</h2>
        <form method="post" action="change_password.php">
            <?= csrf_field() ?>
            <label>Current password<br><input type="password" name="current_password" autocomplete="current-password" required style="width: 100%;"></label><br><br>
            <label>New password (min. 8 characters)<br><input type="password" name="new_password" autocomplete="new-password" required style="width: 100%;"></label><br><br>
            <label>Confirm new password<br><input type="password" name="confirm_password" autocomplete="new-password" required style="width: 100%;"></label><br><br>
            <button type="submit">Change password</button>
        </form>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
