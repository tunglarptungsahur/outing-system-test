<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once PRIVATE_LIB . '/auth.php';
start_secure_session();
logout();
header('Location: login_staff.php');
exit;
