<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/reports.php';
require_once PRIVATE_LIB . '/gate_ops.php';
require_once PRIVATE_LIB . '/curfew.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin', 'warden']);

$stdNo = trim((string) ($_GET['std_no'] ?? ''));
$student = $stdNo !== '' ? find_student_for_gate($stdNo) : null;
if (!$student) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}

$dateRange  = parse_date_range();
$violations = get_violations($student['std_no'], $dateRange['from'], $dateRange['to']);

$backQuery = http_build_query(array_filter([
    'date_from' => $dateRange['from'],
    'date_to'   => $dateRange['to'],
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Violations - <?= h($student['std_name']) ?> - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main>
        <p class="no-print"><a href="violation_report.php<?= $backQuery !== '' ? '?' . h($backQuery) : '' ?>">&larr; Back to violation report</a></p>
        <h1><?= h($student['std_name']) ?> (<?= h($student['std_no']) ?>)</h1>
        <?= export_pdf_button() ?>

        <div class="stat-grid">
            <div class="stat-card<?= $violations ? ' stat-danger' : '' ?>">
                <p class="stat-label">Violations<?= ($dateRange['from'] || $dateRange['to']) ? ' (in range)' : '' ?></p>
                <p class="stat-value"><?= count($violations) ?></p>
            </div>
        </div>

        <?php if (!$violations): ?>
            <p class="dash-empty">No violations recorded.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Left</th>
                        <th>Should be back</th>
                        <th>Returned</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($violations as $v): ?>
                        <tr>
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
