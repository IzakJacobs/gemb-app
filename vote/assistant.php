<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/capture-core.php';
$error='';
function h($s): string {return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
try {
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Invalid request. Reload.');}
  if(($_POST['action']??'')==='logout'){$_SESSION=[];setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/vote/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);session_destroy();header('Location: assistant.php',true,303);exit;}
  $db=db();$db->beginTransaction();$limited=false;
  $buckets=[['au:'.hash('sha256',strtolower(trim((string)($_POST['username']??'')))),10],['ai:'.hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown'),200]];
  foreach($buckets as [$bucket,$limit]){
   $db->prepare('INSERT IGNORE INTO quorum_login_limits (bucket,attempts,window_start) VALUES (?,0,UNIX_TIMESTAMP())')->execute([$bucket]);
   $s=$db->prepare('SELECT attempts,window_start FROM quorum_login_limits WHERE bucket=? FOR UPDATE');$s->execute([$bucket]);$r=$s->fetch(PDO::FETCH_ASSOC);$expired=(int)$r['window_start']<=time()-30;
   if(!$expired&&(int)$r['attempts']>=$limit){$limited=true;break;}
   $db->prepare('UPDATE quorum_login_limits SET attempts=?,window_start=? WHERE bucket=?')->execute([$expired?1:(int)$r['attempts']+1,$expired?time():(int)$r['window_start'],$bucket]);
  }
  $db->commit();
  $username=trim((string)($_POST['username']??''));$s=$db->prepare('SELECT * FROM quorum_assistants WHERE username=?');$s->execute([$username]);$a=$s->fetch(PDO::FETCH_ASSOC);
  $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
  $ok=password_verify((string)($_POST['password']??''),$a['password_hash']??$dummy);
  if(!$limited&&$a&&$ok&&(int)$a['active']===1&&(int)$a['assigned']===1){
   session_regenerate_id(true);$_SESSION=['authenticated'=>true,'role'=>'assistant','assistant_id'=>(int)$a['id'],'assistant_version'=>(int)$a['version'],'login_at'=>time(),'last_seen'=>time(),'csrf'=>bin2hex(random_bytes(32))];
   cap_audit($db,$a['username'],'ASSISTANT_SIGNED_IN',[],(int)$a['id']);header('Location: assistant.php',true,303);exit;
  }
  $error='Sign-in failed, account unavailable, or temporarily limited. Wait 30 seconds before retrying.';
 }
 $account=assistant_account();
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();error_log('Quorum assistant login: '.$e->getMessage());http_response_code(503);$error='Assistant access unavailable. Contact the administrator.';$account=null;}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quorum · Assistant capture</title>
<style>body{font:16px system-ui;background:#f4f7f5;color:#143e33;margin:0}header,main{max-width:960px;margin:auto;padding:20px}header{display:flex;align-items:center;justify-content:space-between;gap:16px}section{background:white;border:1px solid #d9e2dc;border-radius:12px;padding:20px;margin:16px 0}h1{font-size:24px}label{display:block;margin:14px 0 6px}input,select,button{font:inherit;padding:12px;box-sizing:border-box}input:not([type=checkbox]),select{width:100%;border:1px solid #9cad9f;border-radius:6px}button{cursor:pointer;border:1px solid #174c3e;border-radius:6px;background:#174c3e;color:white}button:disabled{opacity:.5;cursor:wait}button.secondary{background:white;color:#174c3e}#hits button{display:block;width:100%;text-align:left;margin:8px 0}#notice{padding:16px;border-radius:8px;background:#e6eee8;white-space:pre-wrap}#notice.error{background:#ffe3df;color:#7b2018}.row{display:flex;gap:12px;flex-wrap:wrap}.row>*{flex:1}.check{display:flex;gap:10px;align-items:start}small{display:block;color:#59665d}.warning{background:#fff1ce;padding:12px;border-radius:6px}[hidden]{display:none!important}a{color:#174c3e}fieldset{border:0;padding:0}table{width:100%;border-collapse:collapse}td,th{padding:10px;text-align:left;border-bottom:1px solid #ddd}@media(max-width:600px){header{align-items:start}.row{display:block}td,th{padding:6px}}</style></head><body>
<header><div><strong>quorum.</strong><br>Assistant check-in &amp; paper votes</div><?php if($account): ?><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button>Sign off</button></form><?php endif ?></header>
<main><?php if(!$account): ?><section style="max-width:420px;margin:auto"><h1>Assistant sign in</h1><p>Use the account appointed by your administrator.</p><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>"><label for="username">Username</label><input id="username" name="username" maxlength="100" autocomplete="username" required><label for="password">Password</label><input id="password" name="password" type="password" maxlength="72" autocomplete="current-password" required><p role="alert"><?=h($error)?></p><button>Sign in</button></form></section>
<?php else: $_SESSION['last_seen']=time(); ?>
<p>Signed in as <strong><?=h($account['display_name'])?></strong> · <?=h($account['username'])?></p><p id="meeting">Loading meeting…</p><div id="notice" role="status" aria-live="polite">Enter or scan a property number to begin.</div>
<section><form id="search-form"><label for="property-search">Property number, owner name, or address</label><div class="row"><input id="property-search" autocomplete="off" autofocus placeholder="E15339"><button>Find owner</button></div></form><div id="hits"></div></section>
<section id="owner-panel" hidden><h1 id="owner-name"></h1><p id="owner-contact"></p><p id="eligibility"></p><p id="case-status" class="warning" hidden></p><button id="refresh-owner" class="secondary" type="button">Refresh this owner</button>
<h2>Check-in</h2><form id="checkin-form"><fieldset id="checkin-fields"><label for="attendance-mode">Who is at the venue?</label><select id="attendance-mode"><option value="personal">Owner personally present</option><option value="proxy">Proxy holder physically present</option></select><div id="proxy-fields" hidden><label for="proxy-holder">Proxy holder’s full name</label><input id="proxy-holder" maxlength="150"><label for="proxy-ref">Verified proxy-mandate reference</label><input id="proxy-ref" maxlength="150"></div><label class="check"><input id="identity-verified" type="checkbox" required><span>I checked identity and entitlement, including the proxy mandate where applicable.</span></label><p><button>Save check-in &amp; next owner</button></p></fieldset></form>
<h2>Paper votes</h2><form id="paper-form"><fieldset id="paper-fields"><label for="representation">Representation on this paper form</label><select id="representation" required></select><label for="paper-reference">Paper form reference</label><input id="paper-reference" required maxlength="150" autocomplete="off"><p>Capture one motion or several. Leave unvoted motions unselected. Do not guess blank, spoiled, or unclear choices.</p><div id="motion-fields"></div><label class="check"><input id="paper-confirmed" type="checkbox" required><span>I verified these choices against the paper form.</span></label><p><button>Save votes &amp; next owner</button></p></fieldset></form></section>
<script nonce="<?=h($nonce)?>">window.quorumCsrf=<?=json_encode($_SESSION['csrf'])?>;</script><script src="asset.php?name=assistant.js"></script>
<?php endif ?></main></body></html>
