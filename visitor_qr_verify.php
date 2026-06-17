<?php
/**
 * visitor_qr_verify.php — Public gate verification screen
 * ─────────────────────────────────────────────────────────
 * Scanned by guard from visitor's phone QR code.
 * Public endpoint — no login session required.
 * Code format: 3XXXXX
 *
 * Security:
 *   - Per-IP rate limit: 10 requests/minute (DB-backed, no APCu needed)
 *   - Code validated against strict regex before any DB query
 *   - All output htmlspecialchars encoded
 *   - Gate trigger uses HMAC-signed token
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');

// ── Rate limiting ─────────────────────────────────────────
// 10 requests per IP per minute — blocks code enumeration attacks
// Uses a lightweight DB table (auto-created on first use)
function rlCheck(string $ip): bool {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS rate_limit_qr (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_hash    VARCHAR(64)  NOT NULL,
            endpoint   VARCHAR(20)  NOT NULL DEFAULT 'visitor',
            hit_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip_hash, hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ipHash = hash('sha256', $ip); // never store raw IPs

        // Count hits in the last 60 seconds
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM rate_limit_qr
             WHERE ip_hash = ?
               AND endpoint = 'visitor'
               AND hit_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $stmt->execute([$ipHash]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= 10) return false; // locked out

        // Record this request
        db()->prepare(
            "INSERT INTO rate_limit_qr (ip_hash, endpoint) VALUES (?, 'visitor')"
        )->execute([$ipHash]);

        // Housekeeping — purge records older than 5 minutes
        db()->prepare(
            "DELETE FROM rate_limit_qr
             WHERE hit_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        )->execute();

        return true;
    } catch (Exception $e) {
        return true; // fail open — never block on DB error
    }
}

// Use REMOTE_ADDR only — HTTP_X_FORWARDED_FOR is client-controlled
// and can be spoofed to bypass rate limiting.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!rlCheck($ip)) {
    http_response_code(429);
    // Show a plain STOP screen — do not reveal why
    die('<!DOCTYPE html><html><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>⛔ STOP</title>
<style>body{background:#aa0000;display:flex;align-items:center;
justify-content:center;min-height:100vh;margin:0;font-family:sans-serif;}
.box{text-align:center;color:#fff;padding:40px;}
h1{font-size:4rem;margin:0;}p{font-size:1.2rem;margin-top:16px;}</style>
</head><body><div class="box"><h1>⛔ STOP</h1>
<p>Too many requests. Please wait one minute and try again.</p>
</div></body></html>');
}

// ── Input validation ──────────────────────────────────────
$code   = strtoupper(trim($_GET['code'] ?? ''));
$manual = !empty($_GET['manual']);

$access  = false;
$reason  = '';
$visitor = null;
error_log('MBGE DEBUG: visitor_qr_verify.php reached, code=' . ($_GET['code'] ?? 'none'));

if (!preg_match('/^3\d{5}$/', $code)) {
    $reason = 'Invalid visitor code format.';
} else {
    // Use PDO db() — consistent with rest of system
    $stmt = db()->prepare("
        SELECT v.*,
               r.address,
               r.email AS resident_email
        FROM visitors v
        LEFT JOIN residents r
               ON UPPER(r.resident_erfno) = UPPER(v.resident_erfno)
              AND r.is_primary = 1
        WHERE v.code = ?
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $visitor = $stmt->fetch();

    if (!$visitor) {
        $reason = 'Code not found in system.';
    } elseif ($visitor['status'] === 'cancelled' || $visitor['expired']) {
        $reason = 'This pass has been cancelled or expired.';
    } else {
        $visitFrom = $visitor['visit_date']    ?? '';
        $visitTo   = $visitor['visit_date_to'] ?? $visitFrom;
        $now   = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
        $start = new DateTime($visitFrom . ' 00:00:00');
        $end   = new DateTime($visitTo   . ' 23:59:59');

        if ($now < $start) {
            $reason = 'Pass not yet active — valid from '
                    . date('d M Y', strtotime($visitFrom)) . '.';
        } elseif ($now > $end) {
            $reason = 'Pass expired on '
                    . date('d M Y', strtotime($visitTo)) . '.';
        } else {
            $access = true;
            $reason = 'Access Granted';
        }
    }
}

// ── Gate trigger on valid pass ────────────────────────────
$gateOk  = false;
$gateMsg = '';
if ($access && empty($_GET['nogate'])) {
    $gate    = triggerGate();
    $gateOk  = $gate['ok'];
    $gateMsg = $gate['msg'];
    logGateEvent(
        (int)($visitor['id'] ?? 0), $code,
        $gateOk ? 'opened' : 'trigger_failed',
        $manual ? 'manual' : 'qr_scan',
        $gateMsg
    );

    // Record first arrival timestamp
    if (!empty($visitor['id']) && empty($visitor['arrival'])) {
        try {
            db()->prepare(
                "UPDATE visitors SET arrival = NOW() WHERE id = ? AND arrival IS NULL"
            )->execute([(int)$visitor['id']]);
        } catch (Exception $e) { /* arrival column may not exist yet */ }
    }

    // Notify resident of visitor entry
    $resEmail = $visitor['resident_email'] ?? '';
    error_log('MBGE DEBUG notify: erfno=' . ($visitor['resident_erfno'] ?? 'NULL') . ' email=' . ($resEmail ?: 'EMPTY'));
    if ($resEmail) {
        require_once __DIR__ . '/twilio_helper.php';
        notifyResidentEntry(
            $resEmail,
            $visitor['visitor_name'] ?? 'Visitor',
            'visitor',
            'MBGE Estate Gate',
            date('d M Y H:i')
        );
    }
}

// ── Display values ────────────────────────────────────────
$visitFrom    = $visitor['visit_date']    ?? '';
$visitTo      = $visitor['visit_date_to'] ?? $visitFrom;
$fmtFrom      = $visitFrom ? date('d M Y', strtotime($visitFrom)) : '—';
$fmtTo        = $visitTo   ? date('d M Y', strtotime($visitTo))   : '—';
$visitorName  = $visitor['visitor_name']  ?? '—';
$residentName = $visitor['resident_name'] ?? '—';
$address      = $visitor['address']       ?? '';
$vehicleReg   = $visitor['plate']         ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <title><?= $access ? '✅ GO — MBGE Gate' : '⛔ STOP — MBGE Gate' ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      background: #000; min-height: 100vh;
      display: flex; flex-direction: column; align-items: center;
      padding-bottom: 40px;
    }
    .result-band {
      width: 100%; padding: 36px 24px 28px; text-align: center;
    }
    .result-band.go   { background: #006600; }
    .result-band.stop { background: #aa0000; }
    .result-headline {
      font-size: 4rem; font-weight: 900; color: #fff;
      line-height: 1; letter-spacing: 0.02em;
    }
    .result-sub {
      font-size: 1.25rem; color: rgba(255,255,255,0.9);
      margin-top: 12px; font-weight: 600;
    }
    .gate-chip {
      display: inline-block; margin-top: 14px;
      padding: 7px 20px; border-radius: 20px;
      font-size: 0.95rem; font-weight: 700;
    }
    .gate-chip.ok   { background: rgba(255,255,255,0.2); color: #fff; }
    .gate-chip.fail { background: rgba(255,220,0,0.3);   color: #ffe; }
    .detail-card {
      width: calc(100% - 32px); max-width: 460px;
      background: rgba(255,255,255,0.10); border-radius: 14px;
      margin: 18px 0 0; overflow: hidden;
    }
    .detail-row {
      display: flex; justify-content: space-between;
      align-items: flex-start; padding: 13px 18px;
      border-bottom: 1px solid rgba(255,255,255,0.08);
      font-size: 1.05rem; color: #fff;
    }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { font-weight: 700; opacity: 0.7; min-width: 100px; flex-shrink: 0; }
    .detail-value { text-align: right; word-break: break-word; max-width: 60%; }
    .mono {
      font-family: 'Courier New', monospace; font-weight: 800;
      font-size: 1.15rem; letter-spacing: 0.18em;
    }
    .btn-row {
      width: calc(100% - 32px); max-width: 460px;
      display: flex; gap: 12px; margin: 16px 0 0;
    }
    .btn {
      flex: 1; min-height: 64px; border: none; border-radius: 14px;
      font-size: 1.1rem; font-weight: 800; cursor: pointer;
      text-decoration: none;
      display: flex; align-items: center; justify-content: center;
    }
    .btn:active { transform: scale(0.97); }
    .btn-yellow { background: #ffdd00; color: #000; }
    .btn-grey   { background: #333;    color: #fff; }
    .btn-reopen {
      width: calc(100% - 32px); max-width: 460px; min-height: 56px;
      background: rgba(255,255,255,0.15); color: #fff;
      border: 2px solid rgba(255,255,255,0.3); border-radius: 12px;
      font-size: 1rem; font-weight: 700; cursor: pointer;
      margin: 12px 0 0; text-decoration: none;
      display: flex; align-items: center; justify-content: center;
    }
    .btn-reopen:active { transform: scale(0.97); }
  </style>
</head>
<body>

<div class="result-band <?= $access ? 'go' : 'stop' ?>">
  <div class="result-headline"><?= $access ? '✅ GO' : '⛔ STOP' ?></div>
  <div class="result-sub"><?= htmlspecialchars($reason) ?></div>
  <?php if ($access): ?>
    <div class="gate-chip <?= $gateOk ? 'ok' : 'fail' ?>">
      <?= $gateOk ? '🔓 Gate opening…' : '⚠️ Gate trigger not configured' ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($visitor): ?>
<div class="detail-card">
  <div class="detail-row">
    <span class="detail-label">Code</span>
    <span class="detail-value mono"><?= htmlspecialchars($code) ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Visitor</span>
    <span class="detail-value"><?= htmlspecialchars($visitorName) ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Resident</span>
    <span class="detail-value"><?= htmlspecialchars($residentName) ?></span>
  </div>
  <?php if ($address): ?>
  <div class="detail-row">
    <span class="detail-label">Address</span>
    <span class="detail-value"><?= htmlspecialchars($address) ?></span>
  </div>
  <?php endif; ?>
  <div class="detail-row">
    <span class="detail-label">Valid</span>
    <span class="detail-value"><?= $fmtFrom ?> – <?= $fmtTo ?></span>
  </div>
  <?php if ($vehicleReg): ?>
  <div class="detail-row">
    <span class="detail-label">Vehicle</span>
    <span class="detail-value"><?= htmlspecialchars(strtoupper($vehicleReg)) ?></span>
  </div>
  <?php endif; ?>
  <div class="detail-row">
    <span class="detail-label">Entry</span>
    <span class="detail-value"><?= $manual ? 'Manual code' : 'QR scan' ?></span>
  </div>
</div>
<?php endif; ?>

<?php if ($access): ?>
<a href="visitor_qr_verify.php?code=<?= urlencode($code) ?>&nogate=0<?= $manual ? '&manual=1' : '' ?>"
   class="btn-reopen">
  🔓 <?= $gateOk ? 'Open Gate Again' : 'Retry Gate Open' ?>
</a>
<?php endif; ?>

<div class="btn-row">
  <a href="guard.php?action=verify" class="btn btn-yellow">← Gate</a>
  <a href="security.php?action=menu" class="btn btn-grey">Menu</a>
</div>

</body>
</html>
<?php

// ── Helpers ───────────────────────────────────────────────
function triggerGate(): array {
    $url     = defined('GATE_ESP32_URL')   ? GATE_ESP32_URL   : '';
    $token   = defined('GATE_ESP32_TOKEN') ? GATE_ESP32_TOKEN : '';
    $timeout = defined('GATE_TIMEOUT_SEC') ? (int)GATE_TIMEOUT_SEC : 5;

    if (!$url) return ['ok' => false, 'msg' => 'Gate controller not configured'];

    // HMAC-sign the request — prevents token replay without timestamp
    $timestamp = time();
    $sig       = hash_hmac('sha256', $timestamp . $token, $token);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'token'     => $token,
            'ts'        => $timestamp,
            'sig'       => $sig,
        ]),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false, // ESP32 self-signed cert — LAN only, acceptable risk
        CURLOPT_SSL_VERIFYHOST => 0,     // must match VERIFYPEER=false
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)          return ['ok' => false, 'msg' => 'cURL: ' . $err];
    if ($http === 200) return ['ok' => true,  'msg' => 'Opened'];
    return ['ok' => false, 'msg' => "HTTP {$http}"];
}

function logGateEvent(int $vid, string $code, string $status,
                      string $method, string $note): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS visitor_gate_log (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            visitor_id INT          NOT NULL,
            code       VARCHAR(20)  NOT NULL,
            status     VARCHAR(30)  NOT NULL,
            method     VARCHAR(20)  NOT NULL,
            note       VARCHAR(255),
            logged_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code    (code),
            INDEX idx_visitor (visitor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->prepare(
            "INSERT INTO visitor_gate_log
               (visitor_id, code, status, method, note)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$vid, $code, $status, $method, $note]);
    } catch (Exception $e) {
        error_log('MBGE logGateEvent: ' . $e->getMessage());
    }
}
