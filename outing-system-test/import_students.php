<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/student_import.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin']);

$result = null;
$fatalError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $fatalError = 'Please choose a CSV file to upload.';
    } else {
        $name = $_FILES['csv_file']['name'];
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
            $fatalError = 'Please upload a .csv file.';
        } else {
            try {
                $rows   = parse_student_csv($_FILES['csv_file']['tmp_name']);
                $result = import_students_csv($rows);
            } catch (Throwable $e) {
                $fatalError = 'Import failed: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Import students - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Import students</h1>

        <p>CSV columns: <code>std_no, std_name, ic_no, email, program, phone, emergency_contact_name, emergency_contact_phone</code>.
           Only <code>std_no</code>, <code>std_name</code>, and <code>ic_no</code> are required. Header row is required; column order doesn't matter.</p>

        <p>A student number that already exists gets its details <strong>updated</strong> -- their password is never touched.
           A new student number gets created with an initial password of the <strong>last 6 digits of their IC number</strong>;
           they should change it after logging in for the first time.</p>

        <?php if ($fatalError): ?>
            <p style="color: red;"><?= h($fatalError) ?></p>
        <?php endif; ?>

        <?php if ($result): ?>
            <div style="border: 1px solid #ccc; padding: 12px; margin-bottom: 16px;">
                <p><strong>Created:</strong> <?= (int) $result['created'] ?> &nbsp;
                   <strong>Updated:</strong> <?= (int) $result['updated'] ?> &nbsp;
                   <strong>Skipped (errors):</strong> <?= count($result['errors']) ?></p>

                <?php if ($result['errors']): ?>
                    <table border="1" cellpadding="6" cellspacing="0">
                        <tr><th>Row</th><th>Student No.</th><th>Problem</th></tr>
                        <?php foreach ($result['errors'] as $err): ?>
                            <tr>
                                <td><?= (int) $err['row'] ?></td>
                                <td><?= h($err['std_no']) ?></td>
                                <td><?= h($err['message']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="import_students.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label>CSV file <input type="file" name="csv_file" accept=".csv" required></label>
            <button type="submit">Import</button>
        </form>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
