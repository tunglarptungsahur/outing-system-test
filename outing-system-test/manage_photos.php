<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/db.php';
require_once PRIVATE_LIB . '/photos.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_staff(['admin']);

$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $files = $_FILES['photos'] ?? null;
    if (!$files || !is_array($files['name']) || $files['name'][0] === '') {
        flash_set('error', 'Choose at least one image.');
    } else {
        $lookup = get_db()->prepare('SELECT 1 FROM student WHERE std_no = :s');
        foreach ($files['name'] as $i => $origName) {
            $stdNo = pathinfo((string) $origName, PATHINFO_FILENAME);
            try {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                    throw new InvalidArgumentException('Upload failed (code ' . (int) $files['error'][$i] . ').');
                }
                $lookup->execute(['s' => $stdNo]);
                if (!$lookup->fetchColumn()) {
                    throw new InvalidArgumentException('No student with this number (file name must be the student number).');
                }
                save_student_photo($stdNo, $files['tmp_name'][$i]);
                $results[] = [$origName, true, 'Saved'];
            } catch (Throwable $e) {
                $results[] = [$origName, false, $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not save the image.'];
            }
        }
    }
}

$totalStudents = (int) get_db()->query('SELECT COUNT(*) FROM student WHERE is_active = 1')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student photos - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'menuheader.php'; ?>
    <?php require 'mainmenu.php'; ?>

    <main style="padding: 16px;">
        <h1>Student photos</h1>
        <?= flash_render() ?>

        <p>Guards see this photo after scanning a student's QR code. Upload one or many images at once &mdash; each
           file must be named with the student number, e.g. <code>2026000003.jpg</code>. JPEG, PNG or WebP, up to 5 MB each.
           Uploading again replaces the old photo.</p>
        <p><strong><?= count_student_photos() ?></strong> photo(s) on file for <strong><?= $totalStudents ?></strong> active student(s).</p>

        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
            <button type="submit">Upload</button>
        </form>

        <?php if ($results): ?>
            <table border="1" cellpadding="6" cellspacing="0" style="margin-top:16px;">
                <thead><tr><th>File</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($results as [$name, $ok, $msg]): ?>
                    <tr>
                        <td><?= h($name) ?></td>
                        <td style="color:<?= $ok ? 'green' : 'red' ?>;"><?= h($msg) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>

    <?php require 'footer.php'; ?>
</body>
</html>
