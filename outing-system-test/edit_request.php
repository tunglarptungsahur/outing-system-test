<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_student();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$request = $id > 0 ? get_request_for_student($id, $_SESSION['std_no']) : null;

if (!$request) {
    flash_set('error', 'That request was not found.');
    header('Location: my_requests.php');
    exit;
}
if ($request['status'] !== 'pending') {
    flash_set('error', 'Only pending requests can be edited.');
    header('Location: my_requests.php');
    exit;
}

// Type is fixed at creation -- editing only adjusts the details, never
// what kind of permission this is.
$type = $request['type'];

$error = '';
$values = [
    'reason'             => $request['reason'],
    'destination'        => $request['destination'],
    'requested_out_at'   => $request['requested_out_at'] ? to_datetime_local($request['requested_out_at']) : '',
    'expected_return_at' => $request['expected_return_at'] ? to_datetime_local($request['expected_return_at']) : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $values['reason']             = trim($_POST['reason'] ?? '');
    $values['destination']        = trim($_POST['destination'] ?? '');
    $values['requested_out_at']   = $_POST['requested_out_at'] ?? '';
    $values['expected_return_at'] = $_POST['expected_return_at'] ?? '';

    $requiredTimeField = $type === 'checkin' ? 'expected_return_at' : 'requested_out_at';

    if ($values['reason'] === '' || $values['destination'] === '' || $values[$requiredTimeField] === '') {
        $error = 'Please fill in all fields.';
    } else {
        try {
            $updated = update_pending_request(
                $id,
                $_SESSION['std_no'],
                $type,
                $values['reason'],
                $values['destination'],
                $type === 'checkout' ? normalize_datetime_local($values['requested_out_at']) : null,
                $type === 'checkin' ? normalize_datetime_local($values['expected_return_at']) : null
            );
            if ($updated) {
                flash_set('success', 'Outing request updated.');
            } else {
                flash_set('error', 'This request can no longer be edited (it may have just been reviewed).');
            }
            header('Location: my_requests.php');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit outing request - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'student_header.php'; ?>

    <main class="form-page">
        <h1>Edit outing request</h1>
        <p><?= h(request_type_label($type)) ?></p>
        <p>Current time: <?php require 'clock_widget.php'; ?></p>

        <?php if ($error !== ''): ?>
            <p class="form-error"><?= h($error) ?></p>
        <?php endif; ?>

        <fieldset>
            <form method="post" action="edit_request.php?id=<?= $id ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <label>
                    <?= h(destination_field_label($type)) ?>
                    <input type="text" name="destination" maxlength="255" value="<?= h($values['destination']) ?>" required>
                </label>
                <br>
                <label>
                    Reason
                    <input type="text" name="reason" maxlength="255" value="<?= h($values['reason']) ?>" required>
                </label>
                <br>
                <?php if ($type === 'checkout'): ?>
                    <label>
                        What time do you need to leave?
                        <input type="datetime-local" name="requested_out_at" value="<?= h($values['requested_out_at']) ?>" required>
                    </label>
                <?php else: ?>
                    <label>
                        What time do you expect to arrive back?
                        <input type="datetime-local" name="expected_return_at" value="<?= h($values['expected_return_at']) ?>" required>
                    </label>
                <?php endif; ?>
                <br>
                <button type="submit">Save changes</button>
                <a href="my_requests.php">Cancel</a>
            </form>
        </fieldset>
    </main>
</body>
</html>
