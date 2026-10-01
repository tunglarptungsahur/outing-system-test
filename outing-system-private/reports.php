<?php
// Violation reporting for BOTH tracks, with one definition of "violation".
//
// A violation is a late return:
//   - special/checkout: the student, who was cleared to LEAVE during curfew,
//                     came back after the return time THEY THEMSELVES gave when
//                     asking -- and only if they gave one; a checkout request
//                     with no self-declared return time never produces a
//                     violation, since none was ever agreed to
//   - special/checkin:  the student, who was cleared to ARRIVE during curfew,
//                     showed up after the approved arrival time (or was
//                     flagged overdue and never showed at all)
//   - standard track: the student checked in during curfew hours with NO
//                     matching checkin approval (standard_outing.is_late_return,
//                     set once at check-in -- see standard_ops.php)
//
// Everything that shows a violation number -- the staff report, the per-student
// detail page, the dashboard and the student's own history -- reads from the
// functions in this file, so the numbers cannot disagree with each other.
//
// These rows come from history (outing_request timestamps, the outing_event
// audit trail, and the immutable is_late_return flag), never from the current
// outing_request.status, so a student who has since checked back in still counts.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/settings.php'; // special_tolerance_minutes()

/**
 * SQL condition: this outing_request row (aliased r) counts as a late
 * return. Type-aware -- a checkout request only compares against
 * expected_return_at if one was actually given (it's optional); a
 * checkin request always has one (it's the whole point of the row).
 * The overdue_flagged event check covers both types identically: it's
 * a historical fact about that one row regardless of why it fired.
 *
 * The grace period is an admin-editable setting (special_tolerance_minutes()
 * in settings.php), not a fixed number, so it's bound rather than baked
 * into the string. It's compared TWICE (checkout branch, checkin branch) --
 * real prepared statements (this app disables emulation, see db.php)
 * reject the same named placeholder appearing twice in one statement, so
 * this uses two distinct names. Every caller MUST merge
 * special_late_params() into the $params array of the statement that
 * uses this fragment.
 */
function special_late_sql(): string
{
    return '((r.type = "checkout" AND r.expected_return_at IS NOT NULL
       AND r.actual_return_at > DATE_ADD(r.expected_return_at, INTERVAL :tolerance_minutes_1 MINUTE))
      OR (r.type = "checkin" AND r.actual_return_at > DATE_ADD(r.expected_return_at, INTERVAL :tolerance_minutes_2 MINUTE))
      OR EXISTS (SELECT 1 FROM outing_event ev
                 WHERE ev.request_id = r.id AND ev.event_type = "overdue_flagged"))';
}

/** Bind values for the two placeholders special_late_sql() uses -- both the same tolerance. */
function special_late_params(): array
{
    $tolerance = special_tolerance_minutes();
    return ['tolerance_minutes_1' => $tolerance, 'tolerance_minutes_2' => $tolerance];
}

/**
 * Every violation on both tracks, newest first. Optionally limited to one
 * student and/or a date range.
 *
 * The date range applies to when the violation happened: the agreed return
 * time for a special/checkout outing, the approved arrival time for a
 * special/checkin one, the check-in time for a standard one.
 *
 * Row keys: std_no, std_name, track ('special_checkout'|'special_checkin'|'standard'),
 *           violation_at, out_at (null for checkin -- no departure is tracked
 *           on that type), due_at, back_at (null = not back/arrived yet)
 */
function get_violations(?string $stdNo = null, ?string $from = null, ?string $to = null): array
{
    $pdo = get_db();

    // Special track -- both types share one query since special_late_sql()
    // already branches on r.type; only the "did this actually happen"
    // gate differs (checkout needs an actual departure, checkin needs
    // an approval that reached at least "approved").
    $params = special_late_params();
    $sql = 'SELECT r.std_no, s.std_name,
                   CASE WHEN r.type = "checkout" THEN "special_checkout" ELSE "special_checkin" END AS track,
                   COALESCE(r.actual_return_at, r.expected_return_at) AS violation_at,
                   r.actual_out_at AS out_at,
                   r.expected_return_at AS due_at,
                   r.actual_return_at AS back_at
            FROM outing_request r
            JOIN student s ON s.std_no = r.std_no
            WHERE ((r.type = "checkout" AND r.actual_out_at IS NOT NULL)
                   OR (r.type = "checkin" AND r.status IN ("approved", "checked_in", "overdue")))
              AND ' . special_late_sql();
    if ($stdNo !== null) {
        $sql .= ' AND r.std_no = :std_no';
        $params['std_no'] = $stdNo;
    }
    $sql .= date_range_sql('r.expected_return_at', $from, $to, $params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Standard track. Separate statement, so the :date_from/:date_to/:std_no
    // names can safely be reused (real prepared statements don't allow a name
    // to appear twice in ONE statement).
    $params = [];
    $sql = 'SELECT so.std_no, s.std_name, "standard" AS track,
                   so.checked_in_at AS violation_at,
                   so.checked_out_at AS out_at,
                   NULL AS due_at,
                   so.checked_in_at AS back_at
            FROM standard_outing so
            JOIN student s ON s.std_no = so.std_no
            WHERE so.is_late_return = 1 AND so.checked_in_at IS NOT NULL';
    if ($stdNo !== null) {
        $sql .= ' AND so.std_no = :std_no';
        $params['std_no'] = $stdNo;
    }
    $sql .= date_range_sql('so.checked_in_at', $from, $to, $params);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = array_merge($rows, $stmt->fetchAll());

    usort($rows, static fn(array $a, array $b): int => strcmp($b['violation_at'], $a['violation_at']));
    return $rows;
}

/**
 * One row per student who has at least one violation: how many, and when the
 * latest one was. Most violations first.
 * Row keys: std_no, std_name, violations, last_violation_at
 */
function get_violation_summary(?string $from = null, ?string $to = null): array
{
    $byStudent = [];
    // get_violations() is newest-first, so the first row seen per student is their latest.
    foreach (get_violations(null, $from, $to) as $v) {
        $key = $v['std_no'];
        if (!isset($byStudent[$key])) {
            $byStudent[$key] = [
                'std_no'            => $v['std_no'],
                'std_name'          => $v['std_name'],
                'violations'        => 0,
                'last_violation_at' => $v['violation_at'],
            ];
        }
        $byStudent[$key]['violations']++;
    }

    $rows = array_values($byStudent);
    usort($rows, static fn(array $a, array $b): int =>
        [$b['violations'], $b['last_violation_at'], $a['std_name']]
        <=> [$a['violations'], $a['last_violation_at'], $b['std_name']]
    );
    return $rows;
}

/** Filters summary rows by a name / student-number search. Empty search = no filter. */
function search_violation_summary(array $rows, ?string $search): array
{
    $search = trim((string) $search);
    if ($search === '') {
        return $rows;
    }
    return array_values(array_filter($rows, static fn(array $r): bool =>
        mb_stripos($r['std_name'], $search) !== false || mb_stripos($r['std_no'], $search) !== false
    ));
}

/** Same as search_violation_summary(), but for the raw per-event rows get_violations() returns. */
function search_violations(array $rows, ?string $search): array
{
    $search = trim((string) $search);
    if ($search === '') {
        return $rows;
    }
    return array_values(array_filter($rows, static fn(array $r): bool =>
        mb_stripos($r['std_name'], $search) !== false || mb_stripos($r['std_no'], $search) !== false
    ));
}

/**
 * Students who are past their agreed return time and still out, right now.
 * Computed directly rather than read from status = "overdue", so it is
 * correct even if the overdue check hasn't run yet.
 */
function count_overdue_now(): int
{
    $stmt = get_db()->prepare(
        'SELECT COUNT(*) FROM outing_request
         WHERE (type = "checkout" AND status IN ("checked_out", "overdue")
                AND expected_return_at IS NOT NULL
                AND DATE_ADD(expected_return_at, INTERVAL :tolerance_minutes_1 MINUTE) < NOW())
            OR (type = "checkin" AND status IN ("approved", "overdue")
                AND DATE_ADD(expected_return_at, INTERVAL :tolerance_minutes_2 MINUTE) < NOW())'
    );
    $stmt->execute(special_late_params());
    return (int) $stmt->fetchColumn();
}

/**
 * A student's own outings (all tracks), newest first -- only outings that
 * actually happened (a request that was never approved or never used isn't an
 * outing). Same late-return rules as get_violations().
 *
 * Row keys: track ('special_checkout'|'special_checkin'|'standard'),
 *           out_at (null for a checkin approval -- no departure is tracked
 *           on that type), back_at (null = not back/arrived yet), sort_at,
 *           result ('on_time'|'late'|'out'|'awaiting' -- 'awaiting' is a
 *           checkin approval whose arrival hasn't happened yet, not a
 *           violation, just not resolved)
 */
function get_student_outing_history(string $stdNo): array
{
    $pdo = get_db();

    $stmt = $pdo->prepare(
        'SELECT CASE WHEN r.type = "checkout" THEN "special_checkout" ELSE "special_checkin" END AS track,
                r.actual_out_at AS out_at, r.actual_return_at AS back_at,
                COALESCE(r.actual_out_at, r.actual_return_at, r.expected_return_at) AS sort_at,
                CASE
                    WHEN ' . special_late_sql() . ' THEN "late"
                    WHEN r.type = "checkin" AND r.actual_return_at IS NULL THEN "awaiting"
                    WHEN r.actual_return_at IS NULL THEN "out"
                    ELSE "on_time"
                END AS result,
                gl.group_id, gs.std_name AS group_lead_name
         FROM outing_request r
         LEFT JOIN outing_group_link gl ON gl.kind = "special" AND gl.ref_id = r.id
         LEFT JOIN outing_group og ON og.id = gl.group_id
         LEFT JOIN student gs ON gs.std_no = og.lead_std_no
         WHERE r.std_no = :std_no
           AND ((r.type = "checkout" AND r.actual_out_at IS NOT NULL)
                OR (r.type = "checkin" AND r.status IN ("approved", "checked_in", "overdue")))'
    );
    $stmt->execute(['std_no' => $stdNo] + special_late_params());
    $rows = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT "standard" AS track, so.checked_out_at AS out_at, so.checked_in_at AS back_at,
                so.checked_out_at AS sort_at,
                CASE WHEN so.is_late_return = 1 THEN "late"
                     WHEN so.checked_in_at IS NULL THEN "out"
                     ELSE "on_time" END AS result,
                gl.group_id, gs.std_name AS group_lead_name
         FROM standard_outing so
         LEFT JOIN outing_group_link gl ON gl.kind = "standard" AND gl.ref_id = so.id
         LEFT JOIN outing_group og ON og.id = gl.group_id
         LEFT JOIN student gs ON gs.std_no = og.lead_std_no
         WHERE so.std_no = :std_no'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $rows = array_merge($rows, $stmt->fetchAll());

    usort($rows, static fn(array $a, array $b): int => strcmp($b['sort_at'], $a['sort_at']));
    return $rows;
}
