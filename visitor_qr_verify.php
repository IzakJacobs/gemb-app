<?php
/**
 * visitor_qr_verify.php — Guard-authenticated 3-step visitor entry
 * ─────────────────────────────────────────────────────────────────
 * Step 1 (GET  ?code=3XXXXX) — validate visitor pass, show result, NO gate yet
 * Step 2 (same page)         — guard scans licence disc or enters plate
 * Step 3 (POST)              — gate opens, plate saved for LPR exit
 *
 * Requires logged-in guard, security officer, or admin.
 * Code format: 3XXXXX
 *
 * Security:
 *   - Session auth required: guard_id, security_id, or admin_id must be set
 *   - Unauthenticated requests redirected to guard login
 *   - CSRF token required on plate POST
 *   - Per-IP rate limit: 10 requests/minute
 *   - Code validated against strict regex before any DB query
 *   - Gate trigger and log carry the guard's identity
 */
session_start();
require_once __DIR__ . '/config.php';
date_default_timezone_set('Africa/Johannesburg');

// ── Auth check ────────────────────────────────────────────
$guardId   = (int)($_SESSION['guard_id']    ?? 0)
           ?: (int)($_SESSION['security_id'] ?? 0)
           ?: (int)($_SESSION['admin_id']    ?? 0);
$guardName = $_SESSION['guard_name'] ?? $_SESSION['security_name'] ?? 'Unknown';
$isAuth    = $guardId > 0;

if (!$isAuth) {
    $_SESSION['after_qr'] = $_SERVER['REQUEST_URI'];
    header('Location: guard.php?action=login');
    exit;
}

// ── Rate limiting ─────────────────────────────────────────
function rlCheck(string $ip): bool {
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
             WHERE ip_hash = ? AND endpoint = 'visitor'
               AND hit_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
        );
        $stmt->execute([$ipHash]);
        if ((int)$stmt->fetchColumn() >= 10) return false;

        db()->prepare("INSERT INTO rate_limit_qr (ip_hash, endpoint) VALUES (?, 'visitor')")
            ->execute([$ipHash]);
        db()->prepare("DELETE FROM rate_limit_qr WHERE hit_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)")
            ->execute();
        return true;
    } catch (Exception $e) {
        return true;
    }
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (!rlCheck($ip)) {
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

// ══════════════════════════════════════════════════════════
// STEP 3 — POST: plate submitted, trigger gate
// ══════════════════════════════════════════════════════════
$step         = 1;   // which step we are displaying
$access       = false;
$visitor      = null;
$code         = '';
$reason       = '';
$gateOk       = false;
$gateMsg      = '';
$plateDisplay = '';
$dispMake     = '';
$dispModel    = '';
$dispColour   = '';
$dispExpiry   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entry_code'], $_POST['plate'])) {
    verifyCsrf();   // defined in config.php — dies on failure

    $code     = strtoupper(preg_replace('/[^A-Z0-9]/', '', $_POST['entry_code'] ?? ''));
    $rawPlate = strtoupper(trim($_POST['plate'] ?? ''));
    $plate    = preg_replace('/[^A-Z0-9]/', '', $rawPlate);   // clean for storage
    $plateDisplay = $rawPlate;                                  // keep spaces for display

    // Vehicle details from licence disc scan (optional — empty if typed manually)
    $vMake   = substr(strip_tags($_POST['vehicle_make']   ?? ''), 0, 50);
    $vModel  = substr(strip_tags($_POST['vehicle_model']  ?? ''), 0, 50);
    $vColour = substr(strip_tags($_POST['vehicle_colour'] ?? ''), 0, 50);
    $vBody   = substr(strip_tags($_POST['vehicle_body']   ?? ''), 0, 60);
    $vVin    = substr(strip_tags($_POST['vehicle_vin']    ?? ''), 0, 20);
    $vExpiry = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['licence_expiry'] ?? '')
               ? $_POST['licence_expiry'] : null;

    if (preg_match('/^3\d{5}$/', $code) && strlen($plate) >= 2) {
        $stmt = db()->prepare("SELECT * FROM visitors WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        $visitor = $stmt->fetch();
    }

    if ($visitor && $plate) {
        // ── Record plate in active_vehicle_visits ─────────
        saveActiveVisit(
            (int)$visitor['id'],
            $visitor['visitor_name'] ?? '',
            $code,
            $plate,
            $plateDisplay,
            $visitor['visit_date_to'] ?? $visitor['visit_date'] ?? date('Y-m-d'),
            $guardId,
            $guardName,
            $vMake, $vModel, $vColour, $vBody, $vVin, $vExpiry
        );

        // ── Mark first arrival ─────────────────────────────
        try {
            db()->prepare("UPDATE visitors SET arrival = NOW() WHERE id = ? AND arrival IS NULL")
                ->execute([(int)$visitor['id']]);
        } catch (Exception $e) {}

        // ── Open the gate ──────────────────────────────────
        $g       = triggerGate();
        $gateOk  = $g['ok'];
        $gateMsg = $g['msg'];

        logGateEvent(
            (int)$visitor['id'], $code,
            $gateOk ? 'opened' : 'trigger_failed',
            'lpr_plate',
            "Plate: {$plateDisplay}. {$gateMsg}",
            $guardId, $guardName
        );

        // ── Notify resident ────────────────────────────────
        $resEmail = getResEmailByCode($code);
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

        $access = true;
        $step   = 3;   // show final confirmation
        // Carry vehicle details through to the display vars
        $dispMake   = $vMake;
        $dispModel  = $vModel;
        $dispColour = $vColour;
        $dispExpiry = $vExpiry;
    } else {
        $reason = 'Invalid code or blank plate — please try again.';
        $step   = 1;
    }
}

// ══════════════════════════════════════════════════════════
// STEP 1 — GET: validate visitor QR code (no gate trigger)
// ══════════════════════════════════════════════════════════
if ($step === 1) {
    $code   = strtoupper(trim($_GET['code'] ?? ''));
    $manual = !empty($_GET['manual']);

    if (!preg_match('/^3\d{5}$/', $code)) {
        $reason = 'Invalid visitor code format.';
    } else {
        $stmt = db()->prepare("
            SELECT v.*,
                   r.address,
                   r.email AS resident_email
            FROM visitors v
            LEFT JOIN residents r
                   ON UPPER(r.resident_erfno) = UPPER(v.resident_erfno)
                  AND r.is_primary = 1
            WHERE v.code = ? LIMIT 1
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
            $now       = new DateTime('now', new DateTimeZone('Africa/Johannesburg'));
            $start     = new DateTime($visitFrom . ' 00:00:00');
            $end       = new DateTime($visitTo   . ' 23:59:59');

            if ($now < $start) {
                $reason = 'Pass not yet active — valid from '
                        . date('d M Y', strtotime($visitFrom)) . '.';
            } elseif ($now > $end) {
                $reason = 'Pass expired on '
                        . date('d M Y', strtotime($visitTo)) . '.';
            } else {
                $access = true;
                $reason = 'Valid Pass — Register Vehicle';
                $step   = 2;   // advance to plate entry
            }
        }
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
$prePlate     = strtoupper($visitor['plate'] ?? '');   // pre-registered plate if any
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <title>
    <?php if ($step === 3): ?>🔓 GATE OPEN — MBGE
    <?php elseif ($step === 2): ?>📋 Step 2 — MBGE Gate
    <?php else: ?>⛔ STOP — MBGE Gate<?php endif; ?>
  </title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
      background: #0a0a0a; min-height: 100vh;
      display: flex; flex-direction: column; align-items: center;
      padding-bottom: 48px;
    }

    /* ── Result band ── */
    .result-band {
      width: 100%; padding: 28px 24px 22px; text-align: center;
    }
    .band-open   { background: linear-gradient(160deg,#004d00,#006600); }
    .band-step2  { background: linear-gradient(160deg,#003566,#1a5fa0); }
    .band-stop   { background: linear-gradient(160deg,#7a0000,#aa0000); }

    .step-badge {
      display: inline-block; background: rgba(255,255,255,0.2);
      color: #fff; font-size: .75rem; font-weight: 800;
      letter-spacing: .12em; padding: 3px 10px; border-radius: 20px;
      margin-bottom: 8px; text-transform: uppercase;
    }
    .result-headline {
      font-size: 3rem; font-weight: 900; color: #fff;
      line-height: 1; letter-spacing: .02em;
    }
    .result-sub {
      font-size: 1.1rem; color: rgba(255,255,255,.9);
      margin-top: 10px; font-weight: 600;
    }
    .gate-chip {
      display: inline-block; margin-top: 12px;
      padding: 6px 18px; border-radius: 20px;
      font-size: .95rem; font-weight: 700;
    }
    .gate-chip.ok   { background: rgba(255,255,255,.2); color: #fff; }
    .gate-chip.fail { background: rgba(255,220,0,.3);   color: #ffe; }

    /* ── Detail card ── */
    .detail-card {
      width: calc(100% - 32px); max-width: 460px;
      background: rgba(255,255,255,.09); border-radius: 14px;
      margin: 16px 0 0; overflow: hidden;
    }
    .detail-row {
      display: flex; justify-content: space-between; align-items: flex-start;
      padding: 12px 18px; border-bottom: 1px solid rgba(255,255,255,.07);
      font-size: 1rem; color: #fff;
    }
    .detail-row:last-child { border-bottom: none; }
    .detail-label { font-weight: 700; opacity: .7; min-width: 100px; flex-shrink: 0; }
    .detail-value { text-align: right; word-break: break-word; max-width: 60%; }
    .mono { font-family: 'Courier New', monospace; font-weight: 800;
            font-size: 1.05rem; letter-spacing: .15em; }

    /* ── Plate form ── */
    .plate-section {
      width: calc(100% - 32px); max-width: 460px; margin: 20px 0 0;
    }
    .plate-section h2 {
      color: #fff; font-size: 1.15rem; font-weight: 800;
      margin-bottom: 12px; text-align: center; letter-spacing: .05em;
    }
    .plate-input-wrap { position: relative; }
    .plate-input {
      width: 100%; padding: 18px 56px 18px 18px;
      font-size: 1.8rem; font-weight: 800; letter-spacing: .22em;
      text-transform: uppercase; text-align: center;
      background: #fff; color: #0a0a0a;
      border: none; border-radius: 12px;
      outline: none; -webkit-appearance: none;
    }
    .plate-input::placeholder { color: #aaa; letter-spacing: .1em; font-size: 1.2rem; }
    .scan-icon-btn {
      position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; font-size: 1.6rem;
      padding: 4px;
    }
    .hint { color: rgba(255,255,255,.5); font-size: .78rem; text-align: center;
            margin-top: 6px; }

    /* ── Camera scanner ── */
    #camera-wrap {
      display: none; margin-top: 14px; border-radius: 12px; overflow: hidden;
      background: #000; position: relative;
    }
    #camera-video { width: 100%; max-height: 260px; object-fit: cover; display: block; }
    #camera-aim {
      position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
      width: 200px; height: 80px;
      border: 2px solid rgba(255,220,0,.8); border-radius: 6px;
      box-shadow: 0 0 0 9999px rgba(0,0,0,.45);
      pointer-events: none;
    }
    #camera-status {
      position: absolute; bottom: 8px; left: 0; right: 0;
      text-align: center; color: #ffe; font-size: .8rem; font-weight: 700;
    }
    #camera-close {
      position: absolute; top: 8px; right: 10px;
      background: rgba(0,0,0,.6); color: #fff; border: none;
      border-radius: 50%; width: 32px; height: 32px;
      font-size: 1rem; cursor: pointer; font-weight: 700;
    }

    /* ── Buttons ── */
    .open-gate-btn {
      width: 100%; margin-top: 14px; padding: 20px;
      background: #00b300; color: #fff;
      border: none; border-radius: 14px;
      font-size: 1.35rem; font-weight: 900;
      letter-spacing: .05em; cursor: pointer;
      transition: background .15s;
    }
    .open-gate-btn:active { background: #009900; transform: scale(.98); }
    .open-gate-btn:disabled { background: #555; color: #999; cursor: not-allowed; }
    .btn-row {
      width: calc(100% - 32px); max-width: 460px;
      display: flex; gap: 12px; margin: 16px 0 0;
    }
    .btn {
      flex: 1; min-height: 60px; border: none; border-radius: 14px;
      font-size: 1.05rem; font-weight: 800; cursor: pointer;
      text-decoration: none;
      display: flex; align-items: center; justify-content: center;
    }
    .btn:active { transform: scale(.97); }
    .btn-yellow { background: #ffdd00; color: #000; }
    .btn-grey   { background: #222;    color: #fff; }
    .plate-display {
      font-size: 2rem; font-weight: 900; letter-spacing: .2em;
      color: #ffdd00; font-family: monospace; margin-top: 4px;
    }

    /* ── Vehicle info card (shown after scan) ── */
    #vehicle-info {
      display: none; margin-top: 10px;
      background: rgba(255,255,255,.1); border-radius: 12px;
      padding: 12px 16px;
    }
    .vi-row {
      display: flex; justify-content: space-between;
      font-size: .9rem; color: #fff; padding: 3px 0;
    }
    .vi-label { opacity: .6; font-weight: 700; }
    .vi-value { font-weight: 800; text-align: right; }
    .vi-expiry-warn { color: #ffdd00; }
    .vi-expiry-ok   { color: #99ee99; }
  </style>
</head>
<body>

<?php if ($step === 3): ?>
<!-- ═══════════════════════════════════════════════════════
     STEP 3 — Gate opened, vehicle registered
     ═══════════════════════════════════════════════════════ -->
<div class="result-band band-open">
  <div class="step-badge">Entry Complete</div>
  <div class="result-headline">🔓 GATE OPEN</div>
  <div class="result-sub">Vehicle registered for LPR exit</div>
  <div class="gate-chip <?= $gateOk ? 'ok' : 'fail' ?>">
    <?= $gateOk ? '✅ Gate opening…' : '⚠️ Gate trigger not configured' ?>
  </div>
</div>

<div class="detail-card">
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
    <span class="detail-label">Plate</span>
    <span class="detail-value plate-display"><?= htmlspecialchars($plateDisplay) ?></span>
  </div>
  <?php if (!empty($dispMake) || !empty($dispModel)): ?>
  <div class="detail-row">
    <span class="detail-label">Vehicle</span>
    <span class="detail-value"><?= htmlspecialchars(trim($dispMake . ' ' . $dispModel)) ?></span>
  </div>
  <?php endif; ?>
  <?php if (!empty($dispColour)): ?>
  <div class="detail-row">
    <span class="detail-label">Colour</span>
    <span class="detail-value"><?= htmlspecialchars($dispColour) ?></span>
  </div>
  <?php endif; ?>
  <?php if (!empty($dispExpiry)): ?>
  <div class="detail-row">
    <span class="detail-label">Disc Expires</span>
    <span class="detail-value"><?= date('d M Y', strtotime($dispExpiry)) ?></span>
  </div>
  <?php endif; ?>
  <div class="detail-row">
    <span class="detail-label">Valid Until</span>
    <span class="detail-value"><?= $fmtTo ?></span>
  </div>
  <div class="detail-row">
    <span class="detail-label">Guard</span>
    <span class="detail-value"><?= htmlspecialchars($guardName) ?></span>
  </div>
</div>

<?php elseif ($step === 2): ?>
<!-- ═══════════════════════════════════════════════════════
     STEP 2 — Valid pass, now capture the plate
     ═══════════════════════════════════════════════════════ -->
<div class="result-band band-step2">
  <div class="step-badge">Step 2 of 3 — Register Vehicle</div>
  <div class="result-headline">✅ VALID PASS</div>
  <div class="result-sub"><?= htmlspecialchars($visitorName) ?> → <?= htmlspecialchars($residentName) ?></div>
</div>

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
</div>

<form class="plate-section" method="POST" id="plateForm" autocomplete="off">
  <?= csrfField() ?>
  <input type="hidden" name="entry_code"      value="<?= htmlspecialchars($code) ?>">
  <input type="hidden" name="vehicle_make"    id="hidMake">
  <input type="hidden" name="vehicle_model"   id="hidModel">
  <input type="hidden" name="vehicle_colour"  id="hidColour">
  <input type="hidden" name="vehicle_body"    id="hidBody">
  <input type="hidden" name="vehicle_vin"     id="hidVin">
  <input type="hidden" name="licence_expiry"  id="hidExpiry">

  <h2>🚗 Scan Licence Disc or Enter Plate</h2>

  <div class="plate-input-wrap">
    <input type="text" id="plateInput" name="plate"
           class="plate-input"
           placeholder="e.g. YRB 698 W"
           value="<?= htmlspecialchars($prePlate) ?>"
           maxlength="15"
           inputmode="text"
           autocomplete="off"
           autocorrect="off"
           spellcheck="false"
           required>
    <button type="button" class="scan-icon-btn" id="scanBtn" title="Scan licence disc barcode">📷</button>
  </div>
  <p class="hint">Scan the barcode on the licence disc, use a handheld scanner, or type manually</p>

  <!-- Vehicle details card — shown after a successful disc scan -->
  <div id="vehicle-info">
    <div class="vi-row"><span class="vi-label">Make / Model</span><span class="vi-value" id="viMakeModel"></span></div>
    <div class="vi-row"><span class="vi-label">Colour</span>     <span class="vi-value" id="viColour"></span></div>
    <div class="vi-row"><span class="vi-label">Body type</span>  <span class="vi-value" id="viBody"></span></div>
    <div class="vi-row"><span class="vi-label">VIN</span>        <span class="vi-value" id="viVin"></span></div>
    <div class="vi-row"><span class="vi-label">Disc expires</span><span class="vi-value" id="viExpiry"></span></div>
  </div>

  <!-- Camera viewer -->
  <div id="camera-wrap">
    <video id="camera-video" autoplay muted playsinline></video>
    <div id="camera-aim"></div>
    <div id="camera-status">Scanning for barcode…</div>
    <button type="button" id="camera-close">✕</button>
  </div>

  <button type="submit" class="open-gate-btn" id="openBtn" disabled>
    🔓 Open Gate
  </button>
</form>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════
     STEP 1 — Invalid / STOP
     ═══════════════════════════════════════════════════════ -->
<div class="result-band band-stop">
  <div class="result-headline">⛔ STOP</div>
  <div class="result-sub"><?= htmlspecialchars($reason) ?></div>
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
    <span class="detail-label">Valid</span>
    <span class="detail-value"><?= $fmtFrom ?> – <?= $fmtTo ?></span>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="btn-row">
  <a href="guard.php?action=verify" class="btn btn-yellow">← Gate</a>
  <a href="security.php?action=menu" class="btn btn-grey">Menu</a>
</div>

<?php if ($step === 2): ?>
<!-- ZXing barcode scanner for licence disc PDF417 -->
<script src="https://unpkg.com/@zxing/library@0.20.0/umd/index.min.js"></script>
<script>
(function () {
  const plateInput = document.getElementById('plateInput');
  const openBtn    = document.getElementById('openBtn');
  const scanBtn    = document.getElementById('scanBtn');
  const cameraWrap = document.getElementById('camera-wrap');
  const video      = document.getElementById('camera-video');
  const status     = document.getElementById('camera-status');
  const closeBtn   = document.getElementById('camera-close');

  // Enable gate button only when plate has value
  function checkPlate() {
    const v = plateInput.value.replace(/[^A-Z0-9]/gi, '');
    openBtn.disabled = v.length < 2;
  }
  plateInput.addEventListener('input', function() {
    this.value = this.value.toUpperCase();
    checkPlate();
  });
  checkPlate(); // run on load (pre-filled plate)

  // ── Parse SA licence disc barcode ─────────────────────
  // Format: %F0%F1%F2%F3%F4%REG%DISC_NO%BODY%MAKE%MODEL%COLOUR%VIN%ENGINE%EXPIRY%
  // Field indices (0-based, after splitting on % and removing empty strings):
  //   5 = Registration number (e.g. CBS75462)
  //   6 = Licence disc number (e.g. YRB698W)
  //   7 = Body type bilingual (e.g. "Station wagon / Stasiewa")
  //   8 = Make  (e.g. NISSAN)
  //   9 = Model (e.g. X-TRAIL)
  //  10 = Colour bilingual (e.g. "Grey / Grys")
  //  11 = VIN
  //  12 = Engine number
  //  13 = Licence expiry date (YYYY-MM-DD)
  function parseLicenceDisc(raw) {
    if (raw.includes('%')) {
      const f = raw.split('%').map(s => s.trim()).filter(Boolean);
      if (f.length >= 6) {
        return {
          plate:  f[5]  || '',
          body:   englishOnly(f[7]  || ''),
          make:   f[8]  || '',
          model:  f[9]  || '',
          colour: englishOnly(f[10] || ''),
          vin:    f[11] || '',
          expiry: f[13] || '',
        };
      }
    }
    // Fallback: regex scan for SA registration pattern
    const m = raw.toUpperCase().match(/\b([A-Z]{1,3}\d{3}[A-Z0-9]{1,3})\b/);
    return { plate: m ? m[1] : raw.trim() };
  }

  // Extract the English portion of a bilingual field ("Grey / Grys" → "Grey")
  function englishOnly(s) {
    return (s.split('/')[0] || s).trim();
  }

  function formatExpiry(s) {
    if (!s) return '';
    const d = new Date(s);
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString('en-ZA', { day:'2-digit', month:'short', year:'numeric' });
  }

  // Populate the vehicle info card from a parsed disc object
  function showVehicleInfo(disc) {
    if (!disc.make && !disc.model) return;
    document.getElementById('viMakeModel').textContent = [disc.make, disc.model].filter(Boolean).join(' ');
    document.getElementById('viColour').textContent    = disc.colour || '—';
    document.getElementById('viBody').textContent      = disc.body   || '—';
    document.getElementById('viVin').textContent       = disc.vin    || '—';

    const expiryEl  = document.getElementById('viExpiry');
    const expiryStr = formatExpiry(disc.expiry);
    expiryEl.textContent = expiryStr || '—';
    // Warn if disc expires within 30 days or already expired
    if (disc.expiry) {
      const daysLeft = (new Date(disc.expiry) - new Date()) / 86400000;
      expiryEl.className = 'vi-value ' + (daysLeft < 30 ? 'vi-expiry-warn' : 'vi-expiry-ok');
    }

    // Fill hidden form fields so PHP can save them
    document.getElementById('hidMake').value   = disc.make   || '';
    document.getElementById('hidModel').value  = disc.model  || '';
    document.getElementById('hidColour').value = disc.colour || '';
    document.getElementById('hidBody').value   = disc.body   || '';
    document.getElementById('hidVin').value    = disc.vin    || '';
    document.getElementById('hidExpiry').value = disc.expiry || '';

    document.getElementById('vehicle-info').style.display = 'block';
  }

  // ── Camera scanner using ZXing ─────────────────────────
  let codeReader = null;
  let scanning   = false;

  scanBtn.addEventListener('click', async function () {
    if (!window.ZXing) {
      alert('Barcode scanner not loaded — enter plate manually or retry.');
      return;
    }
    cameraWrap.style.display = 'block';
    scanBtn.style.display    = 'none';
    status.textContent = 'Starting camera…';

    try {
      codeReader = new ZXing.BrowserMultiFormatReader();
      const devices = await ZXing.BrowserCodeReader.listVideoInputDevices();
      const backCam = devices.find(d => /back|rear|environment/i.test(d.label))
                   || devices[devices.length - 1]; // prefer rear camera

      scanning = true;
      codeReader.decodeFromVideoDevice(
        backCam ? backCam.deviceId : undefined,
        'camera-video',
        (result, err) => {
          if (!scanning) return;
          if (result) {
            scanning = false;
            stopCamera();
            const disc = parseLicenceDisc(result.getText());
            plateInput.value = disc.plate;
            checkPlate();
            showVehicleInfo(disc);
            plateInput.focus();
            plateInput.style.background = '#d4edda';
            setTimeout(() => plateInput.style.background = '#fff', 900);
          } else if (err && !(err instanceof ZXing.NotFoundException)) {
            status.textContent = 'Scanning… (hold disc steady)';
          }
        }
      );
      status.textContent = 'Point camera at the barcode on the licence disc';
    } catch (e) {
      cameraWrap.style.display = 'none';
      scanBtn.style.display    = '';
      alert('Camera error: ' + e.message + '\n\nPlease enter plate manually.');
    }
  });

  function stopCamera() {
    scanning = false;
    if (codeReader) {
      try { codeReader.reset(); } catch(e){}
      codeReader = null;
    }
    cameraWrap.style.display = 'none';
    scanBtn.style.display    = '';
  }

  closeBtn.addEventListener('click', stopCamera);

  // Stop camera if user navigates away
  window.addEventListener('pagehide', stopCamera);
})();
</script>
<?php endif; ?>

</body>
</html>
<?php

// ══════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════

function triggerGate(): array {
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
            'token' => $token, 'ts' => $timestamp, 'sig' => $sig,
        ]),
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)          return ['ok' => false, 'msg' => 'cURL: ' . $err];
    if ($http === 200) return ['ok' => true,  'msg' => 'Opened'];
    return ['ok' => false, 'msg' => "HTTP {$http}"];
}

function saveActiveVisit(
    int     $visitorId,
    string  $visitorName,
    string  $code,
    string  $plate,         // clean: no spaces
    string  $plateDisplay,  // formatted: with spaces
    string  $validUntil,
    int     $guardId,
    string  $guardName,
    string  $vMake   = '',
    string  $vModel  = '',
    string  $vColour = '',
    string  $vBody   = '',
    string  $vVin    = '',
    ?string $vExpiry = null
): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS active_vehicle_visits (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            visitor_id      INT          NOT NULL,
            visitor_name    VARCHAR(255) NOT NULL DEFAULT '',
            visit_code      VARCHAR(20)  NOT NULL DEFAULT '',
            plate_clean     VARCHAR(20)  NOT NULL,
            plate_display   VARCHAR(25)  NOT NULL DEFAULT '',
            valid_until     DATE         NOT NULL,
            entered_at      DATETIME     DEFAULT CURRENT_TIMESTAMP,
            exited_at       DATETIME     NULL,
            guard_id        INT          NOT NULL DEFAULT 0,
            guard_name      VARCHAR(100) NOT NULL DEFAULT '',
            vehicle_make    VARCHAR(50)  NOT NULL DEFAULT '',
            vehicle_model   VARCHAR(50)  NOT NULL DEFAULT '',
            vehicle_colour  VARCHAR(50)  NOT NULL DEFAULT '',
            vehicle_body    VARCHAR(60)  NOT NULL DEFAULT '',
            vehicle_vin     VARCHAR(20)  NOT NULL DEFAULT '',
            licence_expiry  DATE         NULL,
            INDEX idx_plate   (plate_clean, exited_at),
            INDEX idx_visitor (visitor_id),
            INDEX idx_valid   (valid_until, exited_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Add vehicle detail columns to older tables (safe to re-run)
        $cols = ['vehicle_make VARCHAR(50) NOT NULL DEFAULT \'\'',
                 'vehicle_model VARCHAR(50) NOT NULL DEFAULT \'\'',
                 'vehicle_colour VARCHAR(50) NOT NULL DEFAULT \'\'',
                 'vehicle_body VARCHAR(60) NOT NULL DEFAULT \'\'',
                 'vehicle_vin VARCHAR(20) NOT NULL DEFAULT \'\'',
                 'licence_expiry DATE NULL'];
        foreach ($cols as $col) {
            try { db()->exec("ALTER TABLE active_vehicle_visits ADD COLUMN $col"); }
            catch (Exception $e) {} // already exists
        }

        // Close any previous open visit for this plate (safety net)
        db()->prepare(
            "UPDATE active_vehicle_visits SET exited_at = NOW()
             WHERE plate_clean = ? AND exited_at IS NULL"
        )->execute([$plate]);

        db()->prepare(
            "INSERT INTO active_vehicle_visits
               (visitor_id, visitor_name, visit_code, plate_clean, plate_display,
                valid_until, guard_id, guard_name,
                vehicle_make, vehicle_model, vehicle_colour, vehicle_body,
                vehicle_vin, licence_expiry)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $visitorId, $visitorName, $code,
            $plate, $plateDisplay, $validUntil,
            $guardId, $guardName,
            $vMake, $vModel, $vColour, $vBody,
            $vVin, $vExpiry
        ]);
    } catch (Exception $e) {
        error_log('MBGE saveActiveVisit: ' . $e->getMessage());
    }
}

function logGateEvent(int $vid, string $code, string $status,
                      string $method, string $note,
                      int $guardId = 0, string $guardName = ''): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS visitor_gate_log (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            visitor_id  INT          NOT NULL,
            code        VARCHAR(20)  NOT NULL,
            status      VARCHAR(30)  NOT NULL,
            method      VARCHAR(20)  NOT NULL,
            note        VARCHAR(255),
            guard_id    INT          NOT NULL DEFAULT 0,
            guard_name  VARCHAR(100) NOT NULL DEFAULT '',
            logged_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_code    (code),
            INDEX idx_visitor (visitor_id),
            INDEX idx_guard   (guard_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            db()->exec("ALTER TABLE visitor_gate_log
                ADD COLUMN guard_id   INT          NOT NULL DEFAULT 0  AFTER note,
                ADD COLUMN guard_name VARCHAR(100) NOT NULL DEFAULT '' AFTER guard_id");
        } catch (Exception $e) {}

        db()->prepare(
            "INSERT INTO visitor_gate_log
               (visitor_id, code, status, method, note, guard_id, guard_name)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$vid, $code, $status, $method, $note, $guardId, $guardName]);
    } catch (Exception $e) {
        error_log('MBGE logGateEvent: ' . $e->getMessage());
    }
}

function getResEmailByCode(string $code): string {
    try {
        $stmt = db()->prepare("
            SELECT r.email
            FROM visitors v
            JOIN residents r ON UPPER(r.resident_erfno) = UPPER(v.resident_erfno)
                             AND r.is_primary = 1
            WHERE v.code = ? LIMIT 1
        ");
        $stmt->execute([$code]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Exception $e) {
        return '';
    }
}
