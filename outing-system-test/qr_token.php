<?php
// Returns the CURRENT rotating QR token for the logged-in student
// (kind=S) or for the group they lead or belong to (kind=G). Session-authenticated,
// never cached -- this is what makes a screenshot worthless.
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
require_once PRIVATE_LIB . '/totp.php';
require_once PRIVATE_LIB . '/groups.php';

require_student();
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

$stdNo = $_SESSION['std_no'];
$kind  = ($_GET['kind'] ?? 'S') === 'G' ? 'G' : 'S';

if ($kind === 'G') {
    // Lead OR an accepted member (never a pending/declined invite).
    $group = get_any_active_group_for_student($stdNo);
    if (!$group || count_accepted_members((int) $group['id']) < 1) {
        http_response_code(404);
        echo json_encode(['error' => 'No active group with accepted members.']);
        exit;
    }
    echo json_encode(qr_issue_token('G', (string) $group['id']));
    exit;
}

echo json_encode(qr_issue_token('S', $stdNo));
