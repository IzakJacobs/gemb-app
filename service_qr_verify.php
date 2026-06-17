<?php
/**
 * service_qr_verify.php — GEMB Service Provider Pass verification
 * ─────────────────────────────────────────────────────────────────
 * Code format: 7XXXXX
 * Public endpoint — no login session required.
 *
 * Security:
 *   - Per-IP rate limit: 10 requests/minute (shared table with visitor verify)
 *   - Code validated against strict regex before any DB query
 *   - Approval check uses current schema values ('true' / 1)
 *   - Day-of-week and time-of-day enforcement
 *   - Self-contained HTML — no header.php / footer.php dependency
 */
require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');

// ── Rate limiting ─────────────────────────────────────────
// Shared rate_limit_qr table with visitor_qr_verify.php
// endpoint = 'sp' — separate bucket from visitor codes
function rlCheckSp(string $ip): bool {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS rate_limit_qr (
            id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip_hash    VARCHAR(64)  NOT NULL,
            endpoint   VARCHAR(20)  NOT NULL DEFAULT 'visitor',
            hit_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_time (ip_hash, hit_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ipHash = hash('sha256', $ip);

        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM rate_limit_qr
             WHERE ip_hash = ?
               AND endpoint = 'sp'
               AND hit_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $stmt->execute([$ipHash]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= 10) return false;

        db()->prepare(
            "INSERT INTO rate_limit_qr (ip_hash, endpoint) VALUES (?, 'sp')"
        )->execute([$ipHash]);

        db()->prepare(
            "DELETE FROM rate_limit_qr
             WHERE hit_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        )->execute();

        return true;
    } catch (Exception $e) {
        return true; // fail open
    }
}

// Use REMOTE_ADDR only — HTTP_X_FORWARDED_FOR is client-controlled
// and can be spoofed to bypass rate limiting.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (!rlCheckSp($ip)) {
    http_response_code(429);
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
$message = '';
$sp      = null;
$denyDetail = '';

if (!preg_match('/^7\d{5}$/', $code)) {
    $message = '⛔ Invalid service provider code.';
} else {
    $stmt = db()->prepare("
        SELECT sp.*,
               r.resident_name AS res_name,
               r.address,
               r.email AS resident_email
        FROM service_providers sp
        LEFT JOIN residents r
               ON UPPER(r.resident_erfno) = UPPER(sp.resident_erfno)
              AND r.is_primary = 1
        WHERE sp.unique_code = ?
        LIMIT 1
    ");
    $stmt->execute([$code]);
    $sp = $stmt->fetch();

    if (!$sp) {
        $message = '⛔ Code not found in system.';
    } elseif ($sp['expired']) {
        $message = '⛔ Permit has been revoked.';
    } elseif ($sp['approved'] !== 'true' && $sp['approved'] != 1) {
        $message = '⛔ Permit not yet approved by Security.';
    } else {
        $now   = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
        $today = $now->format('Y-m-d');
        $time  = $now->format('H:i:s');
        $dow   = $now->format('D'); // Mon Tue Wed etc.

        $start = $sp['start_date'] ?? '';
        $end   = $sp['end_date']   ?? '';

        // ── Date range check ──────────────────────────────
        if ($today < $start) {
            $message    = '⛔ Permit not yet active.';
            $denyDetail = 'Active from ' . date('d M Y', strtotime($start));
        } elseif ($today > $end) {
            $message    = '⛔ Permit expired.';
            $denyDetail = 'Expired ' . date('d M Y', strtotime($end));
        } else {
            // ── Day-of-week check ─────────────────────────
            $accessDays = array_map('trim',
                          explode(',', $sp['access_days'] ?? ''));
            $dayOk = empty($accessDays) || in_array($dow, $accessDays, true);

            // ── Time-of-day check ─────────────────────────
            $startTime = substr($sp['access_start'] ?? '07:00:00', 0, 8);
            $endTime   = substr($sp['access_end']   ?? '17:00:00', 0, 8);
            $timeOk    = ($time >= $startTime && $time <= $endTime);

            if (!$dayOk) {
                $message    = '⛔ Access not permitted today.';
                $denyDetail = 'Allowed days: ' . ($sp['access_days'] ?? 'Mon–Sat');
            } elseif (!$timeOk) {
                $message    = '⛔ Outside permitted hours.';
                $denyDetail = 'Allowed: '
                    . substr($startTime, 0, 5) . '–'
                    . substr($endTime, 0, 5);
            } else {
                $access  = true;
                $message = '✅ GO — Access Granted';
            }
        }
    }
}

// ── Gate trigger on valid pass ────────────────────────────
$gateOk  = false;
$gateMsg = '';
if ($access && empty($_GET['nogate'])) {
    $gate    = triggerGateSp();
    $gateOk  = $gate['ok'];
    $gateMsg = $gate['msg'];
    logSpGateEvent(
        (int)($sp['id'] ?? 0), $code,
        $gateOk ? 'opened' : 'trigger_failed',
        $manual ? 'manual' : 'qr_scan',
        $gateMsg
    );

    // Notify resident of service provider entry
    $resEmail = $sp['resident_email'] ?? '';
    if ($resEmail) {
        require_once __DIR__ . '/twilio_helper.php';
        notifyResidentEntry(
            $resEmail,
            $sp['service_name'] ?? 'Service Provider',
            'service_provider',
            'GEMB Estate Gate',
            date('d M Y H:i')
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <title><?= $access ? '✅ GO — GEMB Gate' : '⛔ STOP — GEMB Gate' ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      background: #000; min-height: 100vh;
      display: flex; flex-direction: column;
      align-items: center; padding-bottom: 40px;
    }
    .result-band {
      width: 100%; padding: 36px 24px 28px; text-align: center;
    }
    .result-band.go   { background: #006600; }
    .result-band.stop { background: #aa0000; }
    .result-headline {
      font-size: 3.5rem; font-weight: 900; color: #fff;
      line-height: 1.1; letter-spacing: 0.02em;
    }
    .result-sub {
      font-size: 1.1rem; color: rgba(255,255,255,0.9);
      margin-top: 10px; font-weight: 600;
    }
    .deny-detail {
      font-size: .95rem; color: rgba(255,255,200,0.85);
      margin-top: 8px;
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
      font-size: 1rem; color: #fff;
    }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { font-weight: 700; opacity: 0.7; min-width: 110px; flex-shrink: 0; }
    .detail-value { text-align: right; word-break: break-word; max-width: 58%; }
    .mono {
      font-family: 'Courier New', monospace; font-weight: 800;
      font-size: 1.1rem; letter-spacing: 0.18em;
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
  </style>
</head>
<body>

<div class="result-band <?= $access ? 'go' : 'stop' ?>">
  <div class="result-headline"><?= $message ?></div>
  <?php if ($denyDetail): ?>
    <div class="deny-detail"><?= htmlspecialchars($denyDetail) ?></div>
  <?php endif; ?>
  <?php if ($access): ?>
    <div class="gate-chip <?= $gateOk ? 'ok' : 'fail' ?>">
      <?= $gateOk ? '🔓 Gate opening…' : '⚠️ Gate trigger not configured' ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($sp): ?>
<div class="detail-card">
  <div class="detail-row">
    <span class="detail-label">Code</span>
    <span class="detail-value mono"><?= htmlspecialchars($code) ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Provider</span>
    <span class="detail-value"><?= htmlspecialchars($sp['service_name'] ?? '—') ?></span>
  </div>
  <?php if (!empty($sp['company_name'])): ?>
  <div class="detail-row">
    <span class="detail-label">Company</span>
    <span class="detail-value"><?= htmlspecialchars($sp['company_name']) ?></span>
  </div>
  <?php endif; ?>
  <div class="detail-row">
    <span class="detail-label">Category</span>
    <span class="detail-value">
      <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $sp['category'] ?? ''))) ?>
    </span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Resident</span>
    <span class="detail-value">
      <?= htmlspecialchars($sp['res_name'] ?? $sp['resident_name'] ?? '—') ?>
    </span>
  </div>
  <?php if (!empty($sp['address'])): ?>
  <div class="detail-row">
    <span class="detail-label">Address</span>
    <span class="detail-value"><?= htmlspecialchars($sp['address']) ?></span>
  </div>
  <?php endif; ?>
  <div class="detail-row">
    <span class="detail-label">Valid</span>
    <span class="detail-value">
      <?= date('d M Y', strtotime($sp['start_date'])) ?>
      – <?= date('d M Y', strtotime($sp['end_date'])) ?>
    </span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Hours</span>
    <span class="detail-value">
      <?= htmlspecialchars($sp['access_days'] ?? 'Mon–Sat') ?>
      <?= htmlspecialchars(substr($sp['access_start'] ?? '07:00:00', 0, 5)) ?>
      –<?= htmlspecialchars(substr($sp['access_end'] ?? '17:00:00', 0, 5)) ?>
    </span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Entry</span>
    <span class="detail-value"><?= $manual ? 'Manual code' : 'QR scan' ?></span>
  </div>
</div>
<?php endif; ?>

<div class="btn-row">
  <a href="guard.php?action=verify" class="btn btn-yellow">← Gate</a>
  <a href="security.php?action=menu" class="btn btn-grey">Menu</a>
</div>

</body>
</html>
<?php

// ── Helpers ───────────────────────────────────────────────
function triggerGateSp(): array {
    $url     = defined('GATE_ESP32_URL')   ? GATE_ESP32_URL   : '';
    $token   = defined('GATE_ESP32_TOKEN') ? GATE_ESP32_TOKEN : '';
    $timeout = defined('GATE_TIMEOUT_SEC') ? (int)GATE_TIMEOUT_SEC : 5;

    if (!$url) return ['ok' => false, 'msg' => 'Gate controller not configured'];

    $timestamp = time();
    $sig       = hash_hmac('sha256', $timestamp . $token, $token);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'token' => $token,
            'ts'    => $timestamp,
            'sig'   => $sig,
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

function logSpGateEvent(int $spId, string $code, string $status,
                        string $method, string $note): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS sp_gate_log (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            sp_id     INT          NOT NULL,
            code      VARCHAR(20)  NOT NULL,
            status    VARCHAR(30)  NOT NULL,
            method    VARCHAR(20)  NOT NULL,
            note      VARCHAR(255),
            logged_at DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code (code),
            INDEX idx_sp   (sp_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        db()->prepare(
            "INSERT INTO sp_gate_log (sp_id, code, status, method, note)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$spId, $code, $status, $method, $note]);
    } catch (Exception $e) {
        error_log('GEMB logSpGateEvent: ' . $e->getMessage());
    }
}
