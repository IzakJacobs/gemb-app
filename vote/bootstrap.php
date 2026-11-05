<?php
declare(strict_types=1);
ini_set('display_errors', '0');
if (empty($_SERVER['HTTPS']) || strtolower((string)$_SERVER['HTTPS']) === 'off') { http_response_code(403); exit('HTTPS is required.'); }
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name('quorum_vote');
session_set_cookie_params(['lifetime'=>0, 'path'=>'/vote/', 'secure'=>true, 'httponly'=>true, 'samesite'=>'Strict']);
session_start();
header('Cache-Control: no-store, private, max-age=0');
header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-" . ($nonce=base64_encode(random_bytes(18))) . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; connect-src 'self'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; object-src 'none'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Content-Type-Options: nosniff');
header('Strict-Transport-Security: max-age=31536000');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
$configPath = dirname(__DIR__, 2) . '/quorum-private/config.php';
if (!is_file($configPath)) { http_response_code(503); exit('Configuration missing. Follow DEPLOYMENT.md.'); }
$config = require $configPath;
if (strlen($config['admin_username'] ?? '') < 3 || strlen($config['totp_secret'] ?? '') < 32 || !preg_match('/^[A-Z2-7]+$/D', $config['totp_secret']) || empty(password_get_info($config['admin_password_hash'] ?? '')['algo'])) {
 http_response_code(503); exit('Secure login configuration is incomplete.');
}
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function authorized(): bool { global $config; return !empty($_SESSION['authenticated']) && ($_SESSION['last_seen'] ?? 0) > time()-900 && ($_SESSION['login_at'] ?? 0) > time()-28800 && hash_equals($_SESSION['auth_version'] ?? '', hash('sha256', $config['admin_password_hash'] . $config['totp_secret'])); }
function db(): PDO {
 global $config;
 return new PDO($config['dsn'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
}
