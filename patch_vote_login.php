<?php
$file = __DIR__ . '/vote_login.php';
$src  = file_get_contents($file);

$find = 'if (session_status() === PHP_SESSION_NONE) session_start();';

$replace = 'ini_set(\'session.gc_maxlifetime\', 10800);
session_set_cookie_params([\'lifetime\' => 10800, \'path\' => \'/\', \'secure\' => true, \'httponly\' => true, \'samesite\' => \'Lax\']);
if (session_status() === PHP_SESSION_NONE) session_start();';

$count = substr_count($src, $find);
echo "Occurrences found: $count\n";
if ($count === 1) {
    file_put_contents($file, str_replace($find, $replace, $src));
    echo "Patch 1 applied OK.\n";
} else {
    echo "PATCH NOT APPLIED — expected 1 occurrence.\n";
}