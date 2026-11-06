<?php
declare(strict_types=1);
class QmTransportError extends RuntimeException {public string $diagnostic;public bool $definite;public function __construct(string $code,bool $definite){parent::__construct($code);$this->diagnostic=$code;$this->definite=$definite;}}
function qm_config(): array {
 global $configPath;$path=dirname($configPath).'/mail.php';if(!is_file($path))throw new RuntimeException('Private mail.php is missing. Run setup-mail.php.');$c=require $path;
 if(!is_array($c)||!preg_match('/^[a-f0-9]{64}$/D',$c['queue_key']??'')||!filter_var($c['from_email']??'',FILTER_VALIDATE_EMAIL)||!preg_match('~^https://[A-Za-z0-9.-]+(?::[0-9]+)?/vote$~D',$c['base_url']??'')||(int)($c['max_per_hour']??0)<1)throw new RuntimeException('Mail configuration is incomplete.');return $c;
}
function qm_identity(array $o): string {return hash('sha256',json_encode([$o['id'],strtoupper(trim($o['unit'])),trim($o['name']),strtolower(trim($o['email']))],JSON_THROW_ON_ERROR));}
function qm_seal(array $payload,string $id,array $c): string {
 $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($payload,JSON_THROW_ON_ERROR),'aes-256-gcm',hex2bin($c['queue_key']),OPENSSL_RAW_DATA,$iv,$tag,$id,16);
 if($cipher===false)throw new RuntimeException('Cannot protect queued email.');return base64_encode($iv.$tag.$cipher);
}
function qm_open(string $payload,string $id,array $c): array {
 $raw=base64_decode($payload,true);if($raw===false||strlen($raw)<29)throw new RuntimeException('Invalid queued email.');
 $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hex2bin($c['queue_key']),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16),$id);
 if($plain===false)throw new RuntimeException('Cannot decrypt queued email. Keep the original queue_key.');return json_decode($plain,true,32,JSON_THROW_ON_ERROR);
}
function qm_add(PDO $db,array $cfg,string $kind,string $email,array $payload,?int $owner=null,?string $identity=null,?string $invite=null): int {
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))throw new CaptureError('Invalid recipient email.');
 $id='<quorum-'.bin2hex(random_bytes(18)).'@gemb.co.za>';$sealed=qm_seal($payload,$id,$cfg);
 $db->prepare('INSERT INTO quorum_mail_queue (owner_id,kind,recipient,identity_hash,invite_hash,payload,message_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$owner,$kind,$email,$identity,$invite,$sealed,$id,time(),time()]);return (int)$db->lastInsertId();
}
function qm_account(PDO $db,int $owner): ?array {$s=$db->prepare('SELECT * FROM quorum_owner_accounts WHERE owner_id=?');$s->execute([$owner]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
function qm_sync_flags(PDO $db,array $state): array {
 $accounts=[];foreach($db->query('SELECT owner_id,identity_hash,active FROM quorum_owner_accounts')->fetchAll(PDO::FETCH_ASSOC) as $a)$accounts[(int)$a['owner_id']]=$a;
 $invites=[];$meetings=[];$s=$db->prepare('SELECT owner_id,purpose,identity_hash FROM quorum_owner_invites WHERE revoked=0 AND expires_at>?');$s->execute([time()]);foreach($s as $i){$key=$i['owner_id'].':'.$i['identity_hash'];$invites[$key]=true;if($i['purpose']==='meeting')$meetings[$key]=true;}
 foreach($state['owners'] as &$o){$hash=qm_identity($o);$a=$accounts[$o['id']]??null;$o['registered']=$a&&(int)$a['active']===1&&hash_equals($a['identity_hash'],$hash);$o['invited']=$o['registered']||isset($invites[$o['id'].':'.$hash]);$o['meetingInvited']=isset($meetings[$o['id'].':'.$hash]);}unset($o);$state['realOwnerPortal']=true;return $state;
}
function qm_queue_invites(PDO $db,array &$state,string $purpose,array $ownerIds,bool $resend,string $actor): array {
 if(!in_array($purpose,['registration','meeting','reset'],true))throw new CaptureError('Invalid invitation type.');cap_open($state);if($purpose==='meeting')cap_open($state,true);$cfg=qm_config();$queued=0;$skipped=0;
 if(count($ownerIds)>2000||($purpose==='reset'&&count($ownerIds)!==1))throw new CaptureError('Choose one owner for a registration reset.');
 foreach(array_unique($ownerIds) as $id){
  $o=cap_owner($state,(int)$id);if(!cap_eligible($o)||!filter_var($o['email']??'',FILTER_VALIDATE_EMAIL)){$skipped++;continue;}
  $identity=qm_identity($o);$a=qm_account($db,(int)$id);$real=$a&&(int)$a['active']===1&&hash_equals($a['identity_hash'],$identity);
  if(($purpose==='registration'&&$a&&hash_equals($a['identity_hash'],$identity))||($purpose==='meeting'&&!$real)||($purpose==='reset'&&!$a)){$skipped++;continue;}
  $s=$db->prepare('SELECT COUNT(*) FROM quorum_owner_invites WHERE owner_id=? AND purpose=? AND identity_hash=? AND revoked=0 AND expires_at>? AND used_at IS NULL');$s->execute([$id,$purpose,$identity,time()]);if(!$resend&&(int)$s->fetchColumn()>0){$skipped++;continue;}
  $db->prepare('UPDATE quorum_owner_invites SET revoked=1 WHERE owner_id=? AND purpose=? AND revoked=0')->execute([$id,$purpose]);
  $db->prepare("UPDATE quorum_mail_queue SET status='cancelled',payload=NULL,updated_at=? WHERE owner_id=? AND kind=? AND status IN ('queued','failed')")->execute([time(),$id,$purpose]);
  $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$expiry=time()+7*86400;
  $db->prepare('INSERT INTO quorum_owner_invites (token_hash,owner_id,purpose,identity_hash,expires_at) VALUES (?,?,?,?,?)')->execute([$hash,$id,$purpose,$identity,$expiry]);
  $url=$cfg['base_url'].'/owner.php?token='.$token;
  $verb=$purpose==='meeting'?'Sign in to vote':($purpose==='reset'?'Reset your owner registration':'Register to vote');
  $body='Hello '.$o['name'].",\n\n".$state['hoa'].' invites you to '.$state['meeting'].'.'."\nProperty: ".$o['unit']."\n\n".$verb.":\n".$url."\n\nThis personal link expires in 7 days. Do not forward it.\n".($purpose==='meeting'?"Use your owner password; a separate email login code will follow.\n":"Choose your own username and password. No password is sent by email.\n")."\nIf the owner details or proxy authority are incorrect, contact the managing agent.\n";
  qm_add($db,$cfg,$purpose,$o['email'],['subject'=>$verb.' — '.$state['meeting'],'body'=>$body,'expiresAt'=>$expiry],(int)$id,$identity,$hash);$queued++;
 }
 cap_audit($db,$actor,'INVITATIONS_QUEUED',['purpose'=>$purpose,'queued'=>$queued,'skipped'=>$skipped]);$state=qm_sync_flags($db,$state);return ['queued'=>$queued,'skipped'=>$skipped];
}
function qm_invite(PDO $db,array $state,string $hash,bool $forUpdate=false): array {
 if(!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new CaptureError('Invalid or expired invitation.');
 $s=$db->prepare('SELECT * FROM quorum_owner_invites WHERE token_hash=?'.($forUpdate?' FOR UPDATE':''));$s->execute([$hash]);$i=$s->fetch(PDO::FETCH_ASSOC);
 if(!$i||$i['revoked']||(int)$i['expires_at']<=time()||($i['purpose']!=='meeting'&&$i['used_at']!==null))throw new CaptureError('Invitation expired, replaced, or already used. Contact the managing agent.');
 $o=cap_owner($state,(int)$i['owner_id']);if(!cap_eligible($o)||!hash_equals($i['identity_hash'],qm_identity($o)))throw new CaptureError('Owner details or entitlement changed. Contact the managing agent.');return $i;
}
function qm_register(PDO $db,array &$state,string $hash,string $username,string $password,string $confirm,bool $consent): int {
 cap_open($state);$invite=qm_invite($db,$state,$hash,true);if(!in_array($invite['purpose'],['registration','reset'],true))throw new CaptureError('This link is for owner sign-in.');
 $username=trim($username);if(!preg_match('/^[A-Za-z0-9_.@-]{3,100}$/D',$username)||strlen($password)<16||strlen($password)>72||$password!==$confirm||!$consent)throw new CaptureError('Use a 3–100 character username, matching 16–72 character passwords, and confirm you are the invited owner.');
 $id=(int)$invite['owner_id'];$a=qm_account($db,$id);
 if($invite['purpose']==='registration'&&$a&&hash_equals($a['identity_hash'],$invite['identity_hash']))throw new CaptureError('An account already exists. Contact the administrator for a reset.');
 if($invite['purpose']==='reset'&&!$a)throw new CaptureError('Account unavailable. Request a fresh registration invite.');
 $newHash=password_hash($password,PASSWORD_DEFAULT);
 if($a)$db->prepare('UPDATE quorum_owner_accounts SET username=?,password_hash=?,identity_hash=?,active=1,version=version+1 WHERE owner_id=?')->execute([$username,$newHash,$invite['identity_hash'],$id]);
 else $db->prepare('INSERT INTO quorum_owner_accounts (owner_id,username,password_hash,identity_hash) VALUES (?,?,?,?)')->execute([$id,$username,$newHash,$invite['identity_hash']]);
 $db->prepare('UPDATE quorum_owner_invites SET used_at=? WHERE token_hash=?')->execute([time(),$hash]);
 $db->prepare('UPDATE quorum_owner_otp SET used_at=? WHERE owner_id=? AND used_at IS NULL')->execute([time(),$id]);
 cap_audit($db,'Owner '.$id,$a?'OWNER_ACCOUNT_RESET':'OWNER_REGISTERED',['ownerId'=>$id]);$state=qm_sync_flags($db,$state);return $id;
}
function qm_limit(PDO $db,array $buckets,int $window=300): bool {
 $db->beginTransaction();try{
  foreach($buckets as [$key,$max]){$bucket='ow:'.hash('sha256',$key);$db->prepare('INSERT IGNORE INTO quorum_login_limits (bucket,attempts,window_start) VALUES (?,0,?)')->execute([$bucket,time()]);$s=$db->prepare('SELECT attempts,window_start FROM quorum_login_limits WHERE bucket=? FOR UPDATE');$s->execute([$bucket]);$r=$s->fetch(PDO::FETCH_ASSOC);$expired=(int)$r['window_start']<=time()-$window;if(!$expired&&(int)$r['attempts']>=$max){$db->rollBack();return false;}$db->prepare('UPDATE quorum_login_limits SET attempts=?,window_start=? WHERE bucket=?')->execute([$expired?1:(int)$r['attempts']+1,$expired?time():(int)$r['window_start'],$bucket]);}
  $db->commit();return true;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function qm_issue_otp(PDO $db,array $state,array $account,string $sessionId): string {
 $o=cap_owner($state,(int)$account['owner_id']);$cfg=qm_config();
 if(!cap_eligible($o)||!hash_equals($account['identity_hash'],qm_identity($o)))throw new CaptureError('Owner access unavailable.');
 $s=$db->prepare("SELECT COUNT(*) FROM quorum_owner_invites WHERE owner_id=? AND purpose='meeting' AND revoked=0 AND expires_at>? AND identity_hash=?");$s->execute([$o['id'],time(),qm_identity($o)]);if(!(int)$s->fetchColumn()||$state['status']!=='live')throw new CaptureError('Meeting access is not open for this owner. Contact the managing agent.');
 $code=(string)random_int(100000,999999);$challenge=bin2hex(random_bytes(32));$hash=hash('sha256',$challenge);$expiry=time()+300;
 $db->prepare('UPDATE quorum_owner_otp SET used_at=? WHERE owner_id=? AND used_at IS NULL')->execute([time(),$o['id']]);
 $db->prepare("UPDATE quorum_mail_queue SET status='cancelled',payload=NULL,updated_at=? WHERE owner_id=? AND kind='otp' AND status IN ('queued','failed')")->execute([time(),$o['id']]);
 $db->prepare('INSERT INTO quorum_owner_otp (challenge_hash,owner_id,code_hash,session_hash,identity_hash,account_version,expires_at) VALUES (?,?,?,?,?,?,?)')->execute([$hash,$o['id'],hash_hmac('sha256',$hash.':'.$code,hex2bin($cfg['queue_key'])),hash('sha256',$sessionId),qm_identity($o),$account['version'],$expiry]);
 qm_add($db,$cfg,'otp',$o['email'],['subject'=>'Your Quorum sign-in code','body'=>"Your Quorum sign-in code is: ".$code."\n\nValid for 5 minutes. Do not share this code. If you did not request it, ignore this email.",'expiresAt'=>$expiry],$o['id'],qm_identity($o));return $challenge;
}
function qm_verify_otp(PDO $db,array $state,string $challenge,string $code,string $sessionId): ?array {
 $hash=hash('sha256',$challenge);$s=$db->prepare('SELECT * FROM quorum_owner_otp WHERE challenge_hash=? FOR UPDATE');$s->execute([$hash]);$r=$s->fetch(PDO::FETCH_ASSOC);
 if(!$r||$r['used_at']!==null||(int)$r['expires_at']<=time()||(int)$r['attempts']>=5||!hash_equals($r['session_hash'],hash('sha256',$sessionId)))return null;
 $db->prepare('UPDATE quorum_owner_otp SET attempts=attempts+1 WHERE challenge_hash=?')->execute([$hash]);$cfg=qm_config();
 if(!preg_match('/^[0-9]{6}$/D',$code)||!hash_equals($r['code_hash'],hash_hmac('sha256',$hash.':'.$code,hex2bin($cfg['queue_key']))))return null;
 $a=qm_account($db,(int)$r['owner_id']);$o=cap_owner($state,(int)$r['owner_id']);
 if(!$a||!$a['active']||(int)$a['version']!==(int)$r['account_version']||!cap_eligible($o)||!hash_equals($r['identity_hash'],qm_identity($o))||$state['status']!=='live')return null;
 $db->prepare('UPDATE quorum_owner_otp SET used_at=? WHERE challenge_hash=?')->execute([time(),$hash]);return $a;
}
function qm_owner_session(PDO $db,array $state): ?array {
 if(!session_live()||($_SESSION['role']??'')!=='owner')return null;$a=qm_account($db,(int)($_SESSION['owner_id']??0));if(!$a||!$a['active']||(int)$a['version']!==(int)($_SESSION['owner_version']??0))return null;
 try{$o=cap_owner($state,(int)$a['owner_id']);}catch(Throwable $e){return null;}return cap_eligible($o)&&hash_equals($a['identity_hash'],qm_identity($o))?$o:null;
}
function qm_smtp(array $row,array $payload,array $cfg): void {
 global $configPath;$dir=dirname($configPath).'/vendor/PHPMailer/';foreach(['Exception','PHPMailer','SMTP'] as $class)require_once $dir.$class.'.php';
 $mail=new PHPMailer\PHPMailer\PHPMailer(true);$mail->isSMTP();$mail->Host=$cfg['host'];$mail->Port=(int)$cfg['port'];$mail->SMTPSecure=PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;$mail->SMTPAuth=true;$mail->Username=$cfg['username'];$mail->Password=$cfg['password'];$mail->Timeout=15;$mail->getSMTPInstance()->Timelimit=20;$mail->SMTPDebug=0;
 if(!empty($cfg['ca_file']))$mail->SMTPOptions=['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'cafile'=>$cfg['ca_file']]];
 $mail->CharSet='UTF-8';$mail->XMailer='';$mail->setFrom($cfg['from_email'],$cfg['from_name']);$mail->addAddress($row['recipient']);$mail->Subject=$payload['subject'];$mail->Body=$payload['body'];$mail->MessageID=$row['message_id'];
 try{$mail->send();}catch(Throwable $e){$err=$mail->getSMTPInstance()->getError();$code=(int)($err['smtp_code']??0);if(preg_match('/authenticat/i',$mail->ErrorInfo))throw new QmTransportError('SMTP_AUTH_REJECTED',true);if(in_array($code,[534,535,530],true))throw new QmTransportError('SMTP_AUTH_REJECTED',true);if($code>=500&&$code<600)throw new QmTransportError('SMTP_REJECTED_'.$code,true);if(preg_match('/certificate|crypto|TLS/i',$mail->ErrorInfo))throw new QmTransportError('TLS_CONNECTION_FAILED',true);throw new QmTransportError('SMTP_FAILED_OR_UNCERTAIN',false);}
}
function qm_process(PDO $db,array $cfg,?callable $sender=null): array {
 if(empty($cfg['enabled']))return ['disabled'=>true,'sent'=>0];$sender=$sender??'qm_smtp';$sent=0;$failed=0;$uncertain=0;$start=microtime(true);
 $db->prepare("UPDATE quorum_mail_queue SET status='uncertain',error_code='WORKER_INTERRUPTED',updated_at=? WHERE status='sending' AND updated_at<?")->execute([time(),time()-300]);
 for($n=0;$n<min(10,max(1,(int)$cfg['batch_size']));$n++){
  if(microtime(true)-$start>40)break;$db->beginTransaction();
  try{
   [, $state]=cap_lock($db);
   $s=$db->prepare('SELECT COUNT(*) FROM quorum_mail_attempts WHERE attempted_at>?');$s->execute([time()-3600]);$hourly=(int)$s->fetchColumn();if($hourly>=(int)$cfg['max_per_hour']){$db->commit();break;}
   $q=$db->query("SELECT * FROM quorum_mail_queue WHERE status='queued' AND attempts<3 ORDER BY CASE kind WHEN 'otp' THEN 0 WHEN 'test' THEN 1 ELSE 2 END,id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
   if(!$q){$db->commit();break;}
   $reserve=min(max(0,(int)($cfg['otp_reserve']??30)),max(0,(int)$cfg['max_per_hour']-1));
   if($q['kind']!=='otp'&&$hourly>=(int)$cfg['max_per_hour']-$reserve){$db->commit();break;}
   $payload=qm_open($q['payload'],$q['message_id'],$cfg);$valid=(int)($payload['expiresAt']??PHP_INT_MAX)>time();
   if($q['owner_id']!==null){try{$o=cap_owner($state,(int)$q['owner_id']);$valid=$valid&&cap_eligible($o)&&hash_equals((string)$q['identity_hash'],qm_identity($o));}catch(Throwable $e){$valid=false;}$valid=$valid&&$state['status']!=='closed';if(in_array($q['kind'],['otp','meeting'],true))$valid=$valid&&$state['status']==='live';if($q['invite_hash']!==null){try{qm_invite($db,$state,$q['invite_hash']);}catch(Throwable $e){$valid=false;}}}
   if(!$valid){$db->prepare("UPDATE quorum_mail_queue SET status='cancelled',payload=NULL,error_code='EXPIRED_OR_CHANGED',updated_at=? WHERE id=?")->execute([time(),$q['id']]);$db->commit();continue;}
   $db->prepare("UPDATE quorum_mail_queue SET status='sending',attempts=attempts+1,updated_at=? WHERE id=?")->execute([time(),$q['id']]);$db->prepare('INSERT INTO quorum_mail_attempts (queue_id,attempted_at) VALUES (?,?)')->execute([$q['id'],time()]);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  try{$sender($q,$payload,$cfg);$db->prepare("UPDATE quorum_mail_queue SET status='sent',payload=NULL,error_code=NULL,updated_at=? WHERE id=?")->execute([time(),$q['id']]);$sent++;}
  catch(Throwable $e){$status=$e instanceof QmTransportError&&$e->definite?'failed':'uncertain';$code=$e instanceof QmTransportError?$e->diagnostic:'SMTP_FAILED_OR_UNCERTAIN';$db->prepare('UPDATE quorum_mail_queue SET status=?,error_code=?,updated_at=? WHERE id=?')->execute([$status,$code,time(),$q['id']]);if($status==='uncertain')$uncertain++;else $failed++;error_log('Quorum SMTP: '.$code.' for queue '.$q['id']);}
 }
 return ['sent'=>$sent,'failed'=>$failed,'uncertain'=>$uncertain];
}



