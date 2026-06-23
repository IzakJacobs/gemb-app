<?php
$file = __DIR__ . '/vote_cast.php';
$src  = file_get_contents($file);

// Part A — extend session lifetime
$find = 'if (session_status() === PHP_SESSION_NONE) session_start();';
$replace = 'ini_set(\'session.gc_maxlifetime\', 10800);
session_set_cookie_params([\'lifetime\' => 10800, \'path\' => \'/\', \'secure\' => true, \'httponly\' => true, \'samesite\' => \'Lax\']);
if (session_status() === PHP_SESSION_NONE) session_start();';

$countA = substr_count($src, $find);
echo "Part A occurrences: $countA\n";

// Part B — refresh activity on every load
$find2 = 'requireVoteSession();';
$replace2 = 'requireVoteSession();
$_SESSION[\'vote_last_activity\'] = time(); // keep session alive during meeting';

$countB = substr_count($src, $find2);
echo "Part B occurrences: $countB\n";

if ($countA === 1 && $countB === 1) {
    $src = str_replace($find, $replace, $src);
    $src = str_replace($find2, $replace2, $src);
    file_put_contents($file, $src);
    echo "Patch 2 applied OK.\n";
} else {
    echo "PATCH NOT APPLIED — check counts above.\n";
}