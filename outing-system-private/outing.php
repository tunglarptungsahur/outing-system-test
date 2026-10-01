<?php
// Business logic for the outing request lifecycle (phase 2:
// student submits -> staff approves/rejects). Gate check-out/check-in
// (phase 3) is not handled here.
//
// A request now has a `type`, and the two types are NOT a round trip
// split in half -- they're two independent kinds of permission:
//
//   checkout: "I need to LEAVE during curfew" (emergency, early
//             departure). Only requested_out_at matters. Guard checks
//             the student out against this once approved. Their
//             eventual return is closed out on this SAME request
//             (see gate_ops.php::gate_check_in()), but there is no
//             deadline pressure unless the student chose to give one.
//
//   checkin:  "I will ARRIVE during curfew" (e.g. a midnight bus).
//             Only expected_return_at matters. This is not a gate --
//             nothing blocks on it -- it's a pre-clearance so the
//             arrival isn't wrongly flagged as a violation. See
//             find_active_checkin_approval() / fulfill_checkin_approval()
//             below, used by both standard_ops.php and gate_ops.php.
//
// Every write goes through a prepared statement. Multi-step writes
// (request + its audit event) are wrapped in a transaction so we never
// end up with a request row and no matching outing_event row.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/notifications.php';

const OUTING_REQUEST_TYPES = ['checkout', 'checkin'];

/**
 * Basic sanity check shared by create and edit: the one time field
 * that matters for this request's type cannot be blank or in the
 * past. Throws InvalidArgumentException with a message safe to show
 * the user.
 */
function validate_request_type_and_time(string $type, ?string $requestedOutAt, ?string $expectedReturnAt): void
{
    if (!in_array($type, OUTING_REQUEST_TYPES, true)) {
        throw new InvalidArgumentException('Invalid request type.');
    }

    if ($type === 'checkout') {
        if ($requestedOutAt === null || trim($requestedOutAt) === '') {
            throw new InvalidArgumentException('Please enter when you need to leave.');
        }
        $out = strtotime($requestedOutAt);
        if ($out === false) {
            throw new InvalidArgumentException('Please enter a valid date/time.');
        }
        if ($out < strtotime('-5 minutes')) {
            throw new InvalidArgumentException('Requested out time cannot be in the past.');
        }
        return;
    }

    // checkin
    if ($expectedReturnAt === null || trim($expectedReturnAt) === '') {
        throw new InvalidArgumentException('Please enter when you expect to arrive.');
    }
    $return = strtotime($expectedReturnAt);
    if ($return === false) {
        throw new InvalidArgumentException('Please enter a valid date/time.');
    }
    if ($return < strtotime('-5 minutes')) {
        throw new InvalidArgumentException('Expected arrival time cannot be in the past.');
    }
}

function log_outing_event(
    PDO $pdo,
    int $requestId,
    string $eventType,
    string $actorType,
    ?string $actorId,
    ?string $note = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO outing_event (request_id, event_type, actor_type, actor_id, note)
         VALUES (:request_id, :event_type, :actor_type, :actor_id, :note)'
    );
    $stmt->execute([
        'request_id' => $requestId,
        'event_type' => $eventType,
        'actor_type' => $actorType,
        'actor_id'   => $actorId,
        'note'       => $note,
    ]);
}

// --- Student actions ------------------------------------------------------

function create_outing_request(
    string $stdNo,
    string $type,
    string $reason,
    string $destination,
    ?string $requestedOutAt,
    ?string $expectedReturnAt
): int {
    validate_request_type_and_time($type, $requestedOutAt, $expectedReturnAt);

    // Only the field this type actually uses is stored -- the other
    // stays NULL rather than holding a stale/irrelevant value.
    $requestedOutAt   = $type === 'checkout' ? $requestedOutAt : null;
    $expectedReturnAt = $type === 'checkin' ? $expectedReturnAt : null;

    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO outing_request
                (std_no, type, reason, destination, requested_out_at, expected_return_at, status)
             VALUES (:std_no, :type, :reason, :destination, :requested_out_at, :expected_return_at, "pending")'
        );
        $stmt->execute([
            'std_no'             => $stdNo,
            'type'               => $type,
            'reason'             => $reason,
            'destination'        => $destination,
            'requested_out_at'   => $requestedOutAt,
            'expected_return_at' => $expectedReturnAt,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        log_outing_event($pdo, $requestId, 'submitted', 'student', $stdNo);

        create_notification(
            $stdNo,
            'request_submitted',
            'Your outing application has been submitted. CRS will review your application soon.',
            'outing_request',
            $requestId
        );

        $pdo->commit();
        return $requestId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function get_student_requests(string $stdNo): array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request WHERE std_no = :std_no ORDER BY created_at DESC'
    );
    $stmt->execute(['std_no' => $stdNo]);
    return $stmt->fetchAll();
}

/** Fetches a request only if it belongs to this student -- prevents IDOR. */
function get_request_for_student(int $requestId, string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request WHERE id = :id AND std_no = :std_no LIMIT 1'
    );
    $stmt->execute(['id' => $requestId, 'std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Returns false (not an exception) if the request is missing, not the
 *  student's own, or no longer pending -- callers turn that into a
 *  friendly flash message rather than a crash. The request's type is
 *  fixed at creation and cannot be changed by an edit. */
function update_pending_request(
    int $requestId,
    string $stdNo,
    string $type,
    string $reason,
    string $destination,
    ?string $requestedOutAt,
    ?string $expectedReturnAt
): bool {
    validate_request_type_and_time($type, $requestedOutAt, $expectedReturnAt);

    $requestedOutAt   = $type === 'checkout' ? $requestedOutAt : null;
    $expectedReturnAt = $type === 'checkin' ? $expectedReturnAt : null;

    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request
             SET reason = :reason,
                 destination = :destination,
                 requested_out_at = :requested_out_at,
                 expected_return_at = :expected_return_at
             WHERE id = :id AND std_no = :std_no AND type = :type AND status = "pending"'
        );
        $stmt->execute([
            'reason'             => $reason,
            'destination'        => $destination,
            'requested_out_at'   => $requestedOutAt,
            'expected_return_at' => $expectedReturnAt,
            'id'                 => $requestId,
            'std_no'             => $stdNo,
            'type'               => $type,
        ]);
        $updated = $stmt->rowCount() > 0;

        if ($updated) {
            log_outing_event($pdo, $requestId, 'submitted', 'student', $stdNo, 'Edited by student');
        }
        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function cancel_request_by_student(int $requestId, string $stdNo): bool
{
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request SET status = "cancelled"
             WHERE id = :id AND std_no = :std_no AND status = "pending"'
        );
        $stmt->execute(['id' => $requestId, 'std_no' => $stdNo]);
        $cancelled = $stmt->rowCount() > 0;

        if ($cancelled) {
            log_outing_event($pdo, $requestId, 'cancelled', 'student', $stdNo);
        }
        $pdo->commit();
        return $cancelled;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// --- Staff actions ----------------------------------------------------

function get_pending_requests(): array
{
    $pdo  = get_db();
    $stmt = $pdo->query(
        'SELECT r.*, s.std_name, s.email AS student_email
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         WHERE r.status = "pending"
         ORDER BY r.created_at ASC'
    );
    return $stmt->fetchAll();
}

/**
 * $statusIn optionally restricts the result to a fixed set of statuses
 * regardless of $statusFilter -- used to split the single request table
 * into two pages (outing_history.php's "special" view for the
 * executed/gate statuses, request_status.php for the approval-workflow
 * statuses) without either page being able to see the other's rows.
 */
function get_all_requests(?string $statusFilter = null, ?string $typeFilter = null, ?string $from = null, ?string $to = null, ?array $statusIn = null): array
{
    $pdo    = get_db();
    $params = [];
    $where  = ' WHERE 1=1';

    if ($statusIn !== null && $statusIn !== []) {
        $placeholders = [];
        foreach (array_values($statusIn) as $i => $s) {
            $key = "status_in_{$i}";
            $placeholders[] = ":{$key}";
            $params[$key] = $s;
        }
        $where .= ' AND r.status IN (' . implode(',', $placeholders) . ')';
    }
    if ($statusFilter !== null && $statusFilter !== '') {
        $where .= ' AND r.status = :status';
        $params['status'] = $statusFilter;
    }
    if ($typeFilter !== null && $typeFilter !== '') {
        $where .= ' AND r.type = :type';
        $params['type'] = $typeFilter;
    }
    $where .= date_range_sql('r.created_at', $from, $to, $params);

    $stmt = $pdo->prepare(
        'SELECT r.*, s.std_name, s.email AS student_email, gl.group_id, gs.std_name AS group_lead_name
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         LEFT JOIN outing_group_link gl ON gl.kind = "special" AND gl.ref_id = r.id
         LEFT JOIN outing_group og ON og.id = gl.group_id
         LEFT JOIN student gs ON gs.std_no = og.lead_std_no' . $where . '
         ORDER BY r.created_at DESC'
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function get_request_with_student(int $requestId): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT r.*, s.std_name, s.email AS student_email
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         WHERE r.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $requestId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Approves or rejects a pending request. Returns the updated request
 * (joined with student info, for the email notification) on success,
 * or null if there was nothing to review (already decided, or the id
 * doesn't exist) -- this covers two staff double-clicking Approve on
 * the same request at once, since the WHERE status = "pending" guard
 * makes the second UPDATE affect zero rows.
 */
function review_request(int $requestId, string $staffId, string $decision, ?string $rejectionReason): ?array
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid decision.');
    }
    if ($decision === 'rejected' && trim((string) $rejectionReason) === '') {
        throw new InvalidArgumentException('A rejection reason is required.');
    }

    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request
             SET status = :status,
                 reviewed_by = :reviewed_by,
                 reviewed_at = NOW(),
                 rejection_reason = :rejection_reason
             WHERE id = :id AND status = "pending"'
        );
        $stmt->execute([
            'status'           => $decision,
            'reviewed_by'      => $staffId,
            'rejection_reason' => $decision === 'rejected' ? $rejectionReason : null,
            'id'               => $requestId,
        ]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return null;
        }

        log_outing_event($pdo, $requestId, $decision, 'staff', $staffId, $rejectionReason);

        $updated = get_request_with_student($requestId);
        create_notification(
            $updated['std_no'],
            $decision === 'approved' ? 'request_approved' : 'request_rejected',
            $decision === 'approved'
                ? 'Your outing request has been approved.'
                : 'Your outing request has been rejected.',
            'outing_request',
            $requestId
        );

        $pdo->commit();
        return $updated;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// --- Check-in ("arriving during curfew") approvals -------------------
//
// These two functions are the actual fix for the overdue confusion:
// a checkin-type approval is looked up at the moment ANY check-in
// happens (standard track in standard_ops.php, or a stand-alone
// arrival with no open record at all in gate_action.php) so a planned
// late arrival is never wrongly flagged as a violation.

/**
 * The approved checkin-type request this student could be arriving
 * under right now, if any. Soonest expected arrival first -- if a
 * student somehow has more than one approved, the one that's most
 * relevant to "right now" wins.
 */
function find_active_checkin_approval(string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request
         WHERE std_no = :std_no AND type = "checkin" AND status IN ("approved", "overdue")
         ORDER BY expected_return_at ASC LIMIT 1'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Marks a checkin-type approval as fulfilled -- the student showed up.
 * Safe to call even if the request was already flagged "overdue" by
 * the scheduled check (still a valid state to arrive from). Returns
 * false (not an exception) if it's no longer in a fulfillable state,
 * e.g. two guards processing the same student at once.
 *
 * No transaction of its own -- PDO doesn't support nesting one, and
 * this is meant to be called from inside another write's transaction
 * (e.g. standard_ops.php's standard_check_in(), which is closing out
 * a standard_outing row and this approval together). Call
 * fulfill_checkin_approval_standalone() instead for a bare call with
 * nothing else to wrap it (e.g. an arrival with no open record at all
 * to attach to).
 */
function fulfill_checkin_approval(PDO $pdo, int $requestId, string $stdNo, string $guardId, string $gateLocation): bool
{
    $stmt = $pdo->prepare(
        'UPDATE outing_request
         SET status = "checked_in", actual_return_at = NOW()
         WHERE id = :id AND std_no = :std_no AND type = "checkin"
           AND status IN ("approved", "overdue")'
    );
    $stmt->execute(['id' => $requestId, 'std_no' => $stdNo]);
    $ok = $stmt->rowCount() > 0;

    if ($ok) {
        log_outing_event($pdo, $requestId, 'checked_in', 'staff', $guardId, "Gate: {$gateLocation}");
    }
    return $ok;
}

/** Standalone version for a bare call with no surrounding transaction. */
function fulfill_checkin_approval_standalone(int $requestId, string $stdNo, string $guardId, string $gateLocation): bool
{
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $ok = fulfill_checkin_approval($pdo, $requestId, $stdNo, $guardId, $gateLocation);
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
