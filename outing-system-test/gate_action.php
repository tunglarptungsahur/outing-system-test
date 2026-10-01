<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/gate_ops.php';
require_once PRIVATE_LIB . '/standard_ops.php';
require_once PRIVATE_LIB . '/totp.php';
require_once PRIVATE_LIB . '/groups.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['guard']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gate.php');
    exit;
}

require_valid_csrf();

$action       = $_POST['action'] ?? '';
$stdNo        = trim($_POST['std_no'] ?? '');
$gateLocation = 'Main Gate'; // the campus has a single gate; kept only because existing records store it
$guardId      = $_SESSION['staff_id'];

// Group batch: needs a verified Master QR scan (no manual path).
if ($action === 'group_process') {
    $groupId = (int) ($_POST['group_id'] ?? 0);
    $group   = $groupId > 0 ? get_active_group($groupId) : null;
    if (!$group || !gate_is_verified('G:' . $groupId)) {
        flash_set('error', 'Group scan expired or group closed. Scan the Master QR again.');
        header('Location: gate.php');
        exit;
    }
    $selected = array_map('strval', (array) ($_POST['members'] ?? []));
    if (!$selected) {
        flash_set('error', 'No one was selected.');
        header('Location: gate_group.php?group_id=' . $groupId);
        exit;
    }
    $res = process_group_batch($group, $selected, $guardId, $gateLocation);
    gate_clear_verified('G:' . $groupId);
    $msg = count($res['done']) . ' processed';
    if ($res['skipped']) {
        $msg .= ', ' . count($res['skipped']) . ' skipped -- ' . implode('; ', $res['skipped']);
    }
    if ($res['closed']) {
        $msg .= '. Everyone is back -- group closed';
    }
    flash_set($res['skipped'] ? 'error' : 'success', $msg . '.');
    header('Location: gate.php');
    exit;
}

// Single-student actions: if manual entry is off, this student's live
// QR must have been verified in this session.
if (!manual_gate_entry_allowed() && !gate_is_verified('S:' . $stdNo)) {
    flash_set('error', 'Manual entry is turned off. Scan the student\'s live QR code first.');
    header('Location: gate.php');
    exit;
}

try {
    switch ($action) {
        case 'standard_check_out':
            create_standard_checkout($stdNo, $guardId, $gateLocation);
            group_record_movement($stdNo, 'out');
            flash_set('success', 'Student checked out (standard).');
            break;

        case 'standard_check_in':
            $recordId = (int) ($_POST['record_id'] ?? 0);
            $ok = $recordId > 0 && standard_check_in($recordId, $stdNo, $guardId, $gateLocation);
            if ($ok) { group_record_movement($stdNo, 'in'); }
            flash_set(
                $ok ? 'success' : 'error',
                $ok ? 'Student checked in (standard).' : 'Could not check in -- they may have already been checked in.'
            );
            break;

        case 'special_check_out':
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $ok = $requestId > 0 && gate_check_out($requestId, $stdNo, $guardId, $gateLocation);
            if ($ok) { group_record_movement($stdNo, 'out'); }
            flash_set(
                $ok ? 'success' : 'error',
                $ok ? 'Student checked out (special).' : 'Could not check out -- the request may no longer be approved.'
            );
            break;

        case 'special_check_in':
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $ok = $requestId > 0 && gate_check_in($requestId, $stdNo, $guardId, $gateLocation);
            if ($ok) { group_record_movement($stdNo, 'in'); }
            flash_set(
                $ok ? 'success' : 'error',
                $ok ? 'Student checked in (special).' : 'Could not check in -- the student may no longer be checked out.'
            );
            break;

        case 'special_check_in_arrival':
            // Closes an approved checkin-type (arrival permission) request
            // directly -- for a student with no open standard/special
            // record to attach to (e.g. they arrived from off-campus).
            $requestId = (int) ($_POST['request_id'] ?? 0);
            $ok = $requestId > 0 && fulfill_checkin_approval_standalone($requestId, $stdNo, $guardId, $gateLocation);
            if ($ok) { group_record_movement($stdNo, 'in'); }
            flash_set(
                $ok ? 'success' : 'error',
                $ok ? 'Student checked in (pre-cleared arrival).' : 'Could not check in -- the approval may no longer be active.'
            );
            break;

        default:
            flash_set('error', 'Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    // Covers create_standard_checkout()'s curfew guard -- a stale page
    // or a direct POST attempting to bypass the block shown in gate.php.
    flash_set('error', $e->getMessage());
}

gate_clear_verified('S:' . $stdNo);

// Always return to a blank gate.php -- no std_no carried over. A busy
// gate needs to be ready for the next student immediately after any
// action, not sit showing "checked out, waiting for them to come back"
// for a student who might not be back for hours. The flash message
// still confirms what just happened.
header('Location: gate.php');
exit;
