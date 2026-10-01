<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_student();

$requests = get_student_requests($_SESSION['std_no']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Special requests - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'student_header.php'; ?>

    <main>
        <h1>My special outing requests</h1>
        <p>
            This is only for leaving <strong>during curfew hours</strong>, or for any outing that needs
            staff approval for another reason. For a normal outing outside curfew hours, you don't need
            to request anything &mdash; just go to the gate and the guard will check you out directly.
        </p>

        <?= flash_render() ?>

        <p><a href="new_request.php">+ New special request</a></p>

        <?php if (!$requests): ?>
            <p>You haven't submitted any outing requests yet.</p>
        <?php else: ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Destination</th>
                        <th>Reason</th>
                        <th>Out at</th>
                        <th>Expected return</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?= h($r['destination']) ?></td>
                            <td><?= h($r['reason']) ?></td>
                            <td><?= h($r['requested_out_at']) ?></td>
                            <td><?= h($r['expected_return_at']) ?></td>
                            <td><?= h(status_label($r['status'])) ?></td>
                            <td>
                                <?php if ($r['status'] === 'rejected' && $r['rejection_reason']): ?>
                                    Rejected: <?= h($r['rejection_reason']) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'pending'): ?>
                                    <a href="edit_request.php?id=<?= (int) $r['id'] ?>">Edit</a>
                                    &nbsp;
                                    <form method="post" action="cancel_request.php" style="display:inline"
                                          onsubmit="return confirm('Cancel this outing request?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn-danger">Cancel</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
</body>
</html>
