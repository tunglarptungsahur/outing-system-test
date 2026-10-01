<?php
// Aggregated read-only queries for the staff dashboard landing page.
// Nothing here writes to the database -- flag_overdue_students() (in
// gate_ops.php) is still what actually flips a request to "overdue";
// this file only counts/lists current state for display.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

/**
 * Live counts for the dashboard stat cards. Cheap enough to run on
 * every dashboard load -- each query hits an indexed column and
 * none of them join.
 */
function get_dashboard_counts(): array
{
    $pdo = get_db();

    $specialOut = (int) $pdo->query(
        'SELECT COUNT(*) FROM outing_request WHERE status IN ("checked_out", "overdue")'
    )->fetchColumn();

    $standardOut = (int) $pdo->query(
        'SELECT COUNT(*) FROM standard_outing WHERE checked_in_at IS NULL'
    )->fetchColumn();

    $dueToday = (int) $pdo->query(
        'SELECT COUNT(*) FROM outing_request
         WHERE (
             (type = "checkout" AND status IN ("checked_out", "overdue"))
             OR (type = "checkin" AND status IN ("approved", "overdue"))
         )
           AND DATE(expected_return_at) = CURDATE()'
    )->fetchColumn();

    $overdueNow = (int) $pdo->query(
        'SELECT COUNT(*) FROM outing_request WHERE status = "overdue"'
    )->fetchColumn();

    return [
        'out_now'      => $specialOut + $standardOut,
        'special_out'  => $specialOut,
        'standard_out' => $standardOut,
        'due_today'    => $dueToday,
        'overdue_now'  => $overdueNow,
    ];
}

/**
 * Special-track students expected back today: either already checked
 * out (or overdue) under a leaving-type request, OR approved (or
 * overdue) to arrive today under an arriving-type request -- these
 * never show as "checked_out" since they never left through this
 * system, so both cases have to be queried explicitly rather than one
 * status list covering both. Soonest expected return first, so
 * staff/guards can see who to expect without opening the full
 * "currently out" page. Standard track has no expected-return time,
 * so it has no equivalent here (same reasoning as currently_out.php).
 */
function get_due_back_today(int $limit = 10): array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT r.*, s.std_name
         FROM outing_request r
         JOIN student s ON s.std_no = r.std_no
         WHERE (
             (r.type = "checkout" AND r.status IN ("checked_out", "overdue"))
             OR (r.type = "checkin" AND r.status IN ("approved", "overdue"))
         )
           AND DATE(r.expected_return_at) = CURDATE()
         ORDER BY r.expected_return_at ASC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Staff account counts for the admin-only dashboard section: total,
 * active/inactive, and a role breakdown (active accounts only, since
 * an inactive admin/warden/guard isn't meaningfully "on the team").
 */
function get_staff_summary(): array
{
    $pdo = get_db();

    $total  = (int) $pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn();
    $active = (int) $pdo->query('SELECT COUNT(*) FROM staff WHERE is_active = 1')->fetchColumn();

    $byRole = [];
    $stmt = $pdo->query('SELECT role, COUNT(*) AS c FROM staff WHERE is_active = 1 GROUP BY role');
    foreach ($stmt->fetchAll() as $row) {
        $byRole[$row['role']] = (int) $row['c'];
    }

    return [
        'total'    => $total,
        'active'   => $active,
        'inactive' => $total - $active,
        'by_role'  => $byRole,
    ];
}

/**
 * Most recent check-out/check-in actions across both tracks, merged
 * and sorted by when they happened -- a guard's "what just happened
 * at the gate" feed. Special-track actions come from outing_event
 * (the audit trail); standard-track actions are read straight off
 * standard_outing since it has no separate event log (a check-out and
 * its later check-in are two rows in this result, not one).
 */
function get_recent_gate_activity(int $limit = 10): array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT activity_at, action, std_name, std_no, track, staff_id, gate_location FROM (
            SELECT e.event_at AS activity_at, e.event_type AS action,
                   s.std_name, s.std_no, "special" AS track,
                   e.actor_id AS staff_id, e.gate_location
            FROM outing_event e
            JOIN outing_request r ON r.id = e.request_id
            JOIN student s ON s.std_no = r.std_no
            WHERE e.event_type IN ("checked_out", "checked_in")

            UNION ALL

            SELECT so.checked_out_at AS activity_at, "checked_out" AS action,
                   s.std_name, s.std_no, "standard" AS track,
                   so.checked_out_by AS staff_id, so.gate_location_out AS gate_location
            FROM standard_outing so
            JOIN student s ON s.std_no = so.std_no

            UNION ALL

            SELECT so.checked_in_at AS activity_at, "checked_in" AS action,
                   s.std_name, s.std_no, "standard" AS track,
                   so.checked_in_by AS staff_id, so.gate_location_in AS gate_location
            FROM standard_outing so
            JOIN student s ON s.std_no = so.std_no
            WHERE so.checked_in_at IS NOT NULL
        ) activity
        ORDER BY activity_at DESC
        LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
