<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_requests.php');
    exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
$cancelled = $id > 0 && cancel_request_by_student($id, $_SESSION['std_no']);

flash_set(
    $cancelled ? 'success' : 'error',
    $cancelled ? 'Outing request cancelled.' : 'That request could not be cancelled (it may have already been reviewed).'
);

header('Location: my_requests.php');
exit;
