<?php
// Group outings. A "Group Lead" opens a group, invites up to 4 other
// students, and each invitee must ACCEPT before they count. The lead
// then shows one Master QR (dynamic, see totp.php); the guard scans it
// once and processes everyone together.
//
// Batch processing is just the normal per-student routing run in a
// loop (routing.php), so every member still gets their own
// standard_outing / outing_event rows and the curfew rules apply to
// each person individually -- a group can't do anything a member
// couldn't do alone.

declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/routing.php';

const GROUP_MAX_MEMBERS    = 4;   // in addition to the lead (5 people total)
const GROUP_LIFETIME_HOURS = 24;

// --- Lookups --------------------------------------------------------------

function get_active_group(int $groupId): ?array
{
    $stmt = get_db()->prepare(
        'SELECT g.*, s.std_name AS lead_name, s.is_active AS lead_active
         FROM outing_group g JOIN student s ON s.std_no = g.lead_std_no
         WHERE g.id = :id AND g.status = "active" AND g.expires_at > NOW() LIMIT 1'
    );
    $stmt->execute(['id' => $groupId]);
    return $stmt->fetch() ?: null;
}

function get_active_group_for_lead(string $stdNo): ?array
{
    $stmt = get_db()->prepare(
        'SELECT g.*, s.std_name AS lead_name, s.is_active AS lead_active
         FROM outing_group g JOIN student s ON s.std_no = g.lead_std_no
         WHERE g.lead_std_no = :std AND g.status = "active" AND g.expires_at > NOW()
         ORDER BY g.id DESC LIMIT 1'
    );
    $stmt->execute(['std' => $stdNo]);
    return $stmt->fetch() ?: null;
}

function get_accepted_group_for_member(string $stdNo): ?array
{
    $stmt = get_db()->prepare(
        'SELECT g.*, s.std_name AS lead_name, m.id AS member_row_id
         FROM outing_group_member m
         JOIN outing_group g ON g.id = m.group_id
         JOIN student s ON s.std_no = g.lead_std_no
         WHERE m.std_no = :std AND m.status = "accepted"
           AND g.status = "active" AND g.expires_at > NOW()
         ORDER BY g.id DESC LIMIT 1'
    );
    $stmt->execute(['std' => $stdNo]);
    return $stmt->fetch() ?: null;
}

function get_pending_invitations(string $stdNo): array
{
    $stmt = get_db()->prepare(
        'SELECT m.id AS member_row_id, g.id AS group_id, s.std_name AS lead_name, g.lead_std_no
         FROM outing_group_member m
         JOIN outing_group g ON g.id = m.group_id
         JOIN student s ON s.std_no = g.lead_std_no
         WHERE m.std_no = :std AND m.status = "invited"
           AND g.status = "active" AND g.expires_at > NOW()
         ORDER BY m.id DESC'
    );
    $stmt->execute(['std' => $stdNo]);
    return $stmt->fetchAll();
}

/** Invited + accepted members (not the lead), for the lead's management view. */
function get_group_members(int $groupId): array
{
    $stmt = get_db()->prepare(
        'SELECT m.*, s.std_name FROM outing_group_member m
         JOIN student s ON s.std_no = m.std_no
         WHERE m.group_id = :gid AND m.status IN ("invited","accepted")
         ORDER BY m.id ASC'
    );
    $stmt->execute(['gid' => $groupId]);
    return $stmt->fetchAll();
}

/** Lead + accepted members: who a guard can actually process. */
function get_group_participants(int $groupId): array
{
    $g = get_active_group($groupId);
    if (!$g) {
        return [];
    }
    $rows = [[
        'std_no' => $g['lead_std_no'], 'std_name' => $g['lead_name'],
        'is_active' => (int) $g['lead_active'], 'role' => 'Lead',
    ]];
    $stmt = get_db()->prepare(
        'SELECT m.std_no, s.std_name, s.is_active FROM outing_group_member m
         JOIN student s ON s.std_no = m.std_no
         WHERE m.group_id = :gid AND m.status = "accepted" ORDER BY m.id ASC'
    );
    $stmt->execute(['gid' => $groupId]);
    foreach ($stmt->fetchAll() as $r) {
        $rows[] = $r + ['role' => 'Member'];
    }
    return $rows;
}

function count_accepted_members(int $groupId): int
{
    $stmt = get_db()->prepare('SELECT COUNT(*) FROM outing_group_member WHERE group_id = :gid AND status = "accepted"');
    $stmt->execute(['gid' => $groupId]);
    return (int) $stmt->fetchColumn();
}

// --- Lead actions ---------------------------------------------------------

function create_group(string $leadStdNo): int
{
    if (get_active_group_for_lead($leadStdNo)) {
        throw new InvalidArgumentException('You already have an active group.');
    }
    if (get_accepted_group_for_member($leadStdNo)) {
        throw new InvalidArgumentException('You are already a member of another group. Leave it first.');
    }
    $pdo = get_db();
    $pdo->prepare(
        'INSERT INTO outing_group (lead_std_no, expires_at)
         VALUES (:lead, DATE_ADD(NOW(), INTERVAL ' . GROUP_LIFETIME_HOURS . ' HOUR))'
    )->execute(['lead' => $leadStdNo]);
    return (int) $pdo->lastInsertId();
}

function invite_group_member(int $groupId, string $leadStdNo, string $memberStdNo): void
{
    $memberStdNo = trim($memberStdNo);
    $group = get_active_group($groupId);
    if (!$group || $group['lead_std_no'] !== $leadStdNo) {
        throw new InvalidArgumentException('Group not found or no longer active.');
    }
    if ($memberStdNo === '') {
        throw new InvalidArgumentException('Enter a student number.');
    }
    if ($memberStdNo === $leadStdNo) {
        throw new InvalidArgumentException('You are already the group lead.');
    }

    $stmt = get_db()->prepare('SELECT std_no, std_name, is_active FROM student WHERE std_no = :s LIMIT 1');
    $stmt->execute(['s' => $memberStdNo]);
    $student = $stmt->fetch();
    if (!$student || !$student['is_active']) {
        throw new InvalidArgumentException('No active student found with that number.');
    }

    foreach (get_group_members($groupId) as $m) {
        if ($m['std_no'] === $memberStdNo) {
            throw new InvalidArgumentException('That student is already invited to this group.');
        }
    }
    if (count(get_group_members($groupId)) >= GROUP_MAX_MEMBERS) {
        throw new InvalidArgumentException('A group can have at most ' . GROUP_MAX_MEMBERS . ' other students besides the lead.');
    }
    if (get_active_group_for_lead($memberStdNo) || get_accepted_group_for_member($memberStdNo)) {
        throw new InvalidArgumentException('That student is already in another active group.');
    }

    $pdo = get_db();
    $pdo->prepare('INSERT INTO outing_group_member (group_id, std_no) VALUES (:g, :s)')
        ->execute(['g' => $groupId, 's' => $memberStdNo]);
    $rowId = (int) $pdo->lastInsertId();

    create_notification(
        $memberStdNo, 'group_invite',
        $group['lead_name'] . ' invited you to a group outing. Open "My group" to accept or decline.',
        'outing_group_member', $rowId
    );
}

function remove_group_member(int $groupId, string $leadStdNo, string $memberStdNo): bool
{
    $stmt = get_db()->prepare(
        'UPDATE outing_group_member m JOIN outing_group g ON g.id = m.group_id
         SET m.status = "removed", m.responded_at = NOW()
         WHERE m.group_id = :gid AND m.std_no = :s AND m.status IN ("invited","accepted")
           AND g.lead_std_no = :lead AND g.status = "active"'
    );
    $stmt->execute(['gid' => $groupId, 's' => $memberStdNo, 'lead' => $leadStdNo]);
    $ok = $stmt->rowCount() > 0;
    if ($ok) {
        maybe_close_group($groupId); // the remaining people may all be back already
    }
    return $ok;
}

function close_group(int $groupId, string $leadStdNo): bool
{
    $stmt = get_db()->prepare(
        'UPDATE outing_group SET status = "closed" WHERE id = :id AND lead_std_no = :lead AND status = "active"'
    );
    $stmt->execute(['id' => $groupId, 'lead' => $leadStdNo]);
    return $stmt->rowCount() > 0;
}

// --- Member actions -------------------------------------------------------

function respond_to_invitation(int $memberRowId, string $stdNo, bool $accept): bool
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT m.id, g.lead_std_no FROM outing_group_member m JOIN outing_group g ON g.id = m.group_id
         WHERE m.id = :id AND m.std_no = :s AND m.status = "invited"
           AND g.status = "active" AND g.expires_at > NOW()'
    );
    $stmt->execute(['id' => $memberRowId, 's' => $stdNo]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }
    if ($accept && (get_active_group_for_lead($stdNo) || get_accepted_group_for_member($stdNo))) {
        throw new InvalidArgumentException('You are already in another active group. Leave or close it first.');
    }

    $pdo->prepare(
        'UPDATE outing_group_member SET status = :st, responded_at = NOW() WHERE id = :id AND status = "invited"'
    )->execute(['st' => $accept ? 'accepted' : 'declined', 'id' => $memberRowId]);

    $nameStmt = $pdo->prepare('SELECT std_name FROM student WHERE std_no = :s');
    $nameStmt->execute(['s' => $stdNo]);
    create_notification(
        $row['lead_std_no'], 'group_response',
        ((string) $nameStmt->fetchColumn()) . ($accept ? ' accepted' : ' declined') . ' your group invitation.',
        'outing_group_member', $memberRowId
    );
    return true;
}

function leave_group(int $groupId, string $stdNo): bool
{
    $stmt = get_db()->prepare(
        'UPDATE outing_group_member SET status = "left", responded_at = NOW()
         WHERE group_id = :gid AND std_no = :s AND status = "accepted"'
    );
    $stmt->execute(['gid' => $groupId, 's' => $stdNo]);
    $ok = $stmt->rowCount() > 0;
    if ($ok) {
        maybe_close_group($groupId);
    }
    return $ok;
}

// --- Movement tracking + automatic close ------------------------------------
//
// outing_group_log holds one row per (group, student): when they went
// out and when they came back. It is updated from EVERY check-out /
// check-in -- via the Master QR batch, or a student's own QR / manual
// entry -- so someone who walks back in alone still counts.
//
// Rule: the group closes automatically once at least one person has
// gone out and EVERYONE who went out (lead + accepted members) is back.

function get_any_active_group_for_student(string $stdNo): ?array
{
    return get_active_group_for_lead($stdNo) ?: get_accepted_group_for_member($stdNo);
}

/** Call after a successful gate check-out ('out') or check-in ('in'). Never throws for "no group". */
function group_record_movement(string $stdNo, string $direction): void
{
    $group = get_any_active_group_for_student($stdNo);
    if (!$group) {
        return;
    }
    $gid = (int) $group['id'];
    $pdo = get_db();

    if ($direction === 'out') {
        // A second trip while the group is still open restarts that student's row.
        $pdo->prepare(
            'INSERT INTO outing_group_log (group_id, std_no, out_at) VALUES (:g, :s, NOW())
             ON DUPLICATE KEY UPDATE out_at = NOW(), in_at = NULL'
        )->execute(['g' => $gid, 's' => $stdNo]);
        tag_trip_with_group($gid, $stdNo);
        return;
    }

    $pdo->prepare(
        'UPDATE outing_group_log SET in_at = NOW()
         WHERE group_id = :g AND std_no = :s AND in_at IS NULL'
    )->execute(['g' => $gid, 's' => $stdNo]);
    maybe_close_group($gid);
}

/**
 * Links the trip that was just opened for this student (standard or
 * special) to the group, so outing history can show it was a group
 * outing. Uses a link table so the existing outing tables stay as-is.
 */
function tag_trip_with_group(int $groupId, string $stdNo): void
{
    $pdo = get_db();
    $std = $pdo->prepare('SELECT id FROM standard_outing WHERE std_no = :s AND checked_in_at IS NULL ORDER BY id DESC LIMIT 1');
    $std->execute(['s' => $stdNo]);
    $id = $std->fetchColumn();
    $kind = 'standard';
    if (!$id) {
        $sp = $pdo->prepare(
            'SELECT id FROM outing_request
             WHERE std_no = :s AND type = "checkout" AND status IN ("checked_out","overdue") AND actual_out_at IS NOT NULL
             ORDER BY id DESC LIMIT 1'
        );
        $sp->execute(['s' => $stdNo]);
        $id = $sp->fetchColumn();
        $kind = 'special';
    }
    if ($id) {
        $pdo->prepare('INSERT IGNORE INTO outing_group_link (kind, ref_id, group_id) VALUES (:k, :r, :g)')
            ->execute(['k' => $kind, 'r' => (int) $id, 'g' => $groupId]);
    }
}

/** Closes the group if everyone who went out is back. Returns true if it closed now. */
function maybe_close_group(int $groupId): bool
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS went_out, COALESCE(SUM(l.in_at IS NULL), 0) AS still_out
         FROM outing_group_log l
         WHERE l.group_id = :g
           AND ( l.std_no = (SELECT lead_std_no FROM outing_group WHERE id = :g2)
                 OR l.std_no IN (SELECT std_no FROM outing_group_member
                                 WHERE group_id = :g3 AND status = "accepted") )'
    );
    $stmt->execute(['g' => $groupId, 'g2' => $groupId, 'g3' => $groupId]);
    $r = $stmt->fetch();
    if ((int) $r['went_out'] < 1 || (int) $r['still_out'] > 0) {
        return false;
    }
    $upd = $pdo->prepare('UPDATE outing_group SET status = "closed" WHERE id = :id AND status = "active"');
    $upd->execute(['id' => $groupId]);
    return $upd->rowCount() > 0;
}

// --- Gate: batch processing -----------------------------------------------

/**
 * What the guard sees / what a batch may do, per participant. Built
 * from the database every time (never from the POST).
 *
 * Phase 'out':    nobody is currently out under this group -> normal routing.
 * Phase 'return': someone is out under this group -> only people who are
 *                 still out can be checked in; anyone already back shows
 *                 "Already checked in" and anyone who never left is skipped.
 *
 * @return array{phase:string, rows:array}
 */
function get_group_gate_view(int $groupId): array
{
    $participants = get_group_participants($groupId);

    $logStmt = get_db()->prepare('SELECT * FROM outing_group_log WHERE group_id = :g');
    $logStmt->execute(['g' => $groupId]);
    $log = [];
    foreach ($logStmt->fetchAll() as $l) {
        $log[$l['std_no']] = $l;
    }

    $phase = 'out';
    foreach ($participants as $p) {
        $l = $log[$p['std_no']] ?? null;
        if ($l && $l['out_at'] && !$l['in_at']) {
            $phase = 'return';
            break;
        }
    }

    $rows = [];
    foreach ($participants as $p) {
        $route = resolve_gate_route($p['std_no']);
        $l     = $log[$p['std_no']] ?? null;
        $note  = route_action_label($route);
        $tone  = 'ok';
        $actionable = (int) $p['is_active'] && route_is_actionable($route);

        if (!(int) $p['is_active']) {
            $note = 'Account inactive'; $tone = 'bad'; $actionable = false;
        } elseif ($phase === 'return') {
            if ($l && $l['in_at']) {
                $note = 'Already checked in (' . date('g:i A', strtotime($l['in_at'])) . ')';
                $tone = 'info'; $actionable = false;
            } elseif ($l && $l['out_at']) {
                // Still out: must be a check-in.
                $actionable = $actionable && $route['direction'] === 'in';
                if (!$actionable) { $tone = 'bad'; }
            } else {
                $note = 'Did not go out with this group'; $tone = 'info'; $actionable = false;
            }
        } elseif (!$actionable) {
            $tone = 'bad';
        }

        $rows[] = $p + ['route' => $route, 'note' => $note, 'tone' => $tone, 'actionable' => (bool) $actionable];
    }
    return ['phase' => $phase, 'rows' => $rows];
}

/**
 * Runs the normal per-student routing for every SELECTED participant
 * that get_group_gate_view() says is actionable. Each success is
 * recorded against the group; the group closes itself once everyone
 * who went out is back.
 *
 * @return array{done:string[], skipped:string[], closed:bool}
 */
function process_group_batch(array $group, array $selectedStdNos, string $guardId, string $gateLocation): array
{
    $done = [];
    $skipped = [];
    $gid = (int) $group['id'];

    foreach (get_group_gate_view($gid)['rows'] as $r) {
        if (!in_array($r['std_no'], $selectedStdNos, true)) {
            continue;
        }
        $label = $r['std_name'] . ' (' . $r['std_no'] . ')';
        if (!$r['actionable']) {
            $skipped[] = $label . ': ' . $r['note'];
            continue;
        }
        $result = execute_gate_route($r['route'], $guardId, $gateLocation);
        if ($result['ok']) {
            group_record_movement($r['std_no'], (string) $r['route']['direction']);
            $done[] = $label . ': ' . $result['message'];
        } else {
            $skipped[] = $label . ': ' . $result['message'];
        }
    }

    get_db()->prepare(
        'INSERT INTO outing_group_scan (group_id, guard_id, gate_location, processed_count, skipped_count)
         VALUES (:g, :guard, :loc, :done, :skipped)'
    )->execute([
        'g' => $gid, 'guard' => $guardId, 'loc' => $gateLocation,
        'done' => count($done), 'skipped' => count($skipped),
    ]);

    $st = get_db()->prepare('SELECT status FROM outing_group WHERE id = :id');
    $st->execute(['id' => $gid]);

    return ['done' => $done, 'skipped' => $skipped, 'closed' => $st->fetchColumn() === 'closed'];
}
