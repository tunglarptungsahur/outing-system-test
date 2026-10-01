<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/ui_helpers.php';
require_once PRIVATE_LIB . '/routing.php';

require_student();

$stdNo   = $_SESSION['std_no'];
$stdName = $_SESSION['name'] ?? '';
$track   = current_track(); // server time decides
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My QR code - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'student_header.php'; ?>

    <main>
        <p><a href="student_dashboard.php">&larr; Back</a></p>
        <h1>My QR code</h1>
        <p>Show this live code at the gate &mdash; the guard scans it instead of typing your number.
           It changes automatically, so a screenshot or printout will not work.</p>

        <?php if ($track === 'standard'): ?>
            <p class="report-note"><strong>Standard mode</strong> (no curfew right now): the guard can check you out directly.</p>
        <?php else: ?>
            <p class="report-note"><strong>Curfew mode</strong> (<?= h(curfew_window_label()) ?>): you can only leave with an approved
               <a href="my_requests.php">special request</a>.</p>
        <?php endif; ?>

        <div id="qr-card" class="qr-card">
            <div id="qrcode" style="user-select:none;-webkit-user-select:none;"></div>
            <p class="qr-card__name"><?= h($stdName) ?></p>
            <p class="qr-card__id"><?= h($stdNo) ?></p>
            <div class="qr-timer"><div id="qr-bar" class="qr-timer__bar"></div></div>
            <p id="qr-countdown" class="report-note" style="text-align:center;margin:6px 0 0;">Loading&hellip;</p>
        </div>
        <p id="qr-error" class="form-error" style="display:none;"></p>
        <p><a href="my_group.php">Going out as a group? Use a group QR &rarr;</a></p>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script>
        (function () {
            var errorEl = document.getElementById('qr-error');
            var bar = document.getElementById('qr-bar');
            var countdown = document.getElementById('qr-countdown');
            var qr = null, timer = null, tick = null;

            function showError(msg) { errorEl.textContent = msg; errorEl.style.display = 'block'; }

            if (typeof QRCode === 'undefined') {
                showError('QR library failed to load (check your internet connection).');
                return;
            }

            function render(token) {
                if (!qr) {
                    qr = new QRCode(document.getElementById('qrcode'), {
                        text: token, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M
                    });
                } else {
                    qr.makeCode(token);
                }
            }

            function load() {
                clearTimeout(timer); clearInterval(tick);
                fetch('qr_token.php?kind=S', { cache: 'no-store', credentials: 'same-origin' })
                    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(function (d) {
                        errorEl.style.display = 'none';
                        render(d.token);
                        var end = Date.now() + d.expires_in * 1000;
                        tick = setInterval(function () {
                            var left = Math.max(0, (end - Date.now()) / 1000);
                            bar.style.width = (left / d.period * 100) + '%';
                            countdown.textContent = 'New code in ' + Math.ceil(left) + 's';
                        }, 250);
                        // Refresh a moment early so the code on screen is never stale at scan time.
                        timer = setTimeout(load, Math.max(1000, d.expires_in * 1000 - 800));
                    })
                    .catch(function () {
                        showError('Could not refresh your QR code. Check your connection and log in again if needed.');
                        timer = setTimeout(load, 5000);
                    });
            }
            load();
            document.addEventListener('visibilitychange', function () { if (!document.hidden) load(); });
        })();
    </script>
</body>
</html>
