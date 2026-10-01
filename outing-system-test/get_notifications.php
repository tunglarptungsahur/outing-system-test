<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/notifications.php';
require_once PRIVATE_LIB . '/ui_helpers.php'; // fmt_dt()

require_student();

$items = get_notifications($_SESSION['std_no'], 20);
foreach ($items as &$item) {
    $item['created_at_label'] = fmt_dt($item['created_at']);
}
unset($item);

header('Content-Type: application/json');
echo json_encode($items);
