<?php
require __DIR__.'/bootstrap.php';
require __DIR__.'/capture-core.php';
header('Content-Type: application/json; charset=utf-8');
function respond(int $status,array $body): void {http_response_code($status);echo cap_json($body);exit;}
if(!authorized())respond(401,['error'=>'Administrator session required.']);
if(!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))respond(403,['error'=>'Invalid request token.']);
$_SESSION['last_seen']=time();$actor=$config['admin_username'];session_write_close();$db=null;
try {
 $db=db();$db->beginTransaction();
 if($_SERVER['REQUEST_METHOD']==='GET'){
  $row=$db->query('SELECT revision,payload FROM quorum_workspace WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
  if(!$row){$db->commit();respond(200,['revision'=>0,'state'=>null]);}
  $state=cap_clean_state(json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR));cap_migrate($db,$state);$state=cap_projection($db,$state);$db->commit();respond(200,['revision'=>(int)$row['revision'],'state'=>$state]);
 }
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new CaptureError('Method not allowed.',405);
 $raw=file_get_contents('php://input',false,null,0,2000001);if(strlen($raw)>2000000)throw new CaptureError('Workspace exceeds 2 MB.',413);
 $input=json_decode($raw,true,128,JSON_THROW_ON_ERROR);$next=$input['state']??null;$revision=$input['revision']??null;if(is_array($next))$next=cap_clean_state($next);
 if(!is_int($revision)||$revision<0||!is_array($next)||!in_array($next['status']??'', ['draft','live','closed'],true))throw new CaptureError('Invalid workspace or revision.');
 foreach(['owners','motions','ballots','audit'] as $key)if(!is_array($next[$key]??null))throw new CaptureError('Invalid workspace.');
 $ids=[];$units=[];foreach($next['owners'] as $o){if(!is_int($o['id']??null)||isset($ids[$o['id']]))throw new CaptureError('Invalid or duplicate owner ID.');$unit=strtoupper(trim((string)($o['unit']??'')));if($unit===''||isset($units[$unit]))throw new CaptureError('Property numbers must be unique.');$ids[$o['id']]=true;$units[$unit]=true;}
 $ownerIds=[];foreach($next['ballots'] as $b){if(!is_int($b['ownerId']??null)||!isset($ids[$b['ownerId']])||isset($ownerIds[$b['ownerId']])||!is_array($b['choices']??null))throw new CaptureError('Invalid or duplicate owner ballot.');$ownerIds[$b['ownerId']]=true;}
 $row=$db->query('SELECT revision,payload FROM quorum_workspace WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
 if(!$row){if($revision!==0)throw new CaptureError('Workspace changed. Reload.',409);$db->prepare('INSERT INTO quorum_workspace (id,revision,payload) VALUES (1,0,?)')->execute([cap_json($next)]);cap_migrate($db,$next);$result=cap_store($db,0,$next);}
 else {
  if((int)$row['revision']!==$revision)throw new CaptureError('Workspace updated by another operator. Reload to include captured records before saving your edits.',409);
  if($next['status']==='closed'&&(int)$db->query("SELECT COUNT(*) FROM quorum_capture_cases WHERE status='disputed'")->fetchColumn()>0)throw new CaptureError('Resolve all representation disputes before closing the meeting.',409);
  $before=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);cap_migrate($db,$before);$before=cap_projection($db,$before);
  if($before['status']==='closed'&&cap_json($next)!==cap_json($before))throw new CaptureError('The meeting is closed. Records are locked.',409);
  cap_admin_ingest($db,$before,$next,$actor);$result=cap_store($db,$revision,$next);
 }
 $db->commit();respond(200,$result);
}catch(CaptureError $e){if($db&&$db->inTransaction())$db->rollBack();respond($e->status,['error'=>$e->getMessage()]);}
catch(Throwable $e){if($db&&$db->inTransaction())$db->rollBack();error_log('Quorum storage: '.$e->getMessage());respond(503,['error'=>'Storage unavailable. Check the assistant schema upgrade and server logs.']);}
