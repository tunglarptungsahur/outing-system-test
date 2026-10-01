<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/profile.php';
require_once PRIVATE_LIB . '/mailer.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin']);

$generatedPassword = null;
$passwordForStaffId = null;
$createError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'create') {
        try {
            $staffId = trim($_POST['staff_id'] ?? '');
            $generatedPassword = create_staff_account(
                $staffId,
                trim($_POST['name'] ?? ''),
                $_POST['role'] ?? '',
                trim($_POST['email'] ?? '')
            );
            $passwordForStaffId = $staffId;
            flash_set('success', "Account {$staffId} created.");
        } catch (InvalidArgumentException $e) {
            $createError = $e->getMessage();
        }

    } elseif ($formAction === 'reset_password') {
        $staffId = trim($_POST['staff_id'] ?? '');
        try {
            $generatedPassword = admin_reset_staff_password($staffId);
            $passwordForStaffId = $staffId;

            $staff = array_values(array_filter(get_all_staff(), static fn($s) => $s['staff_id'] === $staffId))[0] ?? null;
            if ($staff && !empty($staff['email'])) {
                send_email(
                    $staff['email'],
                    'Your Outing System password has been reset',
                    "Hi {$staff['name']},\n\nAn administrator has reset your password.\n\nTemporary password: {$generatedPassword}\n\nPlease log in and change it from your profile page as soon as possible."
                );
            }
        } catch (InvalidArgumentException $e) {
            flash_set('error', $e->getMessage());
        }

    } elseif ($formAction === 'toggle_active') {
        $staffId = trim($_POST['staff_id'] ?? '');
        try {
            $nowActive = toggle_staff_active($staffId, $_SESSION['staff_id']);
            flash_set('success', "{$staffId} is now " . ($nowActive ? 'active' : 'inactive') . '.');
        } catch (InvalidArgumentException $e) {
            flash_set('error', $e->getMessage());
        }
    }
}

$staffList = get_all_staff();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage staff - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Manage staff accounts</h1>
        <?= flash_render() ?>

        <?php if ($generatedPassword !== null): ?>
            <div style="border: 2px solid #333; padding: 12px; margin-bottom: 16px;">
                <p><strong>Temporary password for <?= h($passwordForStaffId) ?>:</strong>
                   <code style="font-size: 1.2em;"><?= h($generatedPassword) ?></code></p>
                <p>This is shown once and not stored anywhere in plain text. If they have an email on file, it was
                   also sent to them. They should change it from their profile page after logging in.</p>
            </div>
        <?php endif; ?>

        <h2>Add a new staff account</h2>
        <?php if ($createError): ?><p style="color: red;"><?= h($createError) ?></p><?php endif; ?>
        <form method="post" action="manage_staff.php" style="margin-bottom: 24px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="create">
            <label>Staff ID<br><input type="text" name="staff_id" required></label><br><br>
            <label>Name<br><input type="text" name="name" required></label><br><br>
            <label>Role<br>
                <select name="role" required>
                    <option value="guard">Guard</option>
                    <option value="warden">Warden</option>
                    <option value="admin">Admin</option>
                </select>
            </label><br><br>
            <label>Email (optional)<br><input type="email" name="email"></label><br><br>
            <button type="submit">Create account</button>
        </form>

        <h2>Existing staff</h2>
        <table border="1" cellpadding="6" cellspacing="0">
            <tr><th>Staff ID</th><th>Name</th><th>Role</th><th>Email</th><th>Status</th><th colspan="2">Actions</th></tr>
            <?php foreach ($staffList as $s): ?>
                <tr>
                    <td><?= h($s['staff_id']) ?></td>
                    <td><?= h($s['name']) ?></td>
                    <td><?= h(ucfirst($s['role'])) ?></td>
                    <td><?= h($s['email'] ?? '-') ?></td>
                    <td><?= $s['is_active'] ? 'Active' : 'Inactive' ?></td>
                    <td>
                        <form method="post" action="manage_staff.php" onsubmit="return confirm('Reset password for <?= h($s['staff_id']) ?>? Their current password will stop working immediately.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="form_action" value="reset_password">
                            <input type="hidden" name="staff_id" value="<?= h($s['staff_id']) ?>">
                            <button type="submit" class="btn-danger">Reset password</button>
                        </form>
                    </td>
                    <td>
                        <?php if ($s['staff_id'] !== $_SESSION['staff_id']): ?>
                            <form method="post" action="manage_staff.php" onsubmit="return confirm('<?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= h($s['staff_id']) ?>?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="form_action" value="toggle_active">
                                <input type="hidden" name="staff_id" value="<?= h($s['staff_id']) ?>">
                                <button type="submit" class="<?= $s['is_active'] ? 'btn-danger' : '' ?>">
                                    <?= $s['is_active'] ? 'Deactivate' : 'Reactivate' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span style="color: #999;">(you)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
