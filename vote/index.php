<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Invalid request. Reload the page.'); }
 if (($_POST['action'] ?? '') === 'logout') {
  $_SESSION=[];
  setcookie(session_name(), '', ['expires'=>time()-3600,'path'=>'/vote/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
  session_destroy(); header('Location: /vote/',true,303); exit;
 }
 try {
  $ok=authenticate((string)($_POST['username'] ?? ''),(string)($_POST['password'] ?? ''));
  if($ok) {
   session_regenerate_id(true);
   $_SESSION=['authenticated'=>true,'login_at'=>time(),'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32)), 'auth_version'=>hash('sha256',$config['admin_password_hash'].$config['totp_secret'])];
   header('Location: /vote/',true,303); exit;
  }
  $error='Sign-in failed or temporarily limited. Check your details or wait 30 seconds.';
 } catch(Throwable $e) {error_log('Quorum authentication: '.$e->getMessage()); http_response_code(503); $error='Sign-in unavailable. Contact the administrator.';}
}
if (!authorized()): ?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quorum · Sign in</title><style>body{font:16px system-ui;background:#f5f7f5;color:#123f35}label,input{display:block}input{box-sizing:border-box;width:100%;padding:12px;margin:8px 0 20px}button{padding:12px 24px}main{background:white;border-radius:16px}</style><main style="max-width:420px;margin:10vh auto;padding:24px"><h1>Quorum workspace</h1><p>Private demonstration · Managing agent access</p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'], ENT_QUOTES)?>"><div class="field"><label for="username">Username</label><input id="username" name="username" required autocomplete="username" maxlength="100"></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" required autocomplete="current-password" maxlength="1024"></div><p role="alert"><?=htmlspecialchars($error, ENT_QUOTES)?></p><button class="button primary">Sign in</button></form></main></html>
<?php exit; endif;
$_SESSION['last_seen']=time();
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#123f35"><title>Quorum — HOA voting, made clear</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;450;500;550;600;650;700&family=Manrope:wght@400;500;600;650;700;750;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="asset.php?name=style.css"></head>
<body><div id="storage-status" style="position:fixed;bottom:0;left:0;right:0;z-index:9999;background:#123f35;color:white;padding:8px;text-align:center">Loading saved workspace…</div><div id="app"></div><div id="modal-root"></div><div id="toast" role="status"></div><script src="asset.php?name=xlsx.full.min.js"></script><script nonce="<?=htmlspecialchars($nonce, ENT_QUOTES)?>">window.quorumCsrf=<?=json_encode($_SESSION["csrf"])?>;</script><script src="asset.php?name=app.js"></script><script src="asset.php?name=persistence.js"></script></body></html>

