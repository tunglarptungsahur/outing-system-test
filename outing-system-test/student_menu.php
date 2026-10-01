<?php
// Student side panel. Every student page already requires
// student_header.php, which pulls this in, so no page needs changing.
// The profile dropdown keeps only "My profile" + Logout (same as staff).

declare(strict_types=1);

$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
$studentMenu = [
    'student_dashboard.php' => 'Dashboard',
    'my_qr.php'             => 'My QR code',
    'my_history.php'        => 'My outing history',
    'my_group.php'          => 'My group',
    'my_requests.php'       => 'Special requests (curfew)',
];
// Request create/edit pages belong to "Special requests".
if (in_array($current, ['edit_request.php', 'new_request.php'], true)) {
    $current = 'my_requests.php';
}
?>
<div id="m_aside_left" class="m-grid__item m-aside-left m-aside-left--skin-dark">
    <div id="m_ver_menu" class="m-aside-menu m-aside-menu--skin-dark" m-menu-vertical="1">
        <ul class="m-menu__nav">
            <?php foreach ($studentMenu as $href => $label): ?>
            <li class="m-menu__item<?= $current === $href ? ' m-menu__item--active' : '' ?>" aria-haspopup="true">
                <a href="<?= h($href) ?>" class="m-menu__link"<?= $current === $href ? ' aria-current="page"' : '' ?>>
                    <span class="m-menu__link-text"><?= h($label) ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
