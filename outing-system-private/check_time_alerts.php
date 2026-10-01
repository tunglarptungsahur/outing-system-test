<?php
// Run this on its own schedule, every 1-2 minutes -- separate from
// check_overdue.php's 5-10 minute job, since these alerts need to land
// close to the 60/30/10-minute mark to be useful. check_overdue.php's
// slower interval is fine for after-the-fact violation flagging; this
// one exists purely for the reminders.
//
// Windows Task Scheduler setup (same pattern as check_overdue.php):
//   Program/script:  C:\xampp\php\php.exe
//   Arguments:        C:\xampp\outing-system-private\check_time_alerts.php
//   Trigger:          Repeat every 1 or 2 minutes, indefinitely
//   "Start in":       C:\xampp\outing-system-private

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/curfew.php';
require_once __DIR__ . '/notifications.php';

$timestamp = date('Y-m-d H:i:s');
$pdo = get_db();
$sent = 0;

try {
    // ---------- STANDARD TRACK: 60 / 30 / 10 min before curfew START ----------
    // A standard-track check-in during curfew hours is what gets flagged
    // is_late_return (see standard_ops.php), so the deadline that matters
    // to these students is curfew_start(), not curfew_end().
    $curfewStart = curfew_start(); // "HH:MM:SS"
    $thresholds = [60 => 'time_alert_60', 30 => 'time_alert_30', 10 => 'time_alert_10'];

    $stillOut = $pdo->query(
        'SELECT id, std_no FROM standard_outing WHERE checked_in_at IS NULL'
    )->fetchAll();

    foreach ($stillOut as $row) {
        $curfewToday = new DateTime(date('Y-m-d') . ' ' . $curfewStart);
        $now = new DateTime();
        $minutesLeft = ($curfewToday->getTimestamp() - $now->getTimestamp()) / 60;

        foreach ($thresholds as $mins => $type) {
            // Catch window matches the job interval (2 min) so a threshold
            // is never skipped between runs, and never re-sent once passed.
            if ($minutesLeft <= $mins && $minutesLeft > ($mins - 2)) {
                $ok = create_notification(
                    $row['std_no'],
                    $type,
                    "Reminder: curfew (" . curfew_window_label() . ") starts in {$mins} minutes. Please check in soon.",
                    'standard_outing',
                    (int) $row['id']
                );
                if ($ok) $sent++;
            }
        }
    }

    // ---------- SPECIAL TRACK: single "due back in 1 hour" reminder ----------
    // Only checkout-type requests currently out, with a self-declared
    // expected_return_at (it's optional -- see outing.php) -- same
    // population flag_overdue_students() watches for the after-the-fact
    // violation, just caught earlier here.
    $checkedOut = $pdo->query(
        "SELECT id, std_no, expected_return_at FROM outing_request
         WHERE type = 'checkout' AND status = 'checked_out'
           AND expected_return_at IS NOT NULL"
    )->fetchAll();

    foreach ($checkedOut as $row) {
        $expected = new DateTime($row['expected_return_at']);
        $now = new DateTime();
        $minutesLeft = ($expected->getTimestamp() - $now->getTimestamp()) / 60;

        if ($minutesLeft <= 60 && $minutesLeft > 58) {
            $ok = create_notification(
                $row['std_no'],
                'time_alert_due',
                "You're due back in 1 hour.",
                'outing_request',
                (int) $row['id']
            );
            if ($ok) $sent++;
        }
    }

    echo "[{$timestamp}] Sent {$sent} time-alert notification(s).\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[{$timestamp}] check_time_alerts.php failed: {$e->getMessage()}\n");
    exit(1);
}
