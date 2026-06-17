<?php
/**
 * pi_override_api.php — Gate override polling endpoint (cloud side)
 *
 * Pi polls this every 30 seconds to check for pending overrides.
 *
 * GET  ?action=pending  → returns list of pending overrides for this Pi
 * POST ?action=result   → Pi reports back with execution result
 *
 * Auth: Authorization: Bearer <PI_SYNC_KEY>
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/twilio_helper.php';
date_default_timezone_set('Africa/Johannesburg');
header('Content-Type: application/json');

// ── Auth ──────────────────────────────────────────────────
$provided = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!defined('PI_SYNC_KEY')
    || PI_SYNC_KEY === ''
    || !hash_equals('Bearer ' . PI_SYNC_KEY, $provided)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

$action = strtolower($_GET['action'] ?? 'pending');

// ── Auto-expire old pending overrides ─────────────────────
try {
    db()->exec(
        "UPDATE pending_gate_overrides
         SET status='expired'
         WHERE status='pending' AND expires_at < NOW()"
    );
} catch (Exception $e) {}

// ════════════════════════════════════════════════════════
// GET PENDING — Pi checks for work to do
// ════════════════════════════════════════════════════════
if ($action === 'pending') {
    $overrides = db()->query("
        SELECT id, gate, reason, officer_name, officer_id, resident_erfno
        FROM   pending_gate_overrides
        WHERE  status = 'pending'
          AND  expires_at > NOW()
        ORDER  BY created_at ASC
    ")->fetchAll();

    echo json_encode(['overrides' => $overrides]);

// ════════════════════════════════════════════════════════
// RESULT — Pi reports execution outcome
// ════════════════════════════════════════════════════════
} elseif ($action === 'result' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $id      = filter_var($_POST['id']      ?? 0, FILTER_VALIDATE_INT);
    $gateOk  = (bool)($_POST['gate_ok']    ?? false);
    $gateMsg = trim($_POST['gate_msg']      ?? '');

    if (!$id) {
        http_response_code(400);
        exit(json_encode(['error' => 'Missing id']));
    }

    // Load override details
    $ov = db()->prepare(
        "SELECT * FROM pending_gate_overrides WHERE id=? LIMIT 1"
    );
    $ov->execute([$id]);
    $ov = $ov->fetch();

    if (!$ov) {
        exit(json_encode(['error' => 'Override not found']));
    }

    // Update status
    db()->prepare("
        UPDATE pending_gate_overrides
        SET status=?, result_msg=?, executed_at=NOW()
        WHERE id=?
    ")->execute([
        $gateOk ? 'executed' : 'failed',
        $gateMsg,
        $id,
    ]);

    // Log to access_log
    $gateLabel = $ov['gate'] === 'SSgate' ? 'Schoeman Street' : 'Church Street';
    try {
        db()->prepare("
            INSERT INTO access_log
              (event_id, gate, direction, entry_type, person_name,
               guard_id, granted, deny_reason, notes)
            VALUES (?,?,?,?,?, ?,?,?,?)
        ")->execute([
            'OVR-' . strtoupper(substr(md5(uniqid('',true)),0,10)),
            $ov['gate'], 'ENTRY', 'override', $ov['officer_name'],
            $ov['officer_id'],
            $gateOk ? 1 : 0,
            $gateOk ? null : $gateMsg,
            '[REMOTE OVERRIDE] Officer: ' . $ov['officer_name'] . ' | Reason: ' . $ov['reason'],
        ]);
    } catch (Exception $e) {
        error_log('Override access_log error: ' . $e->getMessage());
    }

    // Notify resident if erf provided
    if ($ov['resident_erfno']) {
        try {
            $res = db()->prepare(
                "SELECT email, resident_name FROM residents
                 WHERE UPPER(resident_erfno)=? AND is_primary=1 LIMIT 1"
            );
            $res->execute([strtoupper($ov['resident_erfno'])]);
            $res = $res->fetch();
            if ($res && $res['email']) {
                $msg = "Dear {$res['resident_name']},\n\n"
                     . "The {$gateLabel} gate was opened remotely by the estate security officer.\n\n"
                     . "Officer: {$ov['officer_name']}\n"
                     . "Reason: {$ov['reason']}\n"
                     . "Result: " . ($gateOk ? 'Gate opened successfully' : 'Gate trigger failed — ' . $gateMsg) . "\n"
                     . "Time: " . date('d M Y H:i:s') . "\n\n"
                     . "If you did not request this, please contact the estate office immediately.";
                sendEmail($res['email'], 'GEMB Estate — Gate Opened Remotely', $msg);
            }
        } catch (Exception $e) {
            error_log('Override resident notify error: ' . $e->getMessage());
        }
    }

    // Notify admin
    try {
        $adminEmail = db()->query(
            "SELECT email FROM admins WHERE email IS NOT NULL LIMIT 1"
        )->fetchColumn();
        if ($adminEmail) {
            sendEmail(
                $adminEmail,
                'GEMB — Remote Gate Override by ' . $ov['officer_name'],
                "Remote gate override " . ($gateOk ? 'EXECUTED' : 'FAILED') . ".\n\n"
                . "Officer: {$ov['officer_name']}\n"
                . "Gate: {$gateLabel}\n"
                . "Reason: {$ov['reason']}\n"
                . "Result: " . ($gateOk ? 'Gate opened' : 'FAILED — ' . $gateMsg) . "\n"
                . "Erf: " . ($ov['resident_erfno'] ?: 'Not specified') . "\n"
                . "Time: " . date('d M Y H:i:s')
            );
        }
    } catch (Exception $e) {}

    echo json_encode(['saved' => true, 'status' => $gateOk ? 'executed' : 'failed']);

} else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
