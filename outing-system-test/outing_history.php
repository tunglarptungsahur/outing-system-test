<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/standard_ops.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin', 'guard', 'warden']);

$role = $_SESSION['role'];
// Special-track history carries the same reviewer/rejection detail as the
// approval workflow itself, so it's gated the same way pending_requests.php
// and request_history.php already are -- a guard never sees the toggle,
// let alone the tab, not just gets blocked if they guess the URL.
$canViewSpecial = in_array($role, ['admin', 'warden'], true);

$view = $_GET['view'] ?? 'standard';
if ($view !== 'special' || !$canViewSpecial) {
    $view = 'standard';
}

$dateRange = parse_date_range();

if ($view === 'standard') {
    $filter = $_GET['filter'] ?? '';
    $records = get_standard_history($filter === 'late', $dateRange['from'], $dateRange['to']);
    $lateCount7d = count_late_returns(7);
} else {
    // Executed/gate statuses only -- pending/approved/rejected/cancelled
    // (the approval-workflow statuses) live on request_status.php instead.
    $validStatuses = ['checked_out', 'checked_in', 'overdue'];
    $statusFilter  = $_GET['status'] ?? '';
    if (!in_array($statusFilter, $validStatuses, true)) {
        $statusFilter = '';
    }
    $typeFilter = $_GET['type'] ?? '';
    if (!in_array($typeFilter, OUTING_REQUEST_TYPES, true)) {
        $typeFilter = '';
    }
    $requests = get_all_requests(
        $statusFilter !== '' ? $statusFilter : null,
        $typeFilter !== '' ? $typeFilter : null,
        $dateRange['from'],
        $dateRange['to'],
        $validStatuses
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Outing history - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Outing History</h1>
        <?= flash_render() ?>
        <?= export_pdf_button() ?>

        <?php if ($canViewSpecial): ?>
        <?= tab_nav([
            'standard' => ['Standard outing history', 'outing_history.php?view=standard'],
            'special'  => ['Special outing history', 'outing_history.php?view=special'],
        ], $view) ?>
        <?php endif; ?>

        <?php if ($view === 'standard'): ?>

            <p><strong><?= $lateCount7d ?></strong> late return<?= $lateCount7d === 1 ? '' : 's' ?> in the last 7 days.</p>

            <?= tab_nav([
                ''     => ['All', 'outing_history.php?view=standard'],
                'late' => ['Late returns only', 'outing_history.php?view=standard&filter=late'],
            ], $filter) ?>

            <?= date_filter_form('outing_history.php', $dateRange['from'], $dateRange['to'], ['view' => 'standard', 'filter' => $filter]) ?>

            <?php if (!$records): ?>
                <p>No records match this filter.</p>
            <?php else: ?>
                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Group</th>
                            <th>Checked out</th>
                            <th>Checked out by</th>
                            <th>Checked in</th>
                            <th>Checked in by</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $r): ?>
                            <?php
                                $stillOut = $r['checked_in_at'] === null;
                                $statusLabel = $stillOut ? 'Out' : ($r['is_late_return'] ? 'Late return' : 'Returned on time');
                            ?>
                            <tr<?= $r['is_late_return'] ? ' class="row-flag"' : '' ?>>
                                <td><?= h($r['std_name']) ?> (<?= h($r['std_no']) ?>)</td>
                                <td><?= $r['group_id'] ? 'Group (lead: ' . h($r['group_lead_name']) . ')' : '' ?></td>
                                <td><?= h($r['checked_out_at']) ?></td>
                                <td><?= h($r['checked_out_by']) ?></td>
                                <td><?= h($r['checked_in_at'] ?? '') ?></td>
                                <td><?= h($r['checked_in_by'] ?? '') ?></td>
                                <td><?= h($statusLabel) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

        <?php else: ?>

            <form method="get" action="outing_history.php" class="no-print">
                <input type="hidden" name="view" value="special">
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

            <?= date_filter_form('outing_history.php', $dateRange['from'], $dateRange['to'], ['view' => 'special', 'status' => $statusFilter, 'type' => $typeFilter]) ?>

            <?php if (!$requests): ?>
                <p>No requests match this filter.</p>
            <?php else: ?>
                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Group</th>
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
                                <td><?= $r['group_id'] ? 'Group (lead: ' . h($r['group_lead_name']) . ')' : '' ?></td>
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

        <?php endif; ?>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
