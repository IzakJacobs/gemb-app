<?php
/**
 * Unified AJAX/fetch endpoint.
 * Accepts JSON body { "action": "...", ... } — returns JSON.
 * HMAC secret stays server-side; client never sees it.
 */
require_once __DIR__ . '/db.php';
sessionStart();

header('Content-Type: application/json');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// CSRF check for all state-changing actions
$nocsrf = ['verify_qr'];
if (!in_array($action, $nocsrf, true)) {
    $tok = $body['csrf'] ?? '';
    if (!hash_equals(($_SESSION['csrf'] ?? ''), $tok)) {
        jsonOut(['ok' => false, 'error' => 'Invalid request token.'], 403);
    }
}

switch ($action) {
    case 'generate_qr': actionGenerateQr($body); break;
    case 'verify_qr':   actionVerifyQr($body);   break;
    case 'log_access':  actionLogAccess($body);  break;
    default:            jsonOut(['ok' => false, 'error' => 'Unknown action.'], 400);
}

// ---- Generate signed QR (resident only) ----
function actionGenerateQr(array $b): void {
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
        jsonOut(['ok' => false, 'error' => 'Unauthorised.'], 401);
    }
    $res   = $_SESSION['resident'];
    $name  = trim($b['name']  ?? '');
    $plate = strtoupper(trim($b['plate'] ?? ''));
    $idnum = trim($b['idnum'] ?? '');
    $date  = trim($b['date']  ?? '');

    if (!$name || !$plate || !$date)             jsonOut(['ok'=>false,'error'=>'Missing fields.'], 400);
    if (!preg_match('/^\d{13}$/', $idnum))        jsonOut(['ok'=>false,'error'=>'ID must be 13 digits.'], 400);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jsonOut(['ok'=>false,'error'=>'Invalid date.'], 400);

    // Save invitation to DB
    $uid  = (int)$res['id'];
    $stmt = db()->prepare('INSERT INTO invitations (visitor_name,plate,idnum,visit_date,invited_by,invited_by_name,unit) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('ssssiss', $name, $plate, $idnum, $date, $uid, $res['name'], $res['unit']);
    if (!$stmt->execute()) jsonOut(['ok'=>false,'error'=>'Could not save invitation.'], 500);
    $invId = (int)db()->insert_id;

    // Build QR payload and sign with HMAC (secret stays on server)
    $secret  = getSetting('hmac_secret');
    $payload = ['v'=>3, 'n'=>$name, 'p'=>$plate, 'id'=>$idnum, 'd'=>$date, 'inv'=>$invId, 'byN'=>$res['name'], 'unit'=>$res['unit']];

    if ($secret) {
        $canonical    = implode('|', [3, $name, $plate, $idnum, $date, $invId]);
        $payload['sig'] = hash_hmac('sha256', $canonical, $secret);
    } else {
        $payload['sig'] = 'none';
    }

    jsonOut(['ok'=>true, 'qr'=>json_encode($payload), 'inv_id'=>$invId, 'signed'=>(bool)$secret]);
}

// ---- Verify QR signature (guard session required; secret never sent to client) ----
function actionVerifyQr(array $b): void {
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'guard') {
        jsonOut(['ok'=>false,'error'=>'Unauthorised.'], 401);
    }
    $raw = trim($b['qr'] ?? '');
    if (!$raw) jsonOut(['ok'=>false,'error'=>'No QR data.'], 400);

    $v = json_decode($raw, true);
    if (!$v || empty($v['n']) || empty($v['p'])) {
        // Legacy format: "QR Info: Name - Plate - ID"
        if (preg_match('/QR Info:\s*(.+?)\s*-\s*([A-Z0-9]+)\s*-\s*(\d+)/i', $raw, $m)) {
            $v = ['v'=>1,'n'=>trim($m[1]),'p'=>trim($m[2]),'id'=>trim($m[3]),'d'=>null,'byN'=>'Unknown','unit'=>'','sig'=>'none','inv'=>null];
        } else {
            jsonOut(['ok'=>false,'error'=>'Unrecognised QR format.']);
        }
    }

    $secret = getSetting('hmac_secret');
    $verifyState = 'no-secret';

    if (!empty($v['sig']) && $v['sig'] !== 'none') {
        if (!$secret) {
            $verifyState = 'no-secret';
        } else {
            $invId     = isset($v['inv']) ? (int)$v['inv'] : 0;
            $canonical = implode('|', [(int)($v['v'] ?? 3), $v['n'], $v['p'], $v['id'] ?? '', $v['d'] ?? '', $invId]);
            $expected  = hash_hmac('sha256', $canonical, $secret);
            $verifyState = hash_equals($expected, $v['sig']) ? 'verified' : 'invalid';
        }
    } else {
        $verifyState = $secret ? 'unsigned' : 'no-secret';
    }

    $today     = date('Y-m-d');
    $vDate     = $v['d'] ?? null;
    $dateState = !$vDate ? 'none' : ($vDate === $today ? 'today' : ($vDate > $today ? 'future' : 'expired'));

    jsonOut([
        'ok'          => true,
        'verifyState' => $verifyState,
        'dateState'   => $dateState,
        'visitor'     => ['name'=>$v['n'],'plate'=>$v['p'],'id'=>$v['id']??'','date'=>$vDate,'byN'=>$v['byN']??'Unknown','unit'=>$v['unit']??'','invId'=>$v['inv']??null],
    ]);
}

// ---- Log access entry (guard only) ----
function actionLogAccess(array $b): void {
    if (empty($_SESSION['user_type']) || $_SESSION['user_type'] !== 'guard') {
        jsonOut(['ok'=>false,'error'=>'Unauthorised.'], 401);
    }
    $name    = trim($b['name']   ?? '');
    $plate   = strtoupper(trim($b['plate'] ?? ''));
    $idnum   = trim($b['idnum']  ?? '');
    $date    = $b['date']        ?? date('Y-m-d');
    $byName  = trim($b['byN']    ?? '');
    $unit    = trim($b['unit']   ?? '');
    $act     = $b['action']      ?? '';
    $source  = $b['source']      ?? 'qr';
    $vState  = $b['verifyState'] ?? '';
    $invId   = isset($b['invId']) && $b['invId'] ? (int)$b['invId'] : null;

    if (!$name || !$plate || !in_array($act, ['granted','denied'], true)) {
        jsonOut(['ok'=>false,'error'=>'Missing required fields.'], 400);
    }

    $stmt = db()->prepare('INSERT INTO access_log (visitor_name,plate,idnum,visit_date,invited_by_name,unit,action,source,verify_state,invitation_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('sssssssssi', $name, $plate, $idnum, $date, $byName, $unit, $act, $source, $vState, $invId);
    $stmt->execute();

    if ($invId) {
        $upd = db()->prepare('UPDATE invitations SET status=? WHERE id=?');
        $upd->bind_param('si', $act, $invId);
        $upd->execute();
    }

    jsonOut(['ok'=>true]);
}
