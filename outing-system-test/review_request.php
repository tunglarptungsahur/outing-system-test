<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/mailer.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['warden']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pending_requests.php');
    exit;
}

require_valid_csrf();

$requestId = (int) ($_POST['request_id'] ?? 0);
$decision  = $_POST['decision'] ?? '';
$reason    = trim($_POST['rejection_reason'] ?? '');

try {
    $updated = $requestId > 0
        ? review_request($requestId, $_SESSION['staff_id'], $decision, $reason !== '' ? $reason : null)
        : null;

    if ($updated) {
        // Best-effort: a failed email must never undo the decision above.
        notify_request_reviewed($updated);
        flash_set('success', 'Request ' . $updated['status'] . '.');
    } else {
        flash_set('error', 'This request has already been reviewed by someone else, or no longer exists.');
    }
} catch (InvalidArgumentException $e) {
    flash_set('error', $e->getMessage());
}

header('Location: pending_requests.php');
exit;
