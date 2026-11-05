<?php
declare(strict_types=1);
// Included only by authenticated endpoints. All writes lock workspace row 1 first.
class CaptureError extends RuntimeException {public int $status; public function __construct(string $message,int $status=422){parent::__construct($message);$this->status=$status;}}
function cap_json($x): string {return json_encode($x,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);}
function cap_time(): string {return gmdate('Y-m-d\TH:i:s\Z');}
function cap_text($x,int $max=150): string {if(!is_string($x)||strlen($x)>$max)throw new CaptureError('Invalid or overlong text.');return trim($x);}
function cap_audit(PDO $db,string $actor,string $action,array $details,?int $staff=null): void {
 $db->prepare('INSERT INTO quorum_capture_audit (assistant_id,actor,action,details) VALUES (?,?,?,?)')->execute([$staff,$actor,$action,cap_json($details)]);
}
function cap_owner(array $state,int $id): array {foreach($state['owners'] as $o)if($o['id']===$id)return $o;throw new CaptureError('Owner not found.',404);}
function cap_eligible(array $o): bool {return ($o['approved']??false)===true;}
function cap_clean_state(array $state): array {
 $strip=function($x) use (&$strip){if(!is_array($x))return $x;$out=[];foreach($x as $k=>$v){$key=strtolower(str_replace([' ','-','_'],'',(string)$k));if(in_array($key,['balance','levybalance','accountbalance','outstandingbalance','arrears'],true))continue;$out[$k]=$strip($v);}return $out;};
 $state=$strip($state);
 foreach(($state['audit']??[]) as $i=>$event)if(preg_match('/levy|balance|arrears/i',(string)($event['details']??'')))$state['audit'][$i]['details']='Legacy financial details removed; original event type and actor retained.';
 return $state;
}
function cap_open(array $state,bool $voting=false): void {if(($state['status']??'')==='closed'||($voting&&($state['status']??'')!=='live'))throw new CaptureError('Meeting is closed to this action.',409);}
function cap_lock(PDO $db): array {
 $row=$db->query('SELECT revision,payload FROM quorum_workspace WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
 if(!$row)throw new CaptureError('Administrator must open and save the meeting workspace first.',409);
 $state=cap_clean_state(json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR));
 cap_migrate($db,$state);
 return [(int)$row['revision'],$state];
}
function cap_claims(PDO $db): array {
 $out=[];foreach($db->query('SELECT id,owner_id,authority_key,payload FROM quorum_capture_claims ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r){$p=json_decode($r['payload'],true,512,JSON_THROW_ON_ERROR);$p['claimId']=(int)$r['id'];$p['ownerId']=(int)$r['owner_id'];$p['authorityKey']=$r['authority_key'];$out[]=$p;}return $out;
}
function cap_votes(PDO $db): array {return $db->query('SELECT * FROM quorum_capture_votes ORDER BY claim_id,motion_id')->fetchAll(PDO::FETCH_ASSOC);}
function cap_case(PDO $db,int $ownerId): ?array {$s=$db->prepare('SELECT * FROM quorum_capture_cases WHERE owner_id=?');$s->execute([$ownerId]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
function cap_claim(PDO $db,int $id): array {foreach(cap_claims($db) as $c)if($c['claimId']===$id)return $c;throw new CaptureError('Representation not found.',404);}
function cap_key(string $mode,string $holder,string $reference): string {return $mode==='proxy'?hash('sha256',strtolower(trim($holder)).'|'.strtolower(trim($reference))):'owner';}
function cap_write_claim(PDO $db,array $c): void {
 $id=$c['claimId'];$owner=$c['ownerId'];$key=$c['authorityKey'];unset($c['claimId'],$c['ownerId'],$c['authorityKey']);
 $db->prepare('UPDATE quorum_capture_claims SET payload=? WHERE id=?')->execute([cap_json($c),$id]);
}
function cap_new_claim(PDO $db,int $ownerId,array $a): array {
 $key=cap_key($a['mode'],$a['holder']??'',$a['reference']??'');
 $s=$db->prepare('SELECT id,payload FROM quorum_capture_claims WHERE owner_id=? AND authority_key=?');$s->execute([$ownerId,$key]);$r=$s->fetch(PDO::FETCH_ASSOC);
 if($r){$c=json_decode($r['payload'],true);return $c+['claimId'=>(int)$r['id'],'ownerId'=>$ownerId,'authorityKey'=>$key];}
 $db->prepare('INSERT INTO quorum_capture_claims (owner_id,authority_key,payload) VALUES (?,?,?)')->execute([$ownerId,$key,cap_json($a)]);
 return $a+['claimId'=>(int)$db->lastInsertId(),'ownerId'=>$ownerId,'authorityKey'=>$key];
}
function cap_participants(PDO $db,int $ownerId): array {
 $votes=[];foreach(cap_votes($db) as $v)$votes[(int)$v['claim_id']]=true;
 return array_values(array_filter(cap_claims($db),fn($c)=>$c['ownerId']===$ownerId&&(!empty($c['active'])||isset($votes[$c['claimId']]))));
}
function cap_reconcile(PDO $db,int $ownerId,string $actor,?int $staff=null): bool {
 $cs=cap_participants($db,$ownerId);$case=cap_case($db,$ownerId);
 if(count($cs)<2)return $case&&$case['status']==='disputed';
 if($case)return $case['status']==='disputed';
 $db->prepare("INSERT INTO quorum_capture_cases (owner_id,status,winner,reason,actor,decided_at) VALUES (?,'disputed',NULL,?,?,?)")->execute([$ownerId,'Competing owner/proxy representation requires administrator decision.',$actor,cap_time()]);
 cap_audit($db,$actor,'REPRESENTATION_DISPUTED',['ownerId'=>$ownerId,'claims'=>array_column($cs,'claimId')],$staff);
 return true;
}
function cap_reopen_for_new_claim(PDO $db,int $ownerId,string $actor,?int $staff): void {
 $case=cap_case($db,$ownerId);
 if($case){$db->prepare("UPDATE quorum_capture_cases SET status='disputed',winner=NULL,reason=?,actor=?,decided_at=? WHERE owner_id=?")->execute(['New competing representation received.',$actor,cap_time(),$ownerId]);cap_audit($db,$actor,'REPRESENTATION_REOPENED',['ownerId'=>$ownerId],$staff);}
}
function cap_paper_reference(PDO $db,string $reference,int $ownerId): void {
 if($reference==='')throw new CaptureError('A paper form reference is required.');
 $s=$db->prepare('SELECT owner_id FROM quorum_paper_references WHERE reference=?');$s->execute([$reference]);$r=$s->fetchColumn();
 if($r!==false&&(int)$r!==$ownerId)throw new CaptureError('This paper form reference belongs to another owner.',409);
 if($r===false)$db->prepare('INSERT INTO quorum_paper_references (reference,owner_id) VALUES (?,?)')->execute([$reference,$ownerId]);
}
function cap_migrate(PDO $db,array $state): void {
 $state=cap_clean_state($state);
 if($db->query('SELECT id FROM quorum_capture_seed WHERE id=1')->fetchColumn()!==false)return;
 // A non-financial pre-upgrade snapshot remains available for recovery and audit.
 $db->prepare('INSERT INTO quorum_capture_seed (id,payload) VALUES (1,?)')->execute([cap_json($state)]);
 $mapping=[];
 foreach(($state['attendance']??[]) as $a){
  $id=(int)$a['ownerId'];$c=cap_new_claim($db,$id,$a);$mapping[$a['id']]=$c['claimId'];
  // Current representation fields follow the latest record for this authority.
  $a+=['claimId'=>$c['claimId'],'ownerId'=>$id,'authorityKey'=>$c['authorityKey']];cap_write_claim($db,$a);
 }
 foreach(($state['ballots']??[]) as $b){
  $id=(int)$b['ownerId'];
  if(isset($b['attendanceId'],$mapping[$b['attendanceId']]))$c=cap_claim($db,$mapping[$b['attendanceId']]);
  else {$mode=!empty($b['proxyHolder'])?'proxy':'electronic';$c=cap_new_claim($db,$id,['id'=>'legacy-'.$id,'mode'=>$mode,'holder'=>$b['proxyHolder']??'','reference'=>$b['paperReference']??'','active'=>false,'checkedInAt'=>$b['time']??cap_time(),'checkedInBy'=>'Legacy import']);}
  foreach($b['choices'] as $v){$db->prepare('INSERT INTO quorum_capture_votes (claim_id,motion_id,choice,channel,paper_reference,actor,captured_at) VALUES (?,?,?,?,?,?,?)')->execute([$c['claimId'],(int)$v['motionId'],$v['choice'],$b['channel']??'existing',$b['paperReference']??null,$b['capturedBy']??'Legacy import',$b['time']??cap_time()]);}
  if(!empty($b['paperReference']))cap_paper_reference($db,$b['paperReference'],$id);
 }
 foreach(array_unique(array_column(cap_claims($db),'ownerId')) as $id)cap_reconcile($db,(int)$id,'Migration');
 cap_audit($db,'Migration','CAPTURE_MIGRATED',['owners'=>count($state['owners']),'ballots'=>count($state['ballots'])]);
}
function cap_projection(PDO $db,array $state): array {
 $claims=cap_claims($db);$votes=cap_votes($db);$cases=[];
 foreach($db->query('SELECT * FROM quorum_capture_cases')->fetchAll(PDO::FETCH_ASSOC) as $r)$cases[(int)$r['owner_id']]=$r;
 $byOwner=[];foreach($claims as $c)$byOwner[$c['ownerId']][]=$c;
 $state['attendance']=[];$state['ballots']=[];$state['captureDisputes']=[];
 foreach($byOwner as $oid=>$cs){
  $case=$cases[$oid]??null;$disputed=$case&&$case['status']==='disputed';
  if($disputed)$state['captureDisputes'][]=['ownerId'=>$oid,'reason'=>$case['reason']];
  foreach($cs as $c){
   $allowed=!$case||($case['status']==='resolved'&&$case['winner']!==null&&(int)$case['winner']===$c['claimId']);
   $record=$c;$record['active']=!empty($c['active'])&&$allowed;$record['disputed']=$disputed;$record['rejected']=!$allowed&&!$disputed;
   $state['attendance'][]=$record;
   if(!$allowed)continue;
   $vs=array_values(array_filter($votes,fn($v)=>(int)$v['claim_id']===$c['claimId']));
   if(!$vs)continue;
   $first=$vs[0];$channels=array_unique(array_column($vs,'channel'));
   $state['ballots'][]=['ownerId'=>$oid,'claimId'=>$c['claimId'],'channel'=>count($channels)===1?$first['channel']:'mixed','paperReference'=>$first['paper_reference'],'capturedBy'=>$first['actor'],'time'=>$first['captured_at'],'receipt'=>'CAP-'.$c['claimId'],'attendanceId'=>$c['id'],'proxyHolder'=>$c['holder']??null,'choices'=>array_map(fn($v)=>['motionId'=>(int)$v['motion_id'],'choice'=>$v['choice'],'channel'=>$v['channel'],'paperReference'=>$v['paper_reference'],'capturedBy'=>$v['actor'],'capturedAt'=>$v['captured_at']],$vs)];
  }
 }
 $state['captureManaged']=true;
 $state['captureAudit']=array_map(fn($r)=>['id'=>'S'.$r['id'],'time'=>str_replace(' ','T',$r['created_at']).'Z','action'=>$r['action'],'actor'=>$r['actor'],'details'=>$r['details']],$db->query('SELECT id,actor,action,details,created_at FROM quorum_capture_audit ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC));
 return $state;
}
function cap_store(PDO $db,int $revision,array $state): array {
 $state=cap_clean_state(cap_projection($db,$state));$payload=cap_json($state);
 if(strlen($payload)>2000000)throw new CaptureError('Meeting workspace exceeds its 2 MB capacity.',413);
 $db->prepare('UPDATE quorum_workspace SET payload=?,revision=revision+1 WHERE id=1')->execute([$payload]);
 return ['revision'=>$revision+1,'state'=>$state];
}
function cap_checkin(PDO $db,array $state,array $input,string $actor,?int $staff): array {
 cap_open($state);$id=filter_var($input['ownerId']??null,FILTER_VALIDATE_INT);if(!$id)throw new CaptureError('Select an owner.');
 $o=cap_owner($state,$id);if(!cap_eligible($o))throw new CaptureError('Owner is not eligible. Ask the administrator to review.');
 $mode=$input['mode']??'';if(!in_array($mode,['personal','proxy','electronic'],true))throw new CaptureError('Choose an attendance method.');
 if($staff!==null&&$mode==='electronic')throw new CaptureError('Assistants may register venue attendance only.',403);
 if(($input['verified']??false)!==true)throw new CaptureError('Confirm identity and proxy verification.');
 $holder=cap_text($input['holder']??'');$reference=cap_text($input['reference']??'');
 if($mode==='proxy'&&($holder===''||$reference===''))throw new CaptureError('Proxy holder and verified mandate reference are required.');
 $key=cap_key($mode,$holder,$reference);$before=cap_participants($db,$id);$existing=null;
 foreach(cap_claims($db) as $c)if($c['ownerId']===$id&&$c['authorityKey']===$key)$existing=$c;
 if($existing&&!empty($existing['active'])&&$existing['mode']===$mode)throw new CaptureError('Already checked in by '.($existing['checkedInBy']??'another operator').' at '.($existing['checkedInAt']??'an earlier time').'.',409);
 $case=cap_case($db,$id);
 if($case&&$case['status']==='resolved'&&($case['winner']===null||($existing&&(int)$case['winner']!==$existing['claimId'])))throw new CaptureError('Administrator has rejected this representation. Refer new evidence to the administrator.',409);
 $a=['id'=>$existing['id']??'ATT-'.bin2hex(random_bytes(12)),'mode'=>$mode,'holder'=>$mode==='proxy'?$holder:'','reference'=>$reference,'active'=>true,'checkedInAt'=>cap_time(),'checkedInBy'=>$actor];
 $c=$existing?:cap_new_claim($db,$id,$a);$c=array_merge($c,$a);cap_write_claim($db,$c);
 if(!$existing&&$before)cap_reopen_for_new_claim($db,$id,$actor,$staff);
 $disputed=cap_reconcile($db,$id,$actor,$staff);
 cap_audit($db,$actor,'ATTENDANCE_CHECKED_IN',['ownerId'=>$id,'claimId'=>$c['claimId'],'mode'=>$mode,'holder'=>$holder,'reference'=>$reference,'disputed'=>$disputed],$staff);
 return ['message'=>$disputed?'Recorded as DISPUTED. Administrator must resolve representation; attendance and votes are held.':'Attendance recorded.','disputed'=>$disputed,'claimId'=>$c['claimId']];
}
function cap_record_votes(PDO $db,array $state,array $input,string $actor,?int $staff,bool $electronic=false): array {
 cap_open($state,true);$id=(int)($input['ownerId']??0);$o=cap_owner($state,$id);if(!cap_eligible($o))throw new CaptureError('Owner is not eligible.');
 $claimId=(int)($input['claimId']??0);
 if($electronic){
  $c=null;foreach(cap_claims($db) as $candidate)if($candidate['ownerId']===$id&&$candidate['authorityKey']==='owner')$c=$candidate;
  if(!$c||empty($c['active'])){$r=cap_checkin($db,$state,['ownerId'=>$id,'mode'=>'electronic','verified'=>true,'reference'=>'Electronic ballot submission'],$actor,null);$c=cap_claim($db,$r['claimId']);}
 }else {$c=cap_claim($db,$claimId);if($c['ownerId']!==$id||empty($c['active'])||!in_array($c['mode'],['personal','proxy'],true))throw new CaptureError('Select an active owner or proxy physically at the venue.');}
 $case=cap_case($db,$id);if($case&&$case['status']==='resolved'&&($case['winner']===null||(int)$case['winner']!==$c['claimId']))throw new CaptureError('This representation was rejected by the administrator.',409);
 if(($input['confirmed']??false)!==true)throw new CaptureError('Confirm the captured choices match the source ballot.');
 $choices=$input['choices']??null;if(!is_array($choices)||!count($choices))throw new CaptureError('Select at least one motion. Leave other motions unselected.');
 $motions=array_column($state['motions'],null,'id');$seen=[];
 foreach($choices as $v){$m=$v['motionId']??null;if(!is_int($m)||!isset($motions[$m])||isset($seen[$m])||!in_array($v['choice']??'', ['For','Against','Abstained'],true))throw new CaptureError('Invalid, repeated, or unknown motion choice.');$seen[$m]=true;}
 if($electronic&&count($seen)!==count($motions))throw new CaptureError('Electronic ballots must include every motion.');
 $reference=$electronic?null:cap_text($input['reference']??'');
 if(!$electronic)cap_paper_reference($db,$reference,$id);
 $query=$db->prepare('SELECT actor,captured_at FROM quorum_capture_votes WHERE claim_id=? AND motion_id=?');
 foreach($choices as $v){$query->execute([$c['claimId'],$v['motionId']]);if($prior=$query->fetch(PDO::FETCH_ASSOC))throw new CaptureError('Motion '.$v['motionId'].' already captured for this representation by '.$prior['actor'].' at '.$prior['captured_at'].'. No choices in this submission were saved.',409);}
 foreach($choices as $v)$db->prepare('INSERT INTO quorum_capture_votes (claim_id,motion_id,choice,channel,paper_reference,actor,captured_at) VALUES (?,?,?,?,?,?,?)')->execute([$c['claimId'],$v['motionId'],$v['choice'],$electronic?'electronic':'paper',$reference,$actor,cap_time()]);
 $disputed=cap_reconcile($db,$id,$actor,$staff);
 cap_audit($db,$actor,$electronic?'ELECTRONIC_BALLOT_CAPTURED':'PAPER_VOTES_CAPTURED',['ownerId'=>$id,'claimId'=>$c['claimId'],'reference'=>$reference,'choices'=>$choices,'disputed'=>$disputed],$staff);
 return ['message'=>$disputed?'Votes recorded as DISPUTED and excluded until administrator review.':'Votes saved.','disputed'=>$disputed,'claimId'=>$c['claimId']];
}
function cap_resolve(PDO $db,array $state,int $ownerId,?int $winner,string $reason,string $actor): void {
 cap_open($state);$case=cap_case($db,$ownerId);if(!$case)throw new CaptureError('No representation dispute exists.');
 if(strlen(trim($reason))<5||strlen($reason)>2000)throw new CaptureError('Record the evidence reviewed and decision reason (5–2,000 characters).');
 if($winner!==null&&cap_claim($db,$winner)['ownerId']!==$ownerId)throw new CaptureError('Invalid winning representation.');
 $db->prepare("UPDATE quorum_capture_cases SET status='resolved',winner=?,reason=?,actor=?,decided_at=? WHERE owner_id=?")->execute([$winner,trim($reason),$actor,cap_time(),$ownerId]);
 cap_audit($db,$actor,'REPRESENTATION_RESOLVED',['ownerId'=>$ownerId,'winner'=>$winner,'reason'=>trim($reason)]);
}
function cap_public_owner(PDO $db,array $state,int $id): array {
 $o=cap_owner($state,$id);$claims=array_values(array_filter(cap_claims($db),fn($c)=>$c['ownerId']===$id));$votes=cap_votes($db);
 foreach($claims as &$c){$c['motionsRecorded']=array_values(array_map(fn($v)=>(int)$v['motion_id'],array_filter($votes,fn($v)=>(int)$v['claim_id']===$c['claimId'])));}unset($c);
 $case=cap_case($db,$id);$case=$case?['status'=>$case['status'],'winner'=>$case['winner']]:null;
 return ['id'=>$id,'name'=>$o['name'],'unit'=>$o['unit'],'address'=>$o['address']??'','phone'=>$o['phone']??'','eligible'=>cap_eligible($o),'claims'=>$claims,'case'=>$case];
}
function cap_admin_ingest(PDO $db,array $before,array $after,string $actor): void {
 $rawVotes=cap_votes($db);
 if($rawVotes&&cap_json($before['motions'])!==cap_json($after['motions']))throw new CaptureError('The motion agenda is locked after recorded votes, including held or disputed votes.',409);
 foreach(cap_claims($db) as $claim){
  if(empty($claim['active'])&&!array_filter($rawVotes,fn($v)=>(int)$v['claim_id']===$claim['claimId']))continue;
  $old=cap_owner($before,$claim['ownerId']);$new=cap_owner($after,$claim['ownerId']);
  if($old['name']!==$new['name']||$old['unit']!==$new['unit']||($old['email']??'')!==($new['email']??''))throw new CaptureError('Owner identity is locked once attendance or votes are recorded, including disputed submissions.',409);
 }
 // Administrator workspace edits cannot silently remove or rewrite recorded votes.
 foreach(($before['ballots']??[]) as $old){
  $match=null;foreach($after['ballots'] as $b)if($b['ownerId']===$old['ownerId'])$match=$b;
  if(!$match)throw new CaptureError('Recorded ballots cannot be deleted through workspace saves.',409);
  foreach($old['choices'] as $v){$same=false;foreach($match['choices'] as $n)if($n['motionId']===$v['motionId']&&$n['choice']===$v['choice'])$same=true;if(!$same)throw new CaptureError('Recorded choices cannot be replaced. Resolve representation disputes explicitly.',409);}
  $o=cap_owner($before,$old['ownerId']);$n=cap_owner($after,$old['ownerId']);
  if($o['name']!==$n['name']||$o['unit']!==$n['unit'])throw new CaptureError('Cannot replace the identity of an owner with recorded votes.',409);
 }
 $priorAttendance=array_column($before['attendance']??[],null,'id');
 foreach(($after['attendance']??[]) as $a){
  $old=$priorAttendance[$a['id']]??null;
  if(!$old&&!empty($a['active']))cap_checkin($db,$before,['ownerId'=>$a['ownerId'],'mode'=>$a['mode'],'holder'=>$a['holder']??'','reference'=>$a['reference']??'','verified'=>true],$actor,null);
  elseif($old&&!empty($old['active'])&&empty($a['active'])){
   cap_open($before);$c=cap_claim($db,(int)$old['claimId']);$c['active']=false;$c['checkedOutAt']=cap_time();$c['checkedOutBy']=$actor;$c['checkoutReason']=cap_text($a['checkoutReason']??'Administrator checkout',300);cap_write_claim($db,$c);cap_audit($db,$actor,'ATTENDANCE_CHECKED_OUT',['ownerId'=>$c['ownerId'],'claimId'=>$c['claimId'],'reason'=>$c['checkoutReason']]);
  }
 }
 $priorBallots=array_column($before['ballots']??[],null,'ownerId');
 foreach($after['ballots'] as $b){
  $old=$priorBallots[$b['ownerId']]??null;$oldIds=array_column($old['choices']??[],'motionId');
  $choices=array_values(array_filter($b['choices'],fn($v)=>!in_array($v['motionId'],$oldIds,true)));if(!$choices)continue;
  $paper=($b['channel']??'')==='paper';$claimId=null;
  if($paper){foreach(cap_claims($db) as $c)if($c['ownerId']===$b['ownerId']&&(!empty($c['active']))&&($c['id']===($b['attendanceId']??'')||(($c['holder']??'')===($b['proxyHolder']??'')&&in_array($c['mode'],['personal','proxy'],true))))$claimId=$c['claimId'];if(!$claimId)throw new CaptureError('Check in this paper voter at the venue first.');}
  cap_record_votes($db,$before,['ownerId'=>$b['ownerId'],'claimId'=>$claimId,'choices'=>$choices,'reference'=>$b['paperReference']??'','confirmed'=>true],$actor,null,!$paper);
 }
}
