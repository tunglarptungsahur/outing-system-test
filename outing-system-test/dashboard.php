<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/gate_ops.php';
require_once PRIVATE_LIB . '/dashboard_ops.php';
require_once PRIVATE_LIB . '/reports.php';
require_once PRIVATE_LIB . '/mailer.php';
require_once PRIVATE_LIB . '/curfew.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff();

$role = $_SESSION['role'];
$canApprove = in_array($role, ['admin', 'warden'], true);
$canGate = in_array($role, ['admin', 'guard'], true);
$isAdmin = $role === 'admin';

// Same lazy overdue-flagging currently_out.php does, so the stat cards
// and due-back list are accurate even if nobody's opened that page yet.
$newlyOverdue = flag_overdue_students();
if ($newlyOverdue) {
    $recipients = get_overdue_alert_recipients();
    foreach ($newlyOverdue as $r) {
        notify_overdue($r, $recipients);
    }
}

$counts = get_dashboard_counts();

$allPending = $canApprove ? get_pending_requests() : [];
$pendingCount = count($allPending);
$pendingPreview = array_slice($allPending, 0, 5);

$dueBackToday = get_due_back_today(8);

$violations = $canApprove ? array_slice(get_violation_summary(), 0, 5) : [];
$recentActivity = $canGate ? get_recent_gate_activity(8) : [];
$staffSummary = $isAdmin ? get_staff_summary() : null;

$curfewNow = is_within_curfew();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Dashboard</h1>
        <p style="padding: 8px 12px; background: <?= $curfewNow ? '#fdecea' : '#eef6ec' ?>; border-radius: 6px; display: inline-block;">
           Curfew: <?= h(curfew_window_label()) ?> &mdash;
           <strong><?= $curfewNow ? 'Active now' : 'Not active now' ?></strong></p>
        <?= flash_render() ?>

        <?php if ($isAdmin): ?>
        <div class="dash-section">
            <h2>System</h2>
            <div class="stat-grid">
                <div class="stat-card">
                    <p class="stat-label">Staff accounts</p>
                    <p class="stat-value"><?= $staffSummary['total'] ?></p>
                </div>
                <div class="stat-card">
                    <p class="stat-label">Active</p>
                    <p class="stat-value"><?= $staffSummary['active'] ?></p>
                </div>
                <?php if ($staffSummary['inactive'] > 0): ?>
                <div class="stat-card stat-danger">
                    <p class="stat-label">Inactive</p>
                    <p class="stat-value"><?= $staffSummary['inactive'] ?></p>
                </div>
                <?php endif; ?>
            </div>
            <p>
                <?php foreach ($staffSummary['by_role'] as $r => $c): ?>
                    <?= h(ucfirst($r)) ?>: <strong><?= $c ?></strong>&nbsp;&nbsp;
                <?php endforeach; ?>
            </p>
            <p><a href="manage_staff.php">Manage staff accounts</a></p>
        </div>
        <?php endif; ?>

        <div class="stat-grid">
            <?php if ($canApprove): ?>
                <div class="stat-card<?= $pendingCount > 0 ? ' stat-danger' : '' ?>">
                    <p class="stat-label">Pending approval</p>
                    <p class="stat-value"><?= $pendingCount ?></p>
                </div>
            <?php endif; ?>
            <div class="stat-card">
                <p class="stat-label">Out (special)</p>
                <p class="stat-value"><?= $counts['special_out'] ?></p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Out (standard)</p>
                <p class="stat-value"><?= $counts['standard_out'] ?></p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Due back today</p>
                <p class="stat-value"><?= $counts['due_today'] ?></p>
            </div>
        </div>

        <?php if ($canApprove): ?>
        <div class="dash-section">
            <h2>Pending approvals</h2>
            <?php if (!$pendingPreview): ?>
                <p class="dash-empty">No pending requests right now.</p>
            <?php else: ?>
                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Destination</th>
                            <th>Expected return</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingPreview as $r): ?>
                            <tr>
                                <td><?= h($r['std_name']) ?> (<?= h($r['std_no']) ?>)</td>
                                <td><?= h($r['destination']) ?></td>
                                <td><?= h($r['expected_return_at']) ?></td>
                                <td><?= h($r['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a href="pending_requests.php">Review all<?= $pendingCount > 5 ? " ({$pendingCount})" : '' ?></a></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="dash-section">
            <h2>Due back today (special track)</h2>
            <?php if (!$dueBackToday): ?>
                <p class="dash-empty">No one expected back today.</p>
            <?php else: ?>
                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Type</th>
                            <th>Destination</th>
                            <th>Expected return</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dueBackToday as $r): ?>
                            <tr<?= $r['status'] === 'overdue' ? ' class="row-flag"' : '' ?>>
                                <td><?= h($r['std_name']) ?> (<?= h($r['std_no']) ?>)</td>
                                <td><?= h(request_type_label($r['type'])) ?></td>
                                <td><?= h($r['destination']) ?></td>
                                <td><?= h($r['expected_return_at']) ?></td>
                                <td><?= h(status_label($r['status'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a href="currently_out.php">View everyone currently out</a></p>
            <?php endif; ?>
        </div>

        <?php if ($canGate): ?>
        <div class="dash-section">
            <h2>Recent gate activity</h2>
            <?php if (!$recentActivity): ?>
                <p class="dash-empty">No check-ins or check-outs yet.</p>
            <?php else: ?>
                <table border="1" cellpadding="6" cellspacing="0">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Action</th>
                            <th>Track</th>
                            <th>By</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentActivity as $a): ?>
                            <tr>
                                <td><?= h($a['std_name']) ?> (<?= h($a['std_no']) ?>)</td>
                                <td><?= $a['action'] === 'checked_out' ? 'Checked out' : 'Checked in' ?></td>
                                <td><?= $a['track'] === 'special' ? 'Special' : 'Standard' ?></td>
                                <td><?= h($a['staff_id'] ?? '') ?></td>
                                <td><?= h($a['activity_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($canApprove): ?>
        <div class="dash-section">
            <h2>Most violations</h2>
            <?php if (!$violations): ?>
                <p class="dash-empty">No violations recorded yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Violations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($violations as $r): ?>
                            <tr>
                                <td><a href="student_violations.php?std_no=<?= urlencode($r['std_no']) ?>"><?= h($r['std_name']) ?></a> (<?= h($r['std_no']) ?>)</td>
                                <td><strong><?= (int) $r['violations'] ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a href="violation_report.php">View full violation report</a></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
