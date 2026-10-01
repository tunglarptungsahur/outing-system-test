<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/routing.php';   // gate_ops, standard_ops, curfew
require_once PRIVATE_LIB . '/groups.php';
require_once PRIVATE_LIB . '/reports.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_student();

$stdNo = $_SESSION['std_no'];
$name  = (string) ($_SESSION['name'] ?? '');

// --- Where am I right now? (same lookups the gate uses) ---
$standardOpen = get_open_standard_outing($stdNo);
$specialOut   = $standardOpen ? null : get_active_checkout($stdNo);
$specialReady = ($standardOpen || $specialOut) ? null : get_checkoutable_request($stdNo);
$arrival      = ($standardOpen || $specialOut) ? null : find_active_checkin_approval($stdNo);

$inCurfew    = is_within_curfew();
$curfewLabel = curfew_window_label();
$curfewStart = date('g:i A', strtotime(curfew_start()));

// --- Counts + recent activity ---
$history    = get_student_outing_history($stdNo);
$counts     = array_count_values(array_column($history, 'result'));
$total      = count($history);
$onTime     = $counts['on_time'] ?? 0;
$violations = $counts['late'] ?? 0;
$recent     = array_slice($history, 0, 5);

$requests     = get_student_requests($stdNo);
$reqCounts    = array_count_values(array_column($requests, 'status'));
$pendingReqs  = $reqCounts['pending'] ?? 0;
$approvedReqs = $reqCounts['approved'] ?? 0;

$group       = get_any_active_group_for_student($stdNo);
$invitations = get_pending_invitations($stdNo);

$badges = [
    'on_time'  => ['badge-ok',   'On time'],
    'late'     => ['badge-late', 'Late'],
    'out'      => ['badge-out',  'Still out'],
    'awaiting' => ['badge-out',  'Awaiting arrival'],
];
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
    <?php require 'student_header.php'; ?>

    <main>
        <h1>Welcome, <?= h($name) ?></h1>

        <?= flash_render() ?>

        <?php if ($inCurfew): ?>
            <div class="status-banner status-banner--warn">
                <strong>Curfew is active (<?= h($curfewLabel) ?>).</strong>
                You can only leave with an approved special request.
                <?php if (!$standardOpen && !$specialOut && !$specialReady): ?>
                    <a href="new_request.php">Request a special outing</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="status-banner">
                <strong>No curfew right now.</strong>
                For a normal outing just go to the gate and show your QR code &mdash; no request needed.
                Curfew starts at <?= h($curfewStart) ?>.
            </div>
        <?php endif; ?>

        <div class="dash-section">
            <h2>Your status</h2>
            <div class="status-card">
                <?php if ($standardOpen): ?>
                    <p class="status-card__title"><span class="badge badge-out">Out</span> Standard outing</p>
                    <p>Left at <?= h(fmt_dt($standardOpen['checked_out_at'])) ?>.
                       Check back in at the gate before curfew starts at <?= h($curfewStart) ?>.</p>
                <?php elseif ($specialOut): ?>
                    <p class="status-card__title"><span class="badge badge-out">Out</span> Special outing</p>
                    <p>Left at <?= h(fmt_dt($specialOut['actual_out_at'])) ?><?= !empty($specialOut['destination']) ? ' &middot; ' . h($specialOut['destination']) : '' ?>.
                       <?php if (!empty($specialOut['expected_return_at'])): ?>
                           Expected back by <?= h(fmt_dt($specialOut['expected_return_at'])) ?>.
                       <?php endif; ?></p>
                <?php elseif ($specialReady): ?>
                    <p class="status-card__title"><span class="badge badge-ok">Approved</span> Ready to leave</p>
                    <p>Your special request<?= !empty($specialReady['destination']) ? ' to ' . h($specialReady['destination']) : '' ?>
                       is approved. Show your QR code at the gate<?= !empty($specialReady['requested_out_at']) ? ' (requested for ' . h(fmt_dt($specialReady['requested_out_at'])) . ')' : '' ?>.</p>
                <?php elseif ($arrival): ?>
                    <p class="status-card__title"><span class="badge badge-ok">Approved</span> Arrival approved</p>
                    <p>You're approved to arrive back after curfew<?= !empty($arrival['expected_return_at']) ? ' (expected ' . h(fmt_dt($arrival['expected_return_at'])) . ')' : '' ?>.
                       Show your QR code at the gate.</p>
                <?php else: ?>
                    <p class="status-card__title"><span class="badge badge-ok">In</span> No active outing</p>
                    <p>You have no open outing right now.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="stat-grid">
            <div class="stat-card">
                <p class="stat-label">Total outings</p>
                <p class="stat-value"><?= $total ?></p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Returned on time</p>
                <p class="stat-value"><?= $onTime ?></p>
            </div>
            <div class="stat-card<?= $violations > 0 ? ' stat-danger' : '' ?>">
                <p class="stat-label">Violations</p>
                <p class="stat-value"><?= $violations ?></p>
            </div>
        </div>

        <div class="quick-actions">
            <a class="quick-action" href="my_qr.php"><strong>My QR code</strong><span>Show this at the gate</span></a>
            <a class="quick-action" href="my_group.php"><strong>Group outing</strong>
                <span><?php if ($invitations): ?><?= count($invitations) ?> invitation<?= count($invitations) > 1 ? 's' : '' ?> waiting
                      <?php elseif ($group): ?>You're in an active group
                      <?php else: ?>Go out with friends<?php endif; ?></span></a>
            <a class="quick-action" href="my_requests.php"><strong>Special requests</strong>
                <span><?php if ($pendingReqs || $approvedReqs): ?><?= $pendingReqs ?> pending, <?= $approvedReqs ?> approved
                      <?php else: ?>For leaving during curfew<?php endif; ?></span></a>
        </div>

        <div class="dash-section">
            <h2>Recent outings</h2>
            <?php if (!$recent): ?>
                <p class="dash-empty">You haven't been out yet.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr><th>Type</th><th>Left</th><th>Returned</th><th>Result</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <?php [$badgeClass, $badgeText] = $badges[$r['result']]; ?>
                            <tr<?= $r['result'] === 'late' ? ' class="row-flag"' : '' ?>>
                                <td><?= h(track_label($r['track'])) ?></td>
                                <td><?= $r['out_at'] !== null ? h(fmt_dt($r['out_at'])) : '&mdash;' ?></td>
                                <td><?= $r['back_at'] !== null ? h(fmt_dt($r['back_at'])) : '&mdash;' ?></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= h($badgeText) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><a href="my_history.php">View full history</a></p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
