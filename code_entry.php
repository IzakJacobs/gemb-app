<?php
/**
 * code_entry.php  — Guard manual code entry + QR scan router
 * ─────────────────────────────────────────────────────────────────────────────
 * Code routing by first digit:
 *   3XXXXX  → visitor_qr_verify.php    (visitor pass)
 *   7XXXXX  → service_qr_verify.php    (service provider pass)
 *
 * This page is the guard's default gate screen.
 * It also handles the QR scanner redirect (scanner posts the code here).
 * ─────────────────────────────────────────────────────────────────────────────
 */
session_start();
require_once 'config.php';

if (!isset($_SESSION['security_logged_in']) && !isset($_SESSION['admin_logged_in'])) {
    header('Location: security_login.php');
    exit;
}

$error = '';

/* ── handle submitted code (manual entry OR QR scanner redirect) ── */
if (!empty($_REQUEST['code'])) {
    $raw  = strtoupper(preg_replace('/[^0-9]/', '', trim($_REQUEST['code'])));
    $manual = isset($_POST['code']) ? '&manual=1' : '';   // POST = typed, GET = scanned

    if (!preg_match('/^\d{6}$/', $raw)) {
        $error = 'Please enter a valid 6-digit code.';
    } else {
        $prefix = $raw[0];
        switch ($prefix) {
            case '3':
                header("Location: visitor_qr_verify.php?code={$raw}{$manual}");
                exit;
            case '7':
                header("Location: service_qr_verify.php?code={$raw}{$manual}");
                exit;
            default:
                $error = "Unknown code type (starts with {$prefix}). "
                       . "Visitor codes start with 3, service provider codes with 7.";
        }
    }
}

$pageTitle   = 'Gate Entry';
$pageHeading = 'Gate Access';
require 'header.php';
?>
<style>
  .gate-wrap {
    min-height: 80vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
  }
  .gate-card {
    background: var(--white);
    border-radius: 16px;
    padding: 28px 24px;
    width: 100%;
    max-width: 400px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
  }
  .gate-title {
    font-family: var(--font-main);
    font-size: 1.3rem;
    font-weight: 800;
    color: var(--navy);
    margin-bottom: 6px;
    text-align: center;
  }
  .gate-sub {
    font-size: 0.85rem;
    color: var(--grey);
    text-align: center;
    margin-bottom: 22px;
  }
  .code-input {
    width: 100%;
    font-family: 'Courier New', monospace;
    font-size: 2rem;
    font-weight: 800;
    letter-spacing: 0.3em;
    text-align: center;
    padding: 14px 12px;
    border: 2px solid #dde3ea;
    border-radius: 10px;
    color: var(--navy);
    background: #f5f7fa;
    outline: none;
    margin-bottom: 16px;
    -webkit-appearance: none;
  }
  .code-input:focus { border-color: var(--navy); background: #fff; }
  .btn-verify {
    width: 100%;
    min-height: 58px;
    background: var(--navy);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 1.1rem;
    font-weight: 800;
    cursor: pointer;
    margin-bottom: 14px;
  }
  .btn-verify:active { transform: scale(0.98); }
  .divider {
    text-align: center;
    color: var(--grey);
    font-size: 0.8rem;
    margin: 6px 0 14px;
    position: relative;
  }
  .divider::before, .divider::after {
    content: '';
    position: absolute;
    top: 50%;
    width: 38%;
    height: 1px;
    background: #dde3ea;
  }
  .divider::before { left: 0; }
  .divider::after  { right: 0; }
  .btn-scan {
    width: 100%;
    min-height: 58px;
    background: #ffdd00;
    color: #000;
    border: none;
    border-radius: 12px;
    font-size: 1.1rem;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
  }
  .btn-scan:active { transform: scale(0.98); }
  .hint-row {
    display: flex;
    justify-content: space-between;
    margin-top: 18px;
    gap: 10px;
  }
  .hint {
    flex: 1;
    background: #f5f7fa;
    border-radius: 10px;
    padding: 10px 8px;
    text-align: center;
    font-size: 0.78rem;
    color: var(--grey);
    line-height: 1.4;
  }
  .hint strong { display: block; font-size: 1rem; color: var(--navy); margin-bottom: 2px; }
  .error-msg {
    background: #fdecea;
    border: 1px solid #e74c3c;
    color: #a00;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.9rem;
    margin-bottom: 14px;
    text-align: center;
  }
</style>

<div class="gate-wrap">
  <div class="gate-card">
    <div class="gate-title">🏌️ GEMB Gate Entry</div>
    <div class="gate-sub">Enter 6-digit code or scan visitor QR</div>

    <?php if ($error): ?>
      <div class="error-msg"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="code_entry.php">
      <input
        type="tel"
        name="code"
        class="code-input"
        placeholder="_ _ _ _ _ _"
        maxlength="6"
        pattern="\d{6}"
        inputmode="numeric"
        autofocus
        autocomplete="off"
        value="<?= htmlspecialchars($_POST['code'] ?? '') ?>"
      >
      <button type="submit" class="btn-verify">✅ Verify Code</button>
    </form>

    <div class="divider">or</div>

    <!-- QR Scanner: opens camera, decodes QR, submits code via JS -->
    <button class="btn-scan" onclick="startQrScan()">
      📷 Scan QR Code
    </button>

    <div class="hint-row">
      <div class="hint"><strong>3XXXXX</strong>Visitor pass</div>
      <div class="hint"><strong>7XXXXX</strong>Service provider</div>
    </div>
  </div>
</div>

<!-- QR scanner uses jsQR (lightweight, no app install needed) -->
<div id="scanner-overlay" style="display:none; position:fixed; inset:0; background:#000; z-index:999;
     flex-direction:column; align-items:center; justify-content:center;">
  <video id="qr-video" style="width:100%; max-width:480px; border-radius:12px;" playsinline></video>
  <canvas id="qr-canvas" style="display:none;"></canvas>
  <p style="color:#fff; margin-top:16px; font-size:1rem;">Point camera at QR code…</p>
  <button onclick="stopQrScan()"
    style="margin-top:16px; padding:14px 32px; background:#ffdd00; color:#000;
           border:none; border-radius:10px; font-size:1rem; font-weight:800; cursor:pointer;">
    ✕ Cancel
  </button>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
let stream = null;
let scanLoop = null;

function startQrScan() {
  document.getElementById('scanner-overlay').style.display = 'flex';
  const video  = document.getElementById('qr-video');
  const canvas = document.getElementById('qr-canvas');
  const ctx    = canvas.getContext('2d');

  navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
    .then(s => {
      stream = s;
      video.srcObject = s;
      video.play();
      scanLoop = setInterval(() => {
        if (video.readyState !== video.HAVE_ENOUGH_DATA) return;
        canvas.width  = video.videoWidth;
        canvas.height = video.videoHeight;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        const img  = ctx.getImageData(0, 0, canvas.width, canvas.height);
        const qr   = jsQR(img.data, img.width, img.height);
        if (qr && qr.data) {
          stopQrScan();
          // QR payload is a URL — extract the code parameter
          try {
            const url  = new URL(qr.data);
            const code = url.searchParams.get('code');
            if (code) {
              window.location.href = resolveVerifyUrl(code);
            } else {
              alert('QR code does not contain a valid pass code.');
            }
          } catch(e) {
            // Not a URL — might be a raw code
            const raw = qr.data.trim().replace(/\D/g,'');
            if (/^\d{6}$/.test(raw)) {
              window.location.href = resolveVerifyUrl(raw);
            } else {
              alert('Unrecognised QR: ' + qr.data);
            }
          }
        }
      }, 200);
    })
    .catch(err => {
      stopQrScan();
      alert('Camera error: ' + err.message);
    });
}

function resolveVerifyUrl(code) {
  const prefix = code[0];
  if (prefix === '3') return 'visitor_qr_verify.php?code=' + encodeURIComponent(code);
  if (prefix === '7') return 'service_qr_verify.php?code=' + encodeURIComponent(code);
  return 'code_entry.php?code=' + encodeURIComponent(code);
}

function stopQrScan() {
  clearInterval(scanLoop);
  if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
  document.getElementById('scanner-overlay').style.display = 'none';
}
</script>

<?php require 'footer.php'; ?>
