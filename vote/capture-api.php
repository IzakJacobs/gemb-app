<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/capture-core.php';
header('Content-Type: application/json; charset=utf-8');
function capture_reply(int $status,array $body): void {http_response_code($status);echo cap_json($body);exit;}
$db=null;
try {
 $admin=authorized();$staff=$admin?null:assistant_account();
 if(!$admin&&!$staff)capture_reply(401,['error'=>'Session expired or account disabled. Sign in again.']);
 if(!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))capture_reply(403,['error'=>'Invalid request token. Reload.']);
 $actor=$admin?$config['admin_username']:$staff['username'];$staffId=$staff?(int)$staff['id']:null;
 $version=$staff?(int)$staff['version']:null;$_SESSION['last_seen']=time();session_write_close();
 $db=db();$db->beginTransaction();[$revision,$state]=cap_lock($db);
 if($staffId!==null){$s=$db->prepare('SELECT active,assigned,version FROM quorum_assistants WHERE id=? FOR UPDATE');$s->execute([$staffId]);$a=$s->fetch(PDO::FETCH_ASSOC);if(!$a||!$a['active']||!$a['assigned']||(int)$a['version']!==$version)throw new CaptureError('Assistant account disabled or changed. Sign in again.',401);}
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $action=$_GET['action']??'meta';
  if($action==='meta')$result=['meeting'=>$state['meeting'],'status'=>$state['status'],'motions'=>array_map(fn($m)=>['id'=>$m['id'],'title'=>$m['title']],$state['motions'])];
  elseif($action==='owner')$result=['owner'=>cap_public_owner($db,$state,(int)($_GET['id']??0))];
  elseif($action==='search'){
   $q=cap_text((string)($_GET['q']??''),150);if($q==='')throw new CaptureError('Enter a property number, owner name, or address.');
   $hits=array_values(array_filter($state['owners'],fn($o)=>stripos((string)$o['unit'],$q)!==false||stripos((string)$o['name'],$q)!==false||stripos((string)($o['address']??''),$q)!==false));
   usort($hits,fn($a,$b)=>(strcasecmp((string)$b['unit'],$q)===0?1:0)<=>(strcasecmp((string)$a['unit'],$q)===0?1:0));
   $result=['owners'=>array_map(fn($o)=>['id'=>$o['id'],'unit'=>$o['unit'],'name'=>$o['name'],'address'=>$o['address']??'','phone'=>$o['phone']??'','eligible'=>cap_eligible($o)],array_slice($hits,0,25)),'more'=>count($hits)>25];
  }else throw new CaptureError('Unknown action.',404);
  $db->commit();capture_reply(200,$result);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new CaptureError('Method not allowed.',405);
 $raw=file_get_contents('php://input',false,null,0,32769);if(strlen($raw)>32768)throw new CaptureError('Capture request too large.',413);
 $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$requestId=$input['requestId']??'';
 if(!is_string($requestId)||!preg_match('/^[a-zA-Z0-9-]{16,64}$/D',$requestId))throw new CaptureError('Missing capture request identifier. Reload.');
 $hash=hash('sha256',cap_json($input));
 if($staffId!==null){$s=$db->prepare('SELECT request_hash,response FROM quorum_capture_requests WHERE assistant_id=? AND request_id=?');$s->execute([$staffId,$requestId]);if($prior=$s->fetch(PDO::FETCH_ASSOC)){if(!hash_equals($prior['request_hash'],$hash))throw new CaptureError('Request identifier reused with different data.',409);$result=json_decode($prior['response'],true);$db->commit();capture_reply(200,$result);}}
 if(($input['action']??'')==='checkin')$result=cap_checkin($db,$state,$input,$actor,$staffId);
 elseif(($input['action']??'')==='votes'&&!empty($input['electronic']))throw new CaptureError('Electronic voting requires the authenticated owner portal.',403);
 elseif(($input['action']??'')==='votes')$result=cap_record_votes($db,$state,$input,$actor,$staffId,!empty($input['electronic'])&&$admin);
 elseif(($input['action']??'')==='checkout'&&$admin){cap_open($state);$claim=cap_claim($db,(int)($input['claimId']??0));$reason=cap_text($input['reason']??'',300);if($reason==='')throw new CaptureError('Checkout reason required.');$claim['active']=false;$claim['checkedOutAt']=cap_time();$claim['checkedOutBy']=$actor;$claim['checkoutReason']=$reason;cap_write_claim($db,$claim);cap_audit($db,$actor,'ATTENDANCE_CHECKED_OUT',['claimId'=>$claim['claimId'],'reason'=>$reason]);$result=['message'=>'Attendance checked out. Submitted votes retained.'];}
 else throw new CaptureError('Action not allowed.',403);
 cap_store($db,$revision,$state);
 if($staffId!==null)$db->prepare('INSERT INTO quorum_capture_requests (assistant_id,request_id,request_hash,response) VALUES (?,?,?,?)')->execute([$staffId,$requestId,$hash,cap_json($result)]);
 $db->commit();capture_reply(200,$result);
}catch(CaptureError $e){if($db&&$db->inTransaction())$db->rollBack();capture_reply($e->status,['error'=>$e->getMessage()]);}
catch(Throwable $e){if($db&&$db->inTransaction())$db->rollBack();error_log('Quorum capture: '.$e->getMessage());capture_reply(503,['error'=>'Capture unavailable. Keep the paper form and contact the administrator.']);}
