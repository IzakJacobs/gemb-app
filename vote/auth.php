<?php
// Temporary development login: password only; restore original auth.php to re-enable TOTP.
function authenticate(string $username, string $password): bool {
 global $config;
 $pdo=db();
 // REMOTE_ADDR is deliberate: untrusted forwarded headers cannot reset a limit.
 $buckets=[['account',30],['ip:'.hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'unknown'),10]];
 $pdo->beginTransaction();
 try {
  foreach($buckets as [$bucket,$limit]) {
   $pdo->prepare('INSERT IGNORE INTO quorum_login_limits (bucket,attempts,window_start) VALUES (?,0,UNIX_TIMESTAMP())')->execute([$bucket]);
   $stmt=$pdo->prepare('SELECT attempts,window_start FROM quorum_login_limits WHERE bucket=? FOR UPDATE');$stmt->execute([$bucket]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
   $expired=(int)$row['window_start']<=time()-30;
   if(!$expired && (int)$row['attempts'] >= $limit) {$pdo->rollBack(); return false;}
   $pdo->prepare('UPDATE quorum_login_limits SET attempts=?,window_start=? WHERE bucket=?')->execute([$expired?1:(int)$row['attempts']+1,$expired?time():(int)$row['window_start'],$bucket]);
  }
  $pdo->commit();
 } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 // Always perform the password hash check, even with an incorrect username.
 $passwordOk=password_verify($password,$config['admin_password_hash']);
 return $passwordOk && hash_equals($config['admin_username'],$username);
}

