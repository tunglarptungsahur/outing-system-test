<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin', 'warden']);

$canDecide = $_SESSION['role'] === 'warden';
$requests = get_pending_requests();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pending approval - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Pending special requests</h1>
        <?= flash_render() ?>

        <?php if (!$requests): ?>
            <p>No pending requests right now.</p>
        <?php else: ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Type</th>
                        <th>Destination</th>
                        <th>Reason</th>
                        <th>Time</th>
                        <th>Submitted</th>
                        <?php if ($canDecide): ?><th>Decision</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr>
                            <td><?= h($r['std_name']) ?> (<?= h($r['std_no']) ?>)</td>
                            <td><?= h(request_type_label($r['type'])) ?></td>
                            <td><?= h($r['destination']) ?></td>
                            <td><?= h($r['reason']) ?></td>
                            <td>
                                <?php if ($r['type'] === 'checkin'): ?>
                                    Arriving: <?= h($r['expected_return_at']) ?>
                                <?php else: ?>
                                    Leaving: <?= h($r['requested_out_at']) ?>
                                    <?php if ($r['expected_return_at']): ?>
                                        <br>Back by: <?= h($r['expected_return_at']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= h($r['created_at']) ?></td>
                            <?php if ($canDecide): ?>
                            <td>
                                <form method="post" action="review_request.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                                    <input type="text" name="rejection_reason" placeholder="Reason (required to reject)">
                                    <br>
                                    <button type="submit" name="decision" value="approved"
                                            onclick="return confirm('Approve this outing request?');">Approve</button>
                                    <button type="submit" name="decision" value="rejected" class="btn-danger"
                                            onclick="return confirm('Reject this outing request?');">Reject</button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
