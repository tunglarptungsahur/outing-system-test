<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/gate_ops.php';
require_once PRIVATE_LIB . '/standard_ops.php';
require_once PRIVATE_LIB . '/mailer.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin', 'guard', 'warden']);

// Lazily flag any special-track student who's passed their expected
// return time, and alert admins/wardens for exactly the ones newly
// flagged this page load. Standard track has no expected-return time
// to compare against, so it has no equivalent here -- see gate.php's
// late-return flag instead, set at check-in.
$newlyOverdue = flag_overdue_students();
if ($newlyOverdue) {
    $recipients = get_overdue_alert_recipients();
    foreach ($newlyOverdue as $r) {
        notify_overdue($r, $recipients);
    }
}

$filter = $_GET['filter'] ?? '';

// Students expected to arrive during curfew under an approved
// checkin-type request -- not "out" (they may already be back on
// campus, just not through the gate yet), shown separately so it's
// never confused with the currently-out list.
$arrivals = [];
if ($filter === '' || $filter === 'arrivals') {
    foreach (get_pending_arrivals($filter === 'arrivals' && isset($_GET['overdue_only'])) as $r) {
        $arrivals[] = $r;
    }
}

$rows = [];
if ($filter !== 'standard' && $filter !== 'arrivals') {
    foreach (get_special_currently_out($filter === 'overdue') as $r) {
        $rows[] = [
            'track'       => 'special',
            'std_name'    => $r['std_name'],
            'std_no'      => $r['std_no'],
            'destination' => $r['destination'],
            'reason'      => $r['reason'],
            'out_since'   => $r['actual_out_at'],
            'expected'    => $r['expected_return_at'],
            'status'      => status_label($r['status']),
        ];
    }
}
if ($filter !== 'special' && $filter !== 'overdue' && $filter !== 'arrivals') {
    foreach (get_standard_currently_out() as $r) {
        $rows[] = [
            'track'       => 'standard',
            'std_name'    => $r['std_name'],
            'std_no'      => $r['std_no'],
            'destination' => '',
            'reason'      => '',
            'out_since'   => $r['checked_out_at'],
            'expected'    => '',
            'status'      => 'Out',
        ];
    }
}
usort($rows, fn($a, $b) => strcmp($b['out_since'], $a['out_since']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Currently out - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Currently out</h1>
        <?= flash_render() ?>

        <?= tab_nav([
            ''                => ['All', 'currently_out.php'],
            'standard'        => ['Standard only', 'currently_out.php?filter=standard'],
            'special'         => ['Special only', 'currently_out.php?filter=special'],
            'arrivals'        => ['Pending arrivals only', 'currently_out.php?filter=arrivals'],
        ], $filter) ?>

        <p><a href="gate.php">Go to gate</a></p>

        <?php if ($arrivals): ?>
            <h2>Expected arrivals (<?= count($arrivals) ?>)</h2>
            <p class="report-note">Approved to arrive during curfew &mdash; not counted as "out", just not through the gate yet.</p>
            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Destination</th>
                        <th>Reason</th>
                        <th>Expected</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($arrivals as $a): ?>
                        <tr<?= $a['status'] === 'overdue' ? ' class="row-flag"' : '' ?>>
                            <td><?= h($a['std_name']) ?> (<?= h($a['std_no']) ?>)</td>
                            <td><?= h($a['destination']) ?></td>
                            <td><?= h($a['reason']) ?></td>
                            <td><?= h($a['expected_return_at']) ?></td>
                            <td><?= h(status_label($a['status'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($filter !== 'arrivals'): ?>
            <h2>Currently out (<?= count($rows) ?>)</h2>
            <?php if (!$rows): ?>
                <p>No one matches this view.</p>
            <?php else: ?>
                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Track</th>
                            <th>Destination</th>
                            <th>Reason</th>
                            <th>Out since</th>
                            <th>Expected return</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr<?= $r['status'] === 'Overdue' ? ' class="row-flag"' : '' ?>>
                                <td><?= h($r['std_name']) ?> (<?= h($r['std_no']) ?>)</td>
                                <td><?= $r['track'] === 'special' ? 'Special' : 'Standard' ?></td>
                                <td><?= h($r['destination']) ?></td>
                                <td><?= h($r['reason']) ?></td>
                                <td><?= h($r['out_since']) ?></td>
                                <td><?= h($r['expected']) ?></td>
                                <td><?= h($r['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif (!$arrivals): ?>
            <p>No one matches this view.</p>
        <?php endif; ?>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
