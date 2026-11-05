<?php
// Included by index.php only; Apache denies direct access.
function totpCounter(string $secret, string $code): ?int {
 if (!preg_match('/^[0-9]{6}$/D', $code)) return null;
 $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits=''; $key='';
 foreach (str_split($secret) as $char) $bits.=str_pad(decbin(strpos($alphabet,$char)),5,'0',STR_PAD_LEFT);
 for($i=0;$i+8<=strlen($bits);$i+=8) $key.=chr(bindec(substr($bits,$i,8)));
 $now=intdiv(time(),30);
 foreach ([$now-1,$now,$now+1] as $counter) {
  $digest=hash_hmac('sha1',pack('N2',0,$counter),$key,true);
  $offset=ord($digest[19]) & 15;
  $value=(unpack('N',substr($digest,$offset,4))[1] & 0x7fffffff) % 1000000;
  if(hash_equals(str_pad((string)$value,6,'0',STR_PAD_LEFT),$code)) return $counter;
 }
 return null;
}
function authenticate(string $username, string $password, string $code): bool {
 global $config;
 $pdo=db();
 // REMOTE_ADDR is deliberate: untrusted forwarded headers cannot reset a limit.
 $buckets=[['account',30],['ip:'.hash('sha256',$_SERVER['REMOTE_ADDR'] ?? 'unknown'),10]];
 $pdo->beginTransaction();
 try {
  foreach($buckets as [$bucket,$limit]) {
   $pdo->prepare('INSERT IGNORE INTO quorum_login_limits (bucket,attempts,window_start) VALUES (?,0,UNIX_TIMESTAMP())')->execute([$bucket]);
   $stmt=$pdo->prepare('SELECT attempts,window_start FROM quorum_login_limits WHERE bucket=? FOR UPDATE');$stmt->execute([$bucket]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
   $expired=(int)$row['window_start']<=time()-900;
   if(!$expired && (int)$row['attempts'] >= $limit) {$pdo->rollBack(); return false;}
   $pdo->prepare('UPDATE quorum_login_limits SET attempts=?,window_start=? WHERE bucket=?')->execute([$expired?1:(int)$row['attempts']+1,$expired?time():(int)$row['window_start'],$bucket]);
  }
  $pdo->commit();
 } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
 // Always perform the password hash check, even with an incorrect username.
 $passwordOk=password_verify($password,$config['admin_password_hash']);
 $counter=totpCounter($config['totp_secret'],$code);
 if(!$passwordOk || !hash_equals($config['admin_username'],$username) || $counter===null)return false;
 // Atomically reject previously accepted codes, including across browser sessions.
 $stmt=$pdo->prepare('UPDATE quorum_auth_state SET last_totp_counter=? WHERE id=1 AND last_totp_counter<?');
 $stmt->execute([$counter,$counter]);
 return $stmt->rowCount()===1;
}
