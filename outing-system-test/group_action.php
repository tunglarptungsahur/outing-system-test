<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/groups.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_student();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_group.php');
    exit;
}
require_valid_csrf();

$stdNo   = $_SESSION['std_no'];
$action  = $_POST['action'] ?? '';
$groupId = (int) ($_POST['group_id'] ?? 0);

try {
    switch ($action) {
        case 'create':
            create_group($stdNo);
            flash_set('success', 'Group created. Invite up to ' . GROUP_MAX_MEMBERS . ' students.');
            break;
        case 'invite':
            invite_group_member($groupId, $stdNo, (string) ($_POST['member_std_no'] ?? ''));
            flash_set('success', 'Invitation sent.');
            break;
        case 'remove':
            flash_set(remove_group_member($groupId, $stdNo, trim((string) ($_POST['member_std_no'] ?? ''))) ? 'success' : 'error',
                      'Member removed.');
            break;
        case 'close':
            flash_set(close_group($groupId, $stdNo) ? 'success' : 'error', 'Group closed.');
            break;
        case 'accept':
        case 'decline':
            $ok = respond_to_invitation((int) ($_POST['member_row_id'] ?? 0), $stdNo, $action === 'accept');
            flash_set($ok ? 'success' : 'error', $ok ? ($action === 'accept' ? 'You joined the group.' : 'Invitation declined.') : 'That invitation is no longer available.');
            break;
        case 'leave':
            flash_set(leave_group($groupId, $stdNo) ? 'success' : 'error', 'You left the group.');
            break;
        default:
            flash_set('error', 'Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    flash_set('error', $e->getMessage());
}

header('Location: my_group.php');
exit;
