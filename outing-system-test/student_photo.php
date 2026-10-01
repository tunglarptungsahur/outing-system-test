<?php
// Serves a student's photo from the private folder. Staff (any role)
// can view any student; a student can only view their own.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/photos.php';

start_secure_session();
$type  = $_SESSION['user_type'] ?? null;
$stdNo = (string) ($_GET['std_no'] ?? '');

if ($type !== 'staff' && !($type === 'student' && ($_SESSION['std_no'] ?? '') === $stdNo)) {
    http_response_code(403);
    exit;
}

$file = student_photo_file($stdNo);
if ($file === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($file);
