<?php
// The separate special-track report was merged into the violation report
// (one report, one question: who returned late). Kept as a redirect so old
// bookmarks and links still work.
declare(strict_types=1);
header('Location: violation_report.php', true, 301);
exit;
