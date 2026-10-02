<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

// Same audience as pending_requests.php / the special view on
// outing_history.php -- a guard never sees this workflow detail.
require_staff(['admin', 'warden']);

$validStatuses = ['pending', 'approved', 'rejected', 'cancelled'];
$statusFilter  = $_GET['status'] ?? '';
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}
$typeFilter = $_GET['type'] ?? '';
if (!in_array($typeFilter, OUTING_REQUEST_TYPES, true)) {
    $typeFilter = '';
}

$dateRange = parse_date_range();

$requests = get_all_requests(
    $statusFilter !== '' ? $statusFilter : null,
    $typeFilter !== '' ? $typeFilter : null,
    $dateRange['from'],
    $dateRange['to'],
    $validStatuses
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request status - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Request status</h1>
        <?= flash_render() ?>

        <form method="get" action="request_status.php" class="no-print">
            <label>
                Filter by type
                <select name="type" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach (OUTING_REQUEST_TYPES as $t): ?>
                        <option value="<?= h($t) ?>" <?= $typeFilter === $t ? 'selected' : '' ?>>
                            <?= h(request_type_label($t)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                Filter by status
                <select name="status" onchange="this.form.submit()">
                    <option value="">All</option>
                    <?php foreach ($validStatuses as $s): ?>
                        <option value="<?= h($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>>
                            <?= h(status_label($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>

        <?= date_filter_form('request_status.php', $dateRange['from'], $dateRange['to'], ['status' => $statusFilter, 'type' => $typeFilter], true) ?>

        <?php if (!$requests): ?>
            <p>No requests match this filter.</p>
        <?php else: ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Destination</th>
                        <th>Reason</th>
                        <th>Leaving</th>
                        <th>Arriving/back by</th>
                        <th>Status</th>
                        <th>Reviewed by</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?= h($r['std_name']) ?> (<?= h($r['std_no']) ?>)</td>
                            <td><?= h(request_type_label($r['type'])) ?></td>
                            <td><?= h($r['destination']) ?></td>
                            <td><?= h($r['reason']) ?></td>
                            <td><?= h($r['requested_out_at'] ?? '') ?></td>
                            <td><?= h($r['expected_return_at'] ?? '') ?></td>
                            <td><?= h(status_label($r['status'])) ?></td>
                            <td><?= h($r['reviewed_by'] ?? '') ?></td>
                            <td><?= h($r['rejection_reason'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
