<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/notifications.php';

require_student();

header('Content-Type: application/json');
echo json_encode(['count' => get_unread_count($_SESSION['std_no'])]);
