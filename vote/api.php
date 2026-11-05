<?php
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function respond(int $status, array $body): void { http_response_code($status); echo json_encode($body); exit; }
if (!authorized()) respond(401, ['error'=>'Session expired. Reload and sign in.']);
$_SESSION['last_seen']=time();
if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) respond(403, ['error'=>'Invalid request token.']);
try {
 $pdo=db();
 if ($_SERVER['REQUEST_METHOD']==='GET') {
  $row=$pdo->query('SELECT revision,payload FROM quorum_workspace WHERE id=1')->fetch(PDO::FETCH_ASSOC);
  respond(200, ['revision'=>$row ? (int)$row['revision'] : 0, 'state'=>$row ? json_decode($row['payload'], true) : null]);
 }
 if ($_SERVER['REQUEST_METHOD']!=='POST') respond(405, ['error'=>'Method not allowed.']);
 $raw=file_get_contents('php://input', false, null, 0, 2000001);
 if (strlen($raw)>2000000) respond(413, ['error'=>'Workspace exceeds 2 MB.']);
 $input=json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
 $state=$input['state'] ?? null;
 if (!is_array($state) || !is_array($state['owners'] ?? null) || !is_array($state['ballots'] ?? null) || !is_array($state['motions'] ?? null) || !is_array($state['audit'] ?? null) || !in_array($state['status'] ?? '', ['live','closed','draft'], true)) respond(422,['error'=>'Invalid workspace.']);
 $ownerIds=[];
 foreach ($state['ballots'] as $ballot) {
  $id=$ballot['ownerId'] ?? null;
  if (!is_int($id) || isset($ownerIds[$id])) respond(422,['error'=>'Duplicate or invalid owner ballot.']);
  $ownerIds[$id]=true;
 }
 $payload=json_encode($state, JSON_THROW_ON_ERROR);
 $revision=$input['revision'] ?? -1;
 if (!is_int($revision) || $revision<0) respond(422,['error'=>'Invalid revision.']);
 if ($revision===0) {
  $stmt=$pdo->prepare('INSERT IGNORE INTO quorum_workspace (id,revision,payload) VALUES (1,1,?)'); $stmt->execute([$payload]);
 } else {
  $stmt=$pdo->prepare('UPDATE quorum_workspace SET payload=?,revision=revision+1 WHERE id=1 AND revision=?'); $stmt->execute([$payload,$revision]);
 }
 if ($stmt->rowCount()!==1) respond(409,['error'=>'Workspace changed in another tab. Reload before continuing.']);
 respond(200,['revision'=>$revision+1]);
} catch (Throwable $e) { error_log('Quorum storage: '.$e->getMessage()); respond(500,['error'=>'Storage unavailable. Check server configuration and logs.']); }
