<?php
// Minimal best-effort mailer.
//
// Uses PHP's built-in mail(), which needs a configured MTA/SMTP relay
// (see php.ini's [mail function] section) to actually deliver anything --
// on a bare XAMPP install it will typically fail silently, since there's
// no mail server running locally. Point php.ini's SMTP setting at a dev
// mail catcher (e.g. Mailtrap, Mailhog, or Papercut) while testing so you
// can actually see the emails land somewhere.
//
// Email here is a notification, not the source of truth -- the database
// update already happened by the time we try to send. So a failed send
// is logged and swallowed, never thrown, and never rolls back a review
// decision.

declare(strict_types=1);

function send_email(string $to, string $subject, string $body): bool
{
    if (trim($to) === '') {
        return false;
    }

    $fromEmail = getenv('MAIL_FROM') ?: 'no-reply@outing.local';
    $fromName  = getenv('MAIL_FROM_NAME') ?: 'Outing System';
    $headers   = "From: {$fromName} <{$fromEmail}>\r\nContent-Type: text/plain; charset=UTF-8";

    $sent = @mail($to, $subject, $body, $headers);

    if (!$sent) {
        error_log("[mailer] Failed to send to {$to} -- subject: {$subject}");
    }

    return $sent;
}

/**
 * $request must be the array returned by review_request() / 
 * get_request_with_student() -- needs student_email, std_name, status,
 * destination, reason, requested_out_at, expected_return_at, and
 * rejection_reason when rejected.
 */
function notify_request_reviewed(array $request): void
{
    $email = $request['student_email'] ?? null;
    if (!$email) {
        return; // no email on file for this student -- nothing to send
    }

    $approved = $request['status'] === 'approved';
    $subject  = $approved
        ? 'Your outing request has been approved'
        : 'Your outing request has been rejected';

    $lines = [
        "Hi {$request['std_name']},",
        '',
        $approved
            ? 'Your outing request has been approved.'
            : 'Your outing request has been rejected.',
        '',
        "Destination: {$request['destination']}",
        "Reason: {$request['reason']}",
        "Requested out: {$request['requested_out_at']}",
        "Expected return: {$request['expected_return_at']}",
    ];

    if (!$approved && !empty($request['rejection_reason'])) {
        $lines[] = "Reason for rejection: {$request['rejection_reason']}";
    }

    $lines[] = '';
    $lines[] = 'This is an automated message -- please do not reply.';

    send_email($email, $subject, implode("\n", $lines));
}

/**
 * Alerts staff that a student has passed their expected return time.
 * $request must be a row from flag_overdue_students() -- needs
 * std_name, std_no, destination, reason, expected_return_at,
 * actual_out_at. $recipients is a list of staff email addresses
 * (e.g. from get_overdue_alert_recipients()).
 */
function notify_overdue(array $request, array $recipients): void
{
    if (!$recipients) {
        return; // no admin/warden with an email on file -- nothing to send
    }

    $subject = "Overdue: {$request['std_name']} has not returned";
    $lines = [
        "Student: {$request['std_name']} ({$request['std_no']})",
        "Destination: {$request['destination']}",
        "Reason: {$request['reason']}",
        "Checked out: {$request['actual_out_at']}",
        "Expected return: {$request['expected_return_at']}",
        '',
        'This student has passed their expected return time and has not checked back in.',
    ];
    $body = implode("\n", $lines);

    foreach ($recipients as $email) {
        send_email($email, $subject, $body);
    }
}
