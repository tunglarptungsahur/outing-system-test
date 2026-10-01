<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/settings.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $errors = [];
    foreach (array_keys(SETTING_META) as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        try {
            set_setting($key, $value, $_SESSION['staff_id']);
        } catch (InvalidArgumentException $e) {
            $errors[] = SETTING_META[$key]['label'] . ': ' . $e->getMessage();
        }
    }

    if ($errors) {
        flash_set('error', implode(' ', $errors));
    } else {
        flash_set('success', 'Settings updated.');
    }
    header('Location: manage_settings.php');
    exit;
}

$settings = get_all_settings();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System settings - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>System settings</h1>
        <?= flash_render() ?>

        <p class="report-note">
            Changes take effect immediately for every page -- there's no separate "publish" step.
        </p>

        <form method="post" action="manage_settings.php">
            <?= csrf_field() ?>
            <table border="1" cellpadding="6" cellspacing="0">
                <tbody>
                    <?php foreach ($settings as $key => $s): ?>
                        <tr>
                            <td>
                                <label for="setting_<?= h($key) ?>"><strong><?= h($s['label']) ?></strong></label>
                                <br><small><?= h($s['help']) ?></small>
                            </td>
                            <td>
                                <?php if ($s['type'] === 'bool'): ?>
                                    <select id="setting_<?= h($key) ?>" name="<?= h($key) ?>">
                                        <option value="1" <?= $s['value'] !== '0' ? 'selected' : '' ?>>Yes</option>
                                        <option value="0" <?= $s['value'] === '0' ? 'selected' : '' ?>>No</option>
                                    </select>
                                <?php else: ?>
                                <input
                                    type="<?= h($s['type']) ?>"
                                    id="setting_<?= h($key) ?>"
                                    name="<?= h($key) ?>"
                                    value="<?= h($s['type'] === 'time' ? substr($s['value'], 0, 5) : $s['value']) ?>"
                                    <?= $s['type'] === 'number' ? 'min="' . (int) ($s['min'] ?? 0) . '" step="1"' : '' ?>
                                    <?= isset($s['max']) ? 'max="' . (int) $s['max'] . '"' : '' ?>
                                    required
                                >
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="submit">Save settings</button></p>
        </form>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
