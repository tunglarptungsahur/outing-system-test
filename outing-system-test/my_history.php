<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/reports.php';
require_once PRIVATE_LIB . '/curfew.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_student();

// Always the logged-in student's own number from the session -- never from the URL.
$history = get_student_outing_history($_SESSION['std_no']);

$resultCounts = array_count_values(array_column($history, 'result'));
$total      = count($history);
$onTime     = $resultCounts['on_time'] ?? 0;
$violations = $resultCounts['late'] ?? 0;

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
    <title>My outing history - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'student_header.php'; ?>

    <main>
        <h1>My outing history</h1>
        <p class="report-note">
            A violation is a late return: coming back after your approved return time (special outing),
            or checking in during curfew hours, <?= h(curfew_window_label()) ?> (standard outing).
        </p>

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

        <?php if (!$history): ?>
            <p class="dash-empty">You haven't been out yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Group</th>
                        <th>Left</th>
                        <th>Returned</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $r): ?>
                        <?php [$badgeClass, $badgeText] = $badges[$r['result']]; ?>
                        <tr<?= $r['result'] === 'late' ? ' class="row-flag"' : '' ?>>
                            <td><?= h(track_label($r['track'])) ?></td>
                            <td><?= $r['group_id'] ? 'Group (lead: ' . h($r['group_lead_name']) . ')' : '&mdash;' ?></td>
                            <td><?= $r['out_at'] !== null ? h(fmt_dt($r['out_at'])) : '&mdash;' ?></td>
                            <td><?= $r['back_at'] !== null ? h(fmt_dt($r['back_at'])) : '&mdash;' ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= h($badgeText) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</body>
</html>
