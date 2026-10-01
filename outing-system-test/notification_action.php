<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/notifications.php';

require_student();
require_valid_csrf();

header('Content-Type: application/json');

$stdNo  = $_SESSION['std_no'];
$action = $_POST['action'] ?? '';
$id     = isset($_POST['id']) ? (int) $_POST['id'] : null;

switch ($action) {
    case 'mark_read':
        $ok = $id ? mark_notification_read($stdNo, $id) : false;
        break;
    case 'mark_all_read':
        $ok = mark_all_notifications_read($stdNo);
        break;
    case 'delete':
        $ok = $id ? delete_notification($stdNo, $id) : false;
        break;
    default:
        http_response_code(400);
        echo json_encode(['error' => 'unknown action']);
        exit;
}

echo json_encode(['success' => $ok]);
