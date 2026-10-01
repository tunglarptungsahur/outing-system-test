<?php
// Run this on a schedule (Windows Task Scheduler, every 5-10 minutes)
// instead of relying on flag_overdue_students() only firing when a
// staff member happens to open currently_out.php. That page still
// calls it too (harmless, and keeps things flagged even if the
// scheduled task is ever misconfigured) -- this script exists so
// detection doesn't *depend* on someone browsing the site.
//
// Windows Task Scheduler setup:
//   Program/script:  C:\xampp\php\php.exe
//   Arguments:        C:\xampp\outing-system-private\check_overdue.php
//   Trigger:          Repeat every 5 or 10 minutes, indefinitely
//   "Start in":       C:\xampp\outing-system-private
//
// Redirect output to a log file if you want a record, e.g. by wrapping
// the above in a .bat file:
//   C:\xampp\php\php.exe C:\xampp\outing-system-private\check_overdue.php >> C:\xampp\outing-system-private\overdue_log.txt 2>&1

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gate_ops.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/notifications.php';

$timestamp = date('Y-m-d H:i:s');

try {
    $newlyOverdue = flag_overdue_students();

    if (!$newlyOverdue) {
        echo "[{$timestamp}] No new overdue students.\n";
        exit(0);
    }

    $recipients = get_overdue_alert_recipients();
    foreach ($newlyOverdue as $request) {
        notify_overdue($request, $recipients);
        // Student-facing violation notification -- same event, own
        // channel. Best-effort like the email above: never blocks or
        // rolls back the overdue flag itself.
        create_notification(
            $request['std_no'],
            'violation',
            'A late return was recorded on your account.',
            'outing_request',
            (int) $request['id']
        );
    }

    $names = implode(', ', array_map(
        static fn(array $r): string => "{$r['std_name']} ({$r['std_no']})",
        $newlyOverdue
    ));
    echo "[{$timestamp}] Flagged " . count($newlyOverdue) . " newly overdue student(s): {$names}\n";
    exit(0);
} catch (Throwable $e) {
    // Exit non-zero so Task Scheduler's history shows a failure, but
    // don't let a crash here take down anything else -- this script
    // has no callers, it's only ever invoked standalone.
    fwrite(STDERR, "[{$timestamp}] check_overdue.php failed: {$e->getMessage()}\n");
    exit(1);
}
