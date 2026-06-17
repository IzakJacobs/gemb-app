<?php
// ============================================================
// sendotp_test.php — Read OTP from otp_tokens and send via SMTP
// Upload to public_html/, run once, then DELETE immediately.
// ============================================================

// Load config (live server)
if (file_exists(__DIR__ . '/config.php') && !defined('DB_HOST')) {
    @require_once __DIR__ . '/config.php';
}

// Session + admin guard
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
$isAdmin = (!empty($_SESSION['admin_id']))
        || (!empty($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin');
if (!$isAdmin) {
    $loginPage = file_exists(__DIR__ . '/admin.php') ? 'admin.php?action=login' : 'admin_login.php';
    header('Location: ' . $loginPage); exit;
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$TARGET  = 'imjac123@gmail.com';
$results = [];
$sent    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {

    // 1. Read latest OTP row from otp_tokens
    $otp_code  = null;
    $otp_expiry = null;
    $otp_key   = null;
    try {
        $rows = db()->query("SELECT * FROM otp_tokens ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            $results[] = ['FAIL', 'otp_tokens table is empty — generate an OTP first via forgot.php'];
        } else {
            $latest    = $rows[0];
            $otp_key   = $latest['token_key']  ?? $latest['id'];
            $otp_code  = $latest['token']       ?? $latest['otp'] ?? $latest['code'] ?? null;
            $otp_expiry = $latest['expires_at'] ?? $latest['expiry'] ?? null;
            $results[] = ['OK', 'Latest otp_tokens row: ' . json_encode($latest)];

            if (!$otp_code) {
                $results[] = ['FAIL', 'Cannot find OTP value — column name differs from expected. See row above.'];
            }
            if ($otp_expiry && strtotime($otp_expiry) < time()) {
                $results[] = ['WARN', 'OTP is EXPIRED (was valid until ' . $otp_expiry . '). Generate a new one first.'];
            }
        }
    } catch (Throwable $ex) {
        $results[] = ['FAIL', 'DB error reading otp_tokens: ' . $ex->getMessage()];
    }

    // 2. Send the OTP via smtpSend() directly
    if ($otp_code) {
        require_once __DIR__ . '/smtp_mail.php';

        $subject = 'MBGE Access Control - Your Login Code';
        $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px;">'
              . '<h2 style="color:#002855;">MBGE Access Control</h2>'
              . '<p>Your one-time login code is:</p>'
              . '<div style="font-size:2.5rem;font-weight:800;letter-spacing:0.3em;color:#002855;'
              . 'background:#f0f4f8;padding:16px;text-align:center;border-radius:8px;margin:16px 0;">'
              . esc($otp_code)
              . '</div>'
              . '<p style="color:#555;">Valid for 5 minutes. Do not share this code.</p>'
              . '<p style="font-size:.8rem;color:#999;">sendotp_test.php diagnostic — delete after use.</p>'
              . '</body></html>';

        $results[] = ['INFO', 'Calling smtpSend("' . $TARGET . '", "' . $subject . '", ...)'];

        try {
            $sent = smtpSend($TARGET, $subject, $html);
        } catch (Throwable $ex) {
            $results[] = ['FAIL', 'smtpSend() threw exception: ' . $ex->getMessage()];
            $sent = false;
        }

        if ($sent === true) {
            $results[] = ['OK', 'smtpSend() returned TRUE — message handed to SMTP server'];
        } else {
            $results[] = ['FAIL', 'smtpSend() returned FALSE — see PHP error log for SMTP details'];
        }
    }

    // 3. PHP error log tail — look for SMTP lines
    $errLog = ini_get('error_log');
    if ($errLog && file_exists($errLog) && is_readable($errLog)) {
        $lines = array_slice(file($errLog), -30);
        $relevant = array_values(array_filter($lines,
            fn($l) => stripos($l, 'smtp') !== false
                   || stripos($l, 'mail') !== false
                   || stripos($l, 'phpmailer') !== false
        ));
        if ($relevant) {
            $results[] = ['INFO',
                'Recent PHP error log (SMTP lines):<pre style="font-size:11px;margin:4px 0;white-space:pre-wrap;'
              . 'background:#f8f9fa;padding:8px;border-radius:4px;">'
              . esc(implode('', array_slice($relevant, -10)))
              . '</pre>'
            ];
        } else {
            $results[] = ['INFO', 'No SMTP lines found in PHP error log (' . esc($errLog) . ')'];
        }
    } else {
        $results[] = ['INFO', 'PHP error_log not readable from web: ' . esc((string)$errLog)];
    }
}

// Show current otp_tokens content on GET
$currentRows = [];
try {
    $currentRows = db()->query("SELECT * FROM otp_tokens ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Send OTP Test</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:#f0f4f8;padding:32px 16px}
    .card{background:#fff;border-radius:12px;padding:24px;max-width:760px;margin:0 auto 20px;box-shadow:0 2px 10px rgba(0,0,0,.1)}
    h2{color:#002855;margin-bottom:6px}
    p{font-size:13px;color:#555;margin:8px 0 16px;line-height:1.5}
    .btn{background:#002855;color:#fff;border:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer}
    .btn:hover{background:#001a3a}
    table{width:100%;border-collapse:collapse;font-size:12px;margin-top:12px}
    th{background:#f0f4f8;padding:7px 10px;text-align:left;font-size:.75rem;color:#555}
    td{padding:7px 10px;border-bottom:1px solid #eee;vertical-align:top;word-break:break-all}
    .ok  {color:#155724;font-weight:700}
    .fail{color:#721c24;font-weight:700}
    .warn{color:#856404;font-weight:700}
    .info{color:#0c5460;font-weight:700}
    .result-row td:first-child{width:50px}
    .warn-box{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px;font-size:12px;margin-top:16px;color:#856404}
    .ok-box{background:#d4edda;border:1px solid #b7dfbb;border-radius:8px;padding:12px;font-size:14px;margin-top:16px;color:#155724}
    .fail-box{background:#f8d7da;border:1px solid #f5c6cb;border-radius:8px;padding:12px;font-size:14px;margin-top:16px;color:#721c24}
  </style>
</head>
<body>

<div class="card">
  <h2>Send OTP Test</h2>
  <p>
    Reads the latest code from <code>otp_tokens</code> and sends it directly
    to <strong><?= esc($TARGET) ?></strong> via <code>smtpSend()</code>.<br>
    First generate an OTP at <a href="forgot.php?role=admin">forgot.php?role=admin</a>,
    then come back here and click Send.
  </p>

  <!-- Current otp_tokens content -->
  <?php if (!empty($currentRows)): ?>
  <strong style="font-size:13px;">Current otp_tokens (latest 5):</strong>
  <div style="overflow-x:auto;">
  <table>
    <tr><?php foreach (array_keys($currentRows[0]) as $col): ?><th><?= esc($col) ?></th><?php endforeach; ?></tr>
    <?php foreach ($currentRows as $row): ?>
    <tr><?php foreach ($row as $v): ?><td><?= esc((string)$v) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php else: ?>
  <p style="color:#dc3545;">otp_tokens table is empty — generate an OTP first.</p>
  <?php endif; ?>

  <form method="POST" style="margin-top:18px;">
    <input type="hidden" name="action" value="send">
    <button class="btn" type="submit">Send OTP Email to <?= esc($TARGET) ?></button>
  </form>
</div>

<?php if (!empty($results)): ?>
<div class="card">
  <h2 style="margin-bottom:12px;">Results</h2>
  <table>
    <?php foreach ($results as [$status, $msg]): ?>
    <tr class="result-row">
      <td class="<?= strtolower($status) ?>"><?= $status ?></td>
      <td><?= $msg /* may contain pre-escaped HTML */ ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($sent === true): ?>
  <div class="ok-box">
    <strong>Email handed to SMTP.</strong><br>
    Check <strong><?= esc($TARGET) ?></strong> now — including <strong>Spam / Junk</strong>.<br>
    Also search Gmail for <code>from:admin@gemb.co.za</code> to find any silently-filtered messages.
  </div>
  <?php elseif ($sent === false): ?>
  <div class="fail-box">
    <strong>SMTP send failed.</strong><br>
    Check the error log lines above, or cPanel &rarr; Logs &rarr; Error Log.
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="warn-box">
    <strong>Delete this file immediately after use.</strong><br>
    cPanel &rarr; File Manager &rarr; public_html &rarr; sendotp_test.php &rarr; Delete
  </div>
</div>
</body>
</html>
