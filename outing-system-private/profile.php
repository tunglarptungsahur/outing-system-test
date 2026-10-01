<?php
// Self-service profile: users edit their own contact info and reset
// their own password. Deliberately does NOT let anyone edit std_name,
// ic_no, staff_id, or role themselves -- those stay admin-controlled
// (via student_import.php for students; there's no bulk staff import
// yet, so staff records are still hand-managed, but the principle is
// the same: identity fields are not self-service).

declare(strict_types=1);
require_once __DIR__ . '/db.php';

function get_student_profile(string $stdNo): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT std_no, std_name, ic_no, program, email, phone,
                emergency_contact_name, emergency_contact_phone
         FROM student WHERE std_no = :std_no LIMIT 1'
    );
    $stmt->execute(['std_no' => $stdNo]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_staff_profile(string $staffId): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare(
        'SELECT staff_id, name, email, role FROM staff WHERE staff_id = :staff_id LIMIT 1'
    );
    $stmt->execute(['staff_id' => $staffId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// Note: there is deliberately no update_student_profile() /
// update_staff_profile() here. Contact info (email, phone, emergency
// contact) is sourced from the university database via
// student_import.php and is display-only on the profile page --
// self-service editing was considered and explicitly turned down.
// Only password changes are self-service; see below.

/**
 * Shared password-change validation: current password must verify
 * against the given hash, new password must meet the minimum bar, and
 * the two "new password" fields must match. Throws with a message
 * that's safe to show directly to the user.
 */
function validate_password_change(string $currentPassword, string $currentHash, string $newPassword, string $confirmPassword): void
{
    if (!password_verify($currentPassword, $currentHash)) {
        throw new InvalidArgumentException('Current password is incorrect.');
    }
    if (strlen($newPassword) < 8) {
        throw new InvalidArgumentException('New password must be at least 8 characters.');
    }
    if ($newPassword !== $confirmPassword) {
        throw new InvalidArgumentException('New password and confirmation do not match.');
    }
}

function change_student_password(string $stdNo, string $currentPassword, string $newPassword, string $confirmPassword): void
{
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT password_hash FROM student WHERE std_no = :std_no LIMIT 1');
    $stmt->execute(['std_no' => $stdNo]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Account not found.');
    }

    validate_password_change($currentPassword, $row['password_hash'], $newPassword, $confirmPassword);

    $update = $pdo->prepare('UPDATE student SET password_hash = :hash WHERE std_no = :std_no');
    $update->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'std_no' => $stdNo]);
}

function change_staff_password(string $staffId, string $currentPassword, string $newPassword, string $confirmPassword): void
{
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT password_hash FROM staff WHERE staff_id = :staff_id LIMIT 1');
    $stmt->execute(['staff_id' => $staffId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Account not found.');
    }

    validate_password_change($currentPassword, $row['password_hash'], $newPassword, $confirmPassword);

    $update = $pdo->prepare('UPDATE staff SET password_hash = :hash WHERE staff_id = :staff_id');
    $update->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'staff_id' => $staffId]);
}

// --- Admin: manage staff accounts ---------------------------------------

function get_all_staff(): array
{
    $pdo = get_db();
    return $pdo->query(
        'SELECT staff_id, name, email, role, is_active FROM staff ORDER BY role, name'
    )->fetchAll();
}

/**
 * Generates a random temporary password for a staff member who's
 * locked out, since staff have no self-service "forgot password" flow
 * (unlike students, who reset their own via the profile page -- this
 * is only reachable by an admin, for someone else's account).
 * Returns the plaintext password so the admin can hand it over; it is
 * never stored anywhere except as a hash.
 */
function admin_reset_staff_password(string $staffId): string
{
    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT id FROM staff WHERE staff_id = :staff_id LIMIT 1');
    $stmt->execute(['staff_id' => $staffId]);
    if (!$stmt->fetch()) {
        throw new InvalidArgumentException('Staff account not found.');
    }

    $tempPassword = generate_temp_password();

    $update = $pdo->prepare('UPDATE staff SET password_hash = :hash WHERE staff_id = :staff_id');
    $update->execute([
        'hash'     => password_hash($tempPassword, PASSWORD_DEFAULT),
        'staff_id' => $staffId,
    ]);

    return $tempPassword;
}

function generate_temp_password(): string
{
    return bin2hex(random_bytes(5)); // 10 hex characters
}

const STAFF_ROLES = ['admin', 'warden', 'guard'];

/**
 * Creates a new staff account with a random temporary password.
 * Returns the plaintext password so the admin can hand it over.
 */
function create_staff_account(string $staffId, string $name, string $role, string $email): string
{
    $staffId = trim($staffId);
    $name    = trim($name);
    $email   = trim($email);

    if ($staffId === '' || $name === '') {
        throw new InvalidArgumentException('Staff ID and name are required.');
    }
    if (!in_array($role, STAFF_ROLES, true)) {
        throw new InvalidArgumentException('Invalid role.');
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException("Invalid email: {$email}");
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT id FROM staff WHERE staff_id = :staff_id LIMIT 1');
    $stmt->execute(['staff_id' => $staffId]);
    if ($stmt->fetch()) {
        throw new InvalidArgumentException("Staff ID {$staffId} already exists.");
    }

    $tempPassword = generate_temp_password();

    $insert = $pdo->prepare(
        'INSERT INTO staff (staff_id, name, role, email, password_hash, is_active)
         VALUES (:staff_id, :name, :role, :email, :password_hash, 1)'
    );
    $insert->execute([
        'staff_id'      => $staffId,
        'name'          => $name,
        'role'          => $role,
        'email'         => $email !== '' ? $email : null,
        'password_hash' => password_hash($tempPassword, PASSWORD_DEFAULT),
    ]);

    return $tempPassword;
}

/**
 * Flips a staff account's active status. An admin can never deactivate
 * their own account through this -- that's how you'd accidentally lock
 * every admin out with no way back in short of direct DB access.
 * Returns the new is_active value.
 */
function toggle_staff_active(string $staffId, string $actingStaffId): bool
{
    if ($staffId === $actingStaffId) {
        throw new InvalidArgumentException('You cannot deactivate your own account.');
    }

    $pdo  = get_db();
    $stmt = $pdo->prepare('SELECT is_active FROM staff WHERE staff_id = :staff_id LIMIT 1');
    $stmt->execute(['staff_id' => $staffId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Staff account not found.');
    }

    $newState = $row['is_active'] ? 0 : 1;
    $update   = $pdo->prepare('UPDATE staff SET is_active = :active WHERE staff_id = :staff_id');
    $update->execute(['active' => $newState, 'staff_id' => $staffId]);

    return (bool) $newState;
}
