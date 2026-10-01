<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/totp.php';
require_once PRIVATE_LIB . '/routing.php';
require_once PRIVATE_LIB . '/groups.php';
require_once PRIVATE_LIB . '/curfew.php';
require_once PRIVATE_LIB . '/photos.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['guard']);

$groupId = (int) ($_GET['group_id'] ?? 0);
$group   = $groupId > 0 ? get_active_group($groupId) : null;

// Only reachable right after a verified Master QR scan (gate.php).
if (!$group || !gate_is_verified('G:' . $groupId)) {
    flash_set('error', 'Scan the group\'s Master QR to open it.');
    header('Location: gate.php');
    exit;
}

$view  = get_group_gate_view($groupId);
$rows  = $view['rows'];
$toneColor = ['ok' => 'inherit', 'info' => '#2b6cb0', 'bad' => 'red'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Group gate - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Group: <?= h($group['lead_name']) ?> (<?= h($group['lead_std_no']) ?>)</h1>
        <p style="color:green;"><strong>&#10003; Live Master QR verified.</strong></p>
        <p>Curfew: <?= h(curfew_window_label()) ?> &mdash; <strong><?= is_within_curfew() ? 'Active now' : 'Not active now' ?></strong></p>
        <p><strong><?= $view['phase'] === 'return' ? 'Returning: some of this group are still out.' : 'Leaving: no one from this group is out right now.' ?></strong></p>
        <?= flash_render() ?>

        <form method="post" action="gate_action.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="group_process">
            <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">

            <table border="1" cellpadding="6" cellspacing="0" class="group-table">
                <thead><tr><th></th><th>Photo</th><th>Student</th><th>Role</th><th>What will happen</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><input type="checkbox" name="members[]" value="<?= h($r['std_no']) ?>" <?= $r['actionable'] ? 'checked' : 'disabled' ?>></td>
                        <td><?= student_photo_html($r['std_no'], $r['std_name'], 72) ?></td>
                        <td>
                            <strong><?= h($r['std_name']) ?></strong><br>
                            <?= h($r['std_no']) ?><br>
                            <span style="color:#666;"><?= h($r['route']['student']['program'] ?? '') ?></span>
                        </td>
                        <td><?= h($r['role']) ?></td>
                        <td><span style="color:<?= $toneColor[$r['tone']] ?>;"><?= h($r['note']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="submit" onclick="return confirm('Process the selected students?');">Process selected</button>
                <a href="gate.php">Cancel</a>
            </p>
        </form>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
