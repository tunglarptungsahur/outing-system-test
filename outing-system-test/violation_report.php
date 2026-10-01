<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/reports.php';
require_once PRIVATE_LIB . '/curfew.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin', 'warden']);

$dateRange = parse_date_range();
$search    = trim((string) ($_GET['q'] ?? ''));

$allViolations = get_violations(null, $dateRange['from'], $dateRange['to']);
$rows          = search_violations($allViolations, $search);

$totalViolations = count($allViolations);
$studentCount     = count(array_unique(array_column($allViolations, 'std_no')));
$overdueNow       = count_overdue_now();

$hasFilter = $search !== '' || $dateRange['from'] !== null || $dateRange['to'] !== null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Violation report - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main>
        <h1>Violation report</h1>
        <?= export_pdf_button() ?>

        <p class="report-note">
            A violation is a <strong>late return</strong>: coming back after the agreed return time
            (special outing) or checking in during curfew hours (standard outing).
        </p>

        <form method="get" action="violation_report.php" class="date-filter">
            <label>Search
                <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or student no.">
            </label>
            <label>From <input type="date" name="date_from" value="<?= h($dateRange['from'] ?? '') ?>"></label>
            <label>To <input type="date" name="date_to" value="<?= h($dateRange['to'] ?? '') ?>"></label>
            <button type="submit">Filter</button>
            <?php if ($hasFilter): ?><a href="violation_report.php">Clear</a><?php endif; ?>
        </form>

        <?php if ($dateRange['from'] !== null || $dateRange['to'] !== null): ?>
            <p class="report-note">
                Showing violations
                <?= $dateRange['from'] !== null ? 'from ' . h($dateRange['from']) : '' ?>
                <?= $dateRange['to'] !== null ? 'to ' . h($dateRange['to']) : '' ?>.
            </p>
        <?php endif; ?>

        <div class="stat-grid">
            <div class="stat-card<?= $totalViolations > 0 ? ' stat-danger' : '' ?>">
                <p class="stat-label">Total violations</p>
                <p class="stat-value"><?= $totalViolations ?></p>
            </div>
            <div class="stat-card">
                <p class="stat-label">Students involved</p>
                <p class="stat-value"><?= $studentCount ?></p>
            </div>
            <div class="stat-card<?= $overdueNow > 0 ? ' stat-danger' : '' ?>">
                <p class="stat-label">Overdue right now</p>
                <p class="stat-value"><?= $overdueNow ?></p>
            </div>
        </div>

        <?php if (!$rows): ?>
            <p class="dash-empty"><?= $hasFilter ? 'No violations match this filter.' : 'No violations recorded yet.' ?></p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Left</th>
                        <th>Should be back</th>
                        <th>Returned</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $v): ?>
                        <tr>
                            <td><?= h($v['std_name']) ?> (<?= h($v['std_no']) ?>)</td>
                            <td><?= h(track_label($v['track'])) ?></td>
                            <td><?= $v['out_at'] !== null ? h(fmt_dt($v['out_at'])) : '<em>&mdash; (arrival permission)</em>' ?></td>
                            <td>
                                <?php if (str_starts_with($v['track'], 'special')): ?>
                                    <?= h(fmt_dt($v['due_at'])) ?>
                                <?php else: ?>
                                    Before curfew (<?= h(date('g:i A', strtotime(curfew_start()))) ?>)
                                <?php endif; ?>
                            </td>
                            <td><?= $v['back_at'] !== null ? h(fmt_dt($v['back_at'])) : '<em>Not back yet</em>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
