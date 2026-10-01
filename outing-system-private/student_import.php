<?php
// Bulk student import from CSV. Existing std_no -> update the record,
// leave password_hash untouched (never reset a password someone may
// have already changed). New std_no -> insert, with a password derived
// from their IC number so students can log in immediately without
// waiting on an email.
//
// Deliberately does the "does this std_no already exist" check as a
// separate SELECT per row rather than a single INSERT ... ON DUPLICATE
// KEY UPDATE, even though that's slower for very large files -- it's
// what lets us give an accurate created/updated count and skip touching
// password_hash on updates without relying on MySQL's row-count quirks
// for ON DUPLICATE KEY UPDATE (0/1/2 depending on whether the row
// actually changed, which is easy to get wrong).

declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Last 6 digits of the IC number, used as the initial password. */
function initial_password_from_ic(string $icNo): string
{
    $digits = preg_replace('/\D/', '', $icNo) ?? '';
    return substr($digits, -6) ?: 'Outing123';
}

/**
 * @param array<int, array<string, string>> $rows Each row keyed by
 *   column name: std_no, std_name, ic_no, email, program, phone,
 *   emergency_contact_name, emergency_contact_phone.
 * @return array{created:int, updated:int, errors:array<int, array{row:int, std_no:string, message:string}>}
 */
function import_students_csv(array $rows): array
{
    $pdo = get_db();
    $result = ['created' => 0, 'updated' => 0, 'errors' => []];

    $findStmt = $pdo->prepare('SELECT id FROM student WHERE std_no = :std_no LIMIT 1');

    $insertStmt = $pdo->prepare(
        'INSERT INTO student
            (std_no, std_name, ic_no, email, program, phone,
             emergency_contact_name, emergency_contact_phone, password_hash, is_active)
         VALUES
            (:std_no, :std_name, :ic_no, :email, :program, :phone,
             :emergency_contact_name, :emergency_contact_phone, :password_hash, 1)'
    );

    $updateStmt = $pdo->prepare(
        'UPDATE student SET
            std_name = :std_name,
            ic_no = :ic_no,
            email = :email,
            program = :program,
            phone = :phone,
            emergency_contact_name = :emergency_contact_name,
            emergency_contact_phone = :emergency_contact_phone
         WHERE std_no = :std_no'
        // password_hash intentionally not touched here.
    );

    $pdo->beginTransaction();
    try {
        foreach ($rows as $i => $row) {
            $rowNum = $i + 2; // +1 for zero-index, +1 for the header row

            $stdNo   = trim($row['std_no'] ?? '');
            $stdName = trim($row['std_name'] ?? '');
            $icNo    = trim($row['ic_no'] ?? '');
            $email   = trim($row['email'] ?? '');

            if ($stdNo === '' || $stdName === '' || $icNo === '') {
                $result['errors'][] = ['row' => $rowNum, 'std_no' => $stdNo, 'message' => 'Missing required field (std_no, std_name, or ic_no).'];
                continue;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['errors'][] = ['row' => $rowNum, 'std_no' => $stdNo, 'message' => "Invalid email: {$email}"];
                continue;
            }

            $fields = [
                'std_no'                  => $stdNo,
                'std_name'                => $stdName,
                'ic_no'                   => $icNo,
                'email'                   => $email !== '' ? $email : null,
                'program'                 => trim($row['program'] ?? '') ?: null,
                'phone'                   => trim($row['phone'] ?? '') ?: null,
                'emergency_contact_name'  => trim($row['emergency_contact_name'] ?? '') ?: null,
                'emergency_contact_phone' => trim($row['emergency_contact_phone'] ?? '') ?: null,
            ];

            $findStmt->execute(['std_no' => $stdNo]);
            $exists = (bool) $findStmt->fetch();

            if ($exists) {
                $updateStmt->execute($fields);
                $result['updated']++;
            } else {
                $fields['password_hash'] = password_hash(initial_password_from_ic($icNo), PASSWORD_DEFAULT);
                $insertStmt->execute($fields);
                $result['created']++;
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $result;
}

/**
 * Parses an uploaded CSV file into row arrays keyed by header name.
 * Header matching is case-insensitive and ignores surrounding spaces.
 */
function parse_student_csv(string $filePath): array
{
    $handle = fopen($filePath, 'r');
    if ($handle === false) {
        throw new RuntimeException('Could not read the uploaded file.');
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        throw new RuntimeException('The file appears to be empty.');
    }
    $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);

    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if (count($line) === 1 && trim((string) $line[0]) === '') {
            continue; // skip blank lines
        }
        $rows[] = array_combine($header, array_pad($line, count($header), ''));
    }
    fclose($handle);

    return $rows;
}
