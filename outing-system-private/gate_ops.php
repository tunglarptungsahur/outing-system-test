<?php
// Gate operations for the SPECIAL/CURFEW track. Two independent kinds
// of permission, never one combined round trip:
//
//   checkout requests -- "I need to leave during curfew". A guard
//   checks the student OUT against an approved one. When that same
//   student later comes back to the gate, the guard closes THIS SAME
//   request out (gate_check_in()) -- there's no separate approval
//   needed to return, and no forced deadline unless the student chose
//   to give one when they asked.
//
//   checkin requests -- "I will arrive during curfew" (e.g. a bus
//   landing at midnight). These never block anything at the gate.
//   find_active_checkin_approval() / fulfill_checkin_approval() (in
//   outing.php) are what actually consume them, called from wherever
//   a check-in happens: standard_ops.php's standard_check_in() for a
//   student who left normally and is now arriving late, or directly
//   here for a student with no open record at all to attach to.
//
// There is no more guard-initiated "walk-out" override. Leaving
// during curfew with no approved checkout request is blocked at the
// gate, full stop -- see standard_ops.php's create_standard_checkout().

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/outing.php'; // log_outing_event(), find_active_checkin_approval(), fulfill_checkin_approval()
require_once __DIR__ . '/settings.php'; // special_tolerance_minutes()

function find_student_for_gate(string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT std_no, std_name, is_active, program, phone FROM student WHERE std_no = :std_no LIMIT 1'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The checkout-type request this student could check out against right now, if any. */
function get_checkoutable_request(string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request
         WHERE std_no = :std_no AND type = "checkout" AND status = "approved"
         ORDER BY requested_out_at ASC LIMIT 1'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The checkout-type request this student is currently out under, if
 *  any. Only checkout requests ever reach "checked_out" -- checkin
 *  requests never represent someone currently out, only someone
 *  expected to arrive (see find_active_checkin_approval()). */
function get_active_checkout(string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT * FROM outing_request
         WHERE std_no = :std_no AND type = "checkout" AND status = "checked_out"
         ORDER BY actual_out_at DESC LIMIT 1'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Checks out an approved checkout-type request. Returns false (not an
 * exception) if it's no longer in "approved" state -- e.g. two guards
 * at different gates processing the same student at once.
 */
function gate_check_out(int $requestId, string $stdNo, string $guardId, string $gateLocation): bool
{
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request
             SET status = "checked_out", actual_out_at = NOW()
             WHERE id = :id AND std_no = :std_no AND type = "checkout" AND status = "approved"'
        );
        $stmt->execute(['id' => $requestId, 'std_no' => $stdNo]);
        $ok = $stmt->rowCount() > 0;

        if ($ok) {
            log_outing_event($pdo, $requestId, 'checked_out', 'staff', $guardId, "Gate: {$gateLocation}");
        }
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Closes out a checkout-type request the student is currently out
 * under (checked_out or already flagged overdue on return). This is
 * the SAME request that was checked out -- not a new approval -- so
 * no separate permission is needed to come back in. Same
 * double-processing guard as gate_check_out().
 */
function gate_check_in(int $requestId, string $stdNo, string $guardId, string $gateLocation): bool
{
    $pdo = get_db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE outing_request
             SET status = "checked_in", actual_return_at = NOW()
             WHERE id = :id AND std_no = :std_no AND type = "checkout"
               AND status IN ("checked_out", "overdue")'
        );
        $stmt->execute(['id' => $requestId, 'std_no' => $stdNo]);
        $ok = $stmt->rowCount() > 0;

        if ($ok) {
            log_outing_event($pdo, $requestId, 'checked_in', 'staff', $guardId, "Gate: {$gateLocation}");
        }
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Everyone currently out on the SPECIAL track -- checkout-type
 *  requests only (checked_out or already flagged overdue on return).
 *  A checkin-type approval is someone expected to arrive, not someone
 *  currently out, so it's deliberately excluded here -- see
 *  get_pending_arrivals() for that list. Pass true to see overdue-only. */
function get_special_currently_out(bool $overdueOnly = false): array
{
    $pdo = get_db();
    $sql = 'SELECT r.*, s.std_name
            FROM outing_request r
            JOIN student s ON s.std_no = r.std_no
            WHERE r.type = "checkout" AND r.status IN ("checked_out", "overdue")';
    if ($overdueOnly) {
        $sql .= ' AND r.status = "overdue"';
    }
    $sql .= ' ORDER BY r.actual_out_at DESC';

    return $pdo->query($sql)->fetchAll();
}

/** Approved checkin-type requests -- students expected to arrive
 *  during curfew who haven't shown up at the gate yet. Soonest
 *  expected arrival first. Pass true to see overdue-only (past their
 *  expected arrival and still not checked in). */
function get_pending_arrivals(bool $overdueOnly = false): array
{
    $pdo = get_db();
    $sql = 'SELECT r.*, s.std_name
            FROM outing_request r
            JOIN student s ON s.std_no = r.std_no
            WHERE r.type = "checkin" AND r.status IN ("approved", "overdue")';
    if ($overdueOnly) {
        $sql .= ' AND r.status = "overdue"';
    }
    $sql .= ' ORDER BY r.expected_return_at ASC';

    return $pdo->query($sql)->fetchAll();
}

/**
 * Lazily flags two INDEPENDENT things past their time, each scanned
 * and updated separately so one type's rule can never leak into the
 * other's:
 *
 *   - a checkout-type request the student is still out under, PAST
 *     the return time THEY THEMSELVES gave when asking (only fires
 *     if they gave one at all -- it's optional, see outing.php)
 *   - a checkin-type approval past its expected arrival time with no
 *     check-in yet
 *
 * Safe to call on every staff page load: each WHERE guard means a
 * request is only ever flipped once. Returns full details (joined
 * with student, plus which kind) for requests newly flagged this
 * call, so the caller can send alert emails for exactly those.
 */
function flag_overdue_students(): array
{
    $pdo = get_db();
    $flaggedIds = [];
    $tolerance  = special_tolerance_minutes();

    $pdo->beginTransaction();
    try {
        // Checkout-type: still out, past their own self-declared return time
        // PLUS the grace period -- so a student isn't flagged (and alerted
        // on) before the tolerance the settings page grants has elapsed.
        $stmt = $pdo->prepare(
            'SELECT id FROM outing_request
             WHERE type = "checkout" AND status = "checked_out"
               AND expected_return_at IS NOT NULL
               AND DATE_ADD(expected_return_at, INTERVAL :tolerance_minutes MINUTE) < NOW()'
        );
        $stmt->execute(['tolerance_minutes' => $tolerance]);
        $candidateIds = array_column($stmt->fetchAll(), 'id');
        if ($candidateIds) {
            $updateStmt = $pdo->prepare(
                'UPDATE outing_request SET status = "overdue"
                 WHERE id = :id AND type = "checkout" AND status = "checked_out"'
            );
            foreach ($candidateIds as $id) {
                $updateStmt->execute(['id' => $id]);
                if ($updateStmt->rowCount() > 0) {
                    log_outing_event($pdo, (int) $id, 'overdue_flagged', 'system', null, 'Past self-declared return time (grace period included)');
                    $flaggedIds[] = (int) $id;
                }
            }
        }

        // Checkin-type: approved to arrive, past that arrival time plus the
        // grace period, not checked in.
        $stmt = $pdo->prepare(
            'SELECT id FROM outing_request
             WHERE type = "checkin" AND status = "approved"
               AND DATE_ADD(expected_return_at, INTERVAL :tolerance_minutes MINUTE) < NOW()'
        );
        $stmt->execute(['tolerance_minutes' => $tolerance]);
        $candidateIds = array_column($stmt->fetchAll(), 'id');
        if ($candidateIds) {
            $updateStmt = $pdo->prepare(
                'UPDATE outing_request SET status = "overdue"
                 WHERE id = :id AND type = "checkin" AND status = "approved"'
            );
            foreach ($candidateIds as $id) {
                $updateStmt->execute(['id' => $id]);
                if ($updateStmt->rowCount() > 0) {
                    log_outing_event($pdo, (int) $id, 'overdue_flagged', 'system', null, 'Past expected arrival time');
                    $flaggedIds[] = (int) $id;
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (!$flaggedIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($flaggedIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT r.*, s.std_name, s.email AS student_email
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         WHERE r.id IN ({$placeholders})"
    );
    $stmt->execute($flaggedIds);
    return $stmt->fetchAll();
}

/** Staff who should be emailed when a student goes overdue. */
function get_overdue_alert_recipients(): array
{
    $pdo  = get_db();
    $stmt = $pdo->query(
        'SELECT email FROM staff
         WHERE role IN ("admin", "warden") AND is_active = 1
           AND email IS NOT NULL AND email <> ""'
    );
    return array_column($stmt->fetchAll(), 'email');
}
