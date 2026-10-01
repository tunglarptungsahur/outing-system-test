<?php
// Merged into outing_history.php (Standard/Special toggle). Kept as a
// redirect so old bookmarks and links still work.
declare(strict_types=1);
$qs = $_GET;
$qs['view'] = 'special';
header('Location: outing_history.php?' . http_build_query($qs), true, 301);
exit;
