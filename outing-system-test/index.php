<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
start_secure_session();

// Students are the largest user group, so the student login is the
// default landing page. Anyone already signed in skips straight past
// it to where they actually work.
$userType = $_SESSION['user_type'] ?? null;

if ($userType === 'student') {
    header('Location: student_dashboard.php');
    exit;
}
if ($userType === 'staff') {
    header('Location: ' . ($_SESSION['role'] === 'guard' ? 'gate.php' : 'dashboard.php'));
    exit;
}

header('Location: login_student.php');
exit;
