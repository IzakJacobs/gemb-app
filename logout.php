<?php
require_once __DIR__ . '/db.php';
sessionStart();
$type = $_SESSION['user_type'] ?? 'resident';
session_unset();
session_destroy();

$dest = match($type) {
    'admin' => 'admin_login.php',
    'guard' => 'guard_login.php',
    default  => 'login.php',
};
header('Location: ' . $dest);
exit;
