<?php
// Header notification bell -- student-facing (request status, curfew
// time alerts, violations). Warden/guard notifications are a later
// round; the table's reference_table/reference_id columns are kept
// generic so this doesn't need a schema change when that happens.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

/**
 * Inserts a notification unless an identical one (same student, type,
 * and reference row) already exists -- the unique-ish guard that keeps
 * a scheduled job from re-sending the same 10-minute warning every
 * time it runs. Returns false (not an exception) on a skipped dupe.
 */
function create_notification(
    string $stdNo,
    string $type,
    string $message,
    ?string $refTable = null,
    ?int $refId = null
): bool {
    $pdo = get_db();

    $check = $pdo->prepare(
        'SELECT id FROM notification
         WHERE std_no = :std_no AND type = :type
           AND reference_table <=> :ref_table AND reference_id <=> :ref_id
         LIMIT 1'
    );
    $check->execute([
        'std_no'    => $stdNo,
        'type'      => $type,
        'ref_table' => $refTable,
        'ref_id'    => $refId,
    ]);
    if ($check->fetch()) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO notification (std_no, type, message, reference_table, reference_id)
         VALUES (:std_no, :type, :message, :ref_table, :ref_id)'
    );
    return $stmt->execute([
        'std_no'    => $stdNo,
        'type'      => $type,
        'message'   => $message,
        'ref_table' => $refTable,
        'ref_id'    => $refId,
    ]);
}

function get_unread_count(string $stdNo): int
{
    $stmt = get_db()->prepare(
        'SELECT COUNT(*) FROM notification WHERE std_no = :std_no AND is_read = 0'
    );
    $stmt->execute(['std_no' => $stdNo]);
    return (int) $stmt->fetchColumn();
}

function get_notifications(string $stdNo, int $limit = 20): array
{
    $stmt = get_db()->prepare(
        'SELECT id, type, message, is_read, created_at
         FROM notification WHERE std_no = :std_no
         ORDER BY created_at DESC LIMIT :limit'
    );
    $stmt->bindValue(':std_no', $stdNo);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Returns false (not an exception) if the id doesn't belong to this student. */
function mark_notification_read(string $stdNo, int $notificationId): bool
{
    $stmt = get_db()->prepare(
        'UPDATE notification SET is_read = 1 WHERE id = :id AND std_no = :std_no'
    );
    $stmt->execute(['id' => $notificationId, 'std_no' => $stdNo]);
    return $stmt->rowCount() > 0;
}

function mark_all_notifications_read(string $stdNo): bool
{
    $stmt = get_db()->prepare(
        'UPDATE notification SET is_read = 1 WHERE std_no = :std_no AND is_read = 0'
    );
    return $stmt->execute(['std_no' => $stdNo]);
}

function delete_notification(string $stdNo, int $notificationId): bool
{
    $stmt = get_db()->prepare(
        'DELETE FROM notification WHERE id = :id AND std_no = :std_no'
    );
    $stmt->execute(['id' => $notificationId, 'std_no' => $stdNo]);
    return $stmt->rowCount() > 0;
}
