<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/gate_ops.php';
require_once PRIVATE_LIB . '/totp.php';
require_once PRIVATE_LIB . '/routing.php';
require_once PRIVATE_LIB . '/groups.php';
require_once PRIVATE_LIB . '/photos.php';
require_once PRIVATE_LIB . '/standard_ops.php';
require_once PRIVATE_LIB . '/curfew.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['guard']);

// A scanned QR arrives as a POST (not GET) because verifying a live
// code CONSUMES it -- a refresh or back-button must not replay it.
// Verify, remember "this scan was verified" in the guard's session,
// then redirect to a normal, refreshable GET page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['qr_token'])) {
    require_valid_csrf();
    $scan = qr_verify_token((string) $_POST['qr_token']);
    if (!$scan['ok']) {
        flash_set('error', $scan['error']);
        header('Location: gate.php');
        exit;
    }
    if ($scan['kind'] === 'G') {
        $scanGroup = get_active_group((int) $scan['id']);
        if (!$scanGroup) {
            flash_set('error', 'This group is already closed (everyone checked in) or has expired.');
            header('Location: gate.php');
            exit;
        }
        gate_mark_verified('G:' . $scanGroup['id']);
        header('Location: gate_group.php?group_id=' . (int) $scanGroup['id']);
        exit;
    }
    gate_mark_verified('S:' . $scan['id']);
    header('Location: gate.php?std_no=' . urlencode($scan['id']));
    exit;
}

$stdNo      = trim($_GET['std_no'] ?? '');
$student    = null;
$notFound   = false;
$manualOk   = manual_gate_entry_allowed();
$qrVerified = $stdNo !== '' && gate_is_verified('S:' . $stdNo);
$manualBlocked = false;

if ($stdNo !== '' && !$qrVerified && !$manualOk) {
    $manualBlocked = true;
    $stdNo = '';
}

// Track state comes from routing.php (server time decides standard vs
// special) -- same decision tree for a single student and a group.
$standardOpen     = null; // out via standard track right now
$specialActive    = null; // out via a checkout-type request right now
$specialReady     = null; // has an approved checkout-type request, not yet checked out
$arrivalApproval  = null; // has an approved checkin-type request
$curfewNow        = is_within_curfew();

if ($stdNo !== '') {
    $route = resolve_gate_route($stdNo);
    $student = $route['student'];
    if (!$student) {
        $notFound = true;
    } else {
        $arrivalApproval = $route['arrival_approval'];
        $standardOpen    = $route['standard_open'];
        $specialActive   = $route['special_active'];
        $specialReady    = $route['special_ready'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gate - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Gate check-out / check-in</h1>
        <p>Curfew: <?= h(curfew_window_label()) ?> &mdash;
           <strong><?= $curfewNow ? 'Active now' : 'Not active now' ?></strong></p>
        <?= flash_render() ?>

        <?php if ($manualBlocked): ?>
            <p style="color:red;">Manual entry is turned off. Scan the student's live QR code.</p>
        <?php endif; ?>

        <form method="get" action="gate.php">
            <?php if ($manualOk): ?>
            <label>
                Student number
                <input type="text" id="std_no" name="std_no" value="<?= h($stdNo) ?>" autofocus required>
            </label>
            <button type="submit">Look up</button>
            <?php endif; ?>
            <button type="button" id="scan-toggle">Scan QR</button>
        </form>

        <form method="post" action="gate.php" id="scan-form" style="display:none;">
            <?= csrf_field() ?>
            <input type="hidden" name="qr_token" id="qr_token" value="">
        </form>

        <div id="qr-reader" style="width: 320px; display: none; margin-top: 8px;"></div>
        <p id="qr-error" style="color: red; display: none;"></p>

        <?php if ($notFound): ?>
            <p style="color:red;">No student found with number "<?= h($stdNo) ?>".</p>

        <?php elseif ($student): ?>
            <div style="display:flex;gap:16px;align-items:flex-start;margin:12px 0;">
                <?= student_photo_html($student['std_no'], $student['std_name'], 140) ?>
                <div>
                    <h2 style="margin:0 0 6px;"><?= h($student['std_name']) ?></h2>
                    <table cellpadding="3" cellspacing="0">
                        <tr><td>Student no.</td><td><strong><?= h($student['std_no']) ?></strong></td></tr>
                        <tr><td>Program</td><td><?= h($student['program'] ?? '') !== '' ? h($student['program']) : '&mdash;' ?></td></tr>
                        <tr><td>Phone</td><td><?= h($student['phone'] ?? '') !== '' ? h($student['phone']) : '&mdash;' ?></td></tr>
                        <tr><td>Status now</td><td><strong><?= h(route_action_label($route)) ?></strong></td></tr>
                    </table>
                </div>
            </div>
            <?php if ($qrVerified): ?>
                <p style="color:green;"><strong>&#10003; Live QR verified.</strong></p>
            <?php else: ?>
                <p style="color:#b26a00;"><strong>Manual entry &mdash; not QR-verified.</strong> Check the student's ID.</p>
            <?php endif; ?>
            <?php if (!$student['is_active']): ?>
                <p style="color:red;"><strong>This student account is inactive.</strong></p>
            <?php endif; ?>

            <?php if ($arrivalApproval && !$standardOpen): ?>
                <p style="color:green;">
                    <strong>Has an approved arrival permission</strong>
                    (expected <?= h($arrivalApproval['expected_return_at']) ?>, reason: <?= h($arrivalApproval['reason']) ?>).
                    This check-in will not be flagged as late.
                </p>
            <?php endif; ?>

            <?php if ($standardOpen): ?>
                <p>Currently <strong>out (standard)</strong> since <?= h($standardOpen['checked_out_at']) ?>.</p>
                <form method="post" action="gate_action.php"
                      onsubmit="return confirm('Check in <?= h($student['std_name']) ?>?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="standard_check_in">
                    <input type="hidden" name="record_id" value="<?= (int) $standardOpen['id'] ?>">
                    <input type="hidden" name="std_no" value="<?= h($student['std_no']) ?>">
                    <button type="submit">Check in</button>
                </form>
                <?php if ($curfewNow && $arrivalApproval): ?>
                    <p><em>Pre-cleared arrival (<?= h($arrivalApproval['expected_return_at']) ?>) &mdash; checking in now will NOT be flagged.</em></p>
                <?php elseif ($curfewNow): ?>
                    <p><em>Checking in now will be flagged as a late return (curfew is active, no arrival permission on file).</em></p>
                <?php endif; ?>

            <?php elseif ($specialActive): ?>
                <p>Currently <strong>out (special &mdash; leave permission)</strong> since <?= h($specialActive['actual_out_at']) ?>,
                   destination: <?= h($specialActive['destination']) ?>
                   <?php if ($specialActive['expected_return_at']): ?>
                       , agreed back by: <?= h($specialActive['expected_return_at']) ?>
                   <?php endif; ?>
                   <?php if ($specialActive['status'] === 'overdue'): ?>
                       <strong style="color:red;">(OVERDUE)</strong>
                   <?php endif; ?>
                </p>
                <form method="post" action="gate_action.php"
                      onsubmit="return confirm('Check in <?= h($student['std_name']) ?>?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="special_check_in">
                    <input type="hidden" name="request_id" value="<?= (int) $specialActive['id'] ?>">
                    <input type="hidden" name="std_no" value="<?= h($student['std_no']) ?>">
                    <button type="submit">Check in</button>
                </form>

            <?php elseif ($specialReady): ?>
                <p>Has an <strong>approved leave permission</strong>:
                   destination: <?= h($specialReady['destination']) ?>,
                   reason: <?= h($specialReady['reason']) ?>
                   <?php if ($specialReady['expected_return_at']): ?>
                       , agreed back by: <?= h($specialReady['expected_return_at']) ?>
                   <?php endif; ?>
                </p>
                <form method="post" action="gate_action.php"
                      onsubmit="return confirm('Check out <?= h($student['std_name']) ?>?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="special_check_out">
                    <input type="hidden" name="request_id" value="<?= (int) $specialReady['id'] ?>">
                    <input type="hidden" name="std_no" value="<?= h($student['std_no']) ?>">
                    <button type="submit">Check out</button>
                </form>

            <?php elseif ($arrivalApproval): ?>
                <p>No open outing record, but has an <strong>approved arrival permission</strong>
                   (expected <?= h($arrivalApproval['expected_return_at']) ?>, reason: <?= h($arrivalApproval['reason']) ?>).</p>
                <form method="post" action="gate_action.php"
                      onsubmit="return confirm('Check in <?= h($student['std_name']) ?>?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="special_check_in_arrival">
                    <input type="hidden" name="request_id" value="<?= (int) $arrivalApproval['id'] ?>">
                    <input type="hidden" name="std_no" value="<?= h($student['std_no']) ?>">
                    <button type="submit">Check in</button>
                </form>

            <?php elseif ($curfewNow): ?>
                <p style="color:red;">
                    <strong>Curfew is active (<?= h(curfew_window_label()) ?>).</strong>
                    This student has no approved leave permission, so they cannot be checked out right now.
                    They need to submit a request and have it approved first.
                </p>

            <?php else: ?>
                <p>No approved request, not currently out. Curfew is not active, so this is a standard check-out.</p>
                <form method="post" action="gate_action.php"
                      onsubmit="return confirm('Check out <?= h($student['std_name']) ?>?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="standard_check_out">
                    <input type="hidden" name="std_no" value="<?= h($student['std_no']) ?>">
                    <button type="submit">Check out</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <p><a href="currently_out.php">View everyone currently out</a></p>
    </main>

    <?php require 'footer.php'; ?>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js"></script>
    <script>
        (function () {
            var toggleBtn = document.getElementById('scan-toggle');
            var readerDiv = document.getElementById('qr-reader');
            var errorEl = document.getElementById('qr-error');
            var scanner = null;
            var scanning = false;

            function showError(msg) {
                errorEl.textContent = msg;
                errorEl.style.display = 'block';
            }

            function stopScanner() {
                if (scanner && scanning) {
                    scanner.stop().catch(function () {});
                }
                scanning = false;
                readerDiv.style.display = 'none';
            }

            function onScanSuccess(decodedText) {
                stopScanner();
                document.getElementById('qr_token').value = decodedText.trim();
                document.getElementById('scan-form').submit();
            }

            toggleBtn.addEventListener('click', function () {
                if (scanning) {
                    stopScanner();
                    return;
                }

                if (typeof Html5Qrcode === 'undefined') {
                    showError('QR scanner library failed to load (check your internet connection).');
                    return;
                }
                if (!window.isSecureContext) {
                    showError('Camera access needs HTTPS (or localhost). This page is loaded over plain HTTP, so the browser is blocking the camera before we even get to pick one.');
                    return;
                }

                errorEl.style.display = 'none';
                readerDiv.style.display = 'block';
                scanner = new Html5Qrcode('qr-reader');

                Html5Qrcode.getCameras().then(function (cameras) {
                    if (!cameras || !cameras.length) {
                        readerDiv.style.display = 'none';
                        showError('No camera found on this device.');
                        return;
                    }

                    var chosen = cameras.find(function (c) {
                        return /back|rear|environment/i.test(c.label);
                    }) || cameras[0];

                    scanning = true;
                    scanner.start(
                        chosen.id,
                        { fps: 10, qrbox: 250 },
                        onScanSuccess,
                        function () { /* per-frame decode failures -- ignore, this fires constantly while scanning */ }
                    ).catch(function (err) {
                        scanning = false;
                        readerDiv.style.display = 'none';
                        showError('Could not start the camera: ' + err);
                    });
                }).catch(function (err) {
                    readerDiv.style.display = 'none';
                    showError('Could not access the camera list: ' + err + '. This usually means camera permission was denied -- check your browser\'s site settings.');
                });
            });
        })();
    </script>
</body>
</html>
