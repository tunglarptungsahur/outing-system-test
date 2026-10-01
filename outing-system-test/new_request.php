<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/outing.php';
require_once PRIVATE_LIB . '/ui_helpers.php';

require_student();

$error = '';
$values = [
    'type' => 'checkout',
    'reason' => '',
    'destination' => '',
    'requested_out_at' => '',
    'expected_return_at' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $values['type']                = ($_POST['type'] ?? '') === 'checkin' ? 'checkin' : 'checkout';
    $values['reason']               = trim($_POST['reason'] ?? '');
    $values['destination']          = trim($_POST['destination'] ?? '');
    $values['requested_out_at']     = $_POST['requested_out_at'] ?? '';
    $values['expected_return_at']   = $_POST['expected_return_at'] ?? '';

    $requiredTimeField = $values['type'] === 'checkin' ? 'expected_return_at' : 'requested_out_at';

    if ($values['reason'] === '' || $values['destination'] === '' || $values[$requiredTimeField] === '') {
        $error = 'Please fill in all fields.';
    } else {
        try {
            create_outing_request(
                $_SESSION['std_no'],
                $values['type'],
                $values['reason'],
                $values['destination'],
                $values['type'] === 'checkout' ? normalize_datetime_local($values['requested_out_at']) : null,
                $values['type'] === 'checkin' ? normalize_datetime_local($values['expected_return_at']) : null
            );
            flash_set('success', 'Outing request submitted.');
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
    <title>New outing request - Outing System</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
    <?php require 'student_header.php'; ?>

    <main class="form-page">
        <h1>New special outing request</h1>
        <p>Use this if you need staff approval for something during curfew hours.</p>
        <p>Current time: <?php require 'clock_widget.php'; ?></p>

        <?php if ($error !== ''): ?>
            <p class="form-error"><?= h($error) ?></p>
        <?php endif; ?>

        <fieldset>
            <form method="post" action="new_request.php" id="request-form">
                <?= csrf_field() ?>

                <label>
                    <input type="radio" name="type" value="checkout" <?= $values['type'] === 'checkout' ? 'checked' : '' ?>>
                    I need to <strong>leave</strong> during curfew (emergency / early departure)
                </label>
                <br>
                <label>
                    <input type="radio" name="type" value="checkin" <?= $values['type'] === 'checkin' ? 'checked' : '' ?>>
                    I will <strong>arrive back</strong> during curfew (e.g. a late bus/flight) &mdash; I've already left campus normally
                </label>
                <br><br>

                <label>
                    <span id="destination-label"><?= h(destination_field_label($values['type'])) ?></span>
                    <input type="text" name="destination" maxlength="255" value="<?= h($values['destination']) ?>" required>
                </label>
                <br>
                <label>
                    Reason
                    <input type="text" name="reason" maxlength="255" value="<?= h($values['reason']) ?>" required>
                </label>
                <br>

                <div id="field-checkout" style="display:none;">
                    <label>
                        What time do you need to leave?
                        <input type="datetime-local" name="requested_out_at" value="<?= h($values['requested_out_at']) ?>">
                    </label>
                </div>
                <div id="field-checkin" style="display:none;">
                    <label>
                        What time do you expect to arrive back?
                        <input type="datetime-local" name="expected_return_at" value="<?= h($values['expected_return_at']) ?>">
                    </label>
                </div>
                <br>

                <button type="submit">Submit request</button>
                <a href="my_requests.php">Cancel</a>
            </form>
        </fieldset>
    </main>

    <script>
        // Only the field that matches the chosen type is shown -- and
        // only that field is required, so the other one never blocks
        // submission even though both live in the same form.
        (function () {
            var radios = document.querySelectorAll('input[name="type"]');
            var checkoutField = document.getElementById('field-checkout');
            var checkinField = document.getElementById('field-checkin');
            var checkoutInput = checkoutField.querySelector('input');
            var checkinInput = checkinField.querySelector('input');
            var destinationLabel = document.getElementById('destination-label');

            function sync() {
                var isCheckin = document.querySelector('input[name="type"]:checked').value === 'checkin';
                checkoutField.style.display = isCheckin ? 'none' : 'block';
                checkinField.style.display = isCheckin ? 'block' : 'none';
                checkoutInput.required = !isCheckin;
                checkinInput.required = isCheckin;
                destinationLabel.textContent = isCheckin ? 'Coming from' : 'Destination';
            }

            radios.forEach(function (r) { r.addEventListener('change', sync); });
            sync();
        })();
    </script>
</body>
</html>
