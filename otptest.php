<?php
// ============================================================
// otptest.php — OTP email diagnostic (DELETE AFTER USE)
// Tests the exact generateEmailOtp() code path used by admin.php
// Upload to public_html/, open in browser, then DELETE.
// ============================================================

// Load config (works on live server with config.php)
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
    header('Location: ' . $loginPage);
    exit;
}

function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$targetEmail = 'imjac123@gmail.com';
$results     = [];
$otpResult   = null;
$rawOtp      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {

    // 1. Check twilio_helper.php exists
    $helperPath = __DIR__ . '/twilio_helper.php';
    if (!file_exists($helperPath)) {
        $results[] = ['FAIL', 'twilio_helper.php NOT FOUND at ' . $helperPath];
    } else {
        $results[] = ['OK',   'twilio_helper.php found'];

        require_once $helperPath;

        // 2. Check generateEmailOtp() is defined
        if (!function_exists('generateEmailOtp')) {
            $results[] = ['FAIL', 'generateEmailOtp() is NOT defined in twilio_helper.php'];
        } else {
            $results[] = ['OK', 'generateEmailOtp() is defined'];

            // 3. Check otp_tokens table
            try {
                // Try PDO first (live server), then MySQLi (repo)
                if (function_exists('db') && (db() instanceof PDO)) {
                    $check = db()->query("SHOW TABLES LIKE 'otp_tokens'")->fetchAll();
                    if (empty($check)) {
                        $results[] = ['FAIL', 'otp_tokens table does NOT exist in the database'];
                    } else {
                        $results[] = ['OK', 'otp_tokens table exists'];
                        // Show last 3 rows
                        $rows = db()->query("SELECT * FROM otp_tokens ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
                        if ($rows) {
                            $results[] = ['INFO', 'Last 3 otp_tokens rows: ' . json_encode($rows)];
                        } else {
                            $results[] = ['INFO', 'otp_tokens table is empty'];
                        }
                    }
                } elseif (function_exists('db') && (db() instanceof mysqli)) {
                    $r = db()->query("SHOW TABLES LIKE 'otp_tokens'");
                    if (!$r || $r->num_rows === 0) {
                        $results[] = ['FAIL', 'otp_tokens table does NOT exist in the database'];
                    } else {
                        $results[] = ['OK', 'otp_tokens table exists'];
                    }
                } else {
                    $results[] = ['INFO', 'Cannot check otp_tokens — db() not available in this context'];
                }
            } catch (Throwable $ex) {
                $results[] = ['WARN', 'DB check error: ' . $ex->getMessage()];
            }

            // 4. Call generateEmailOtp() and capture result
            ob_start();
            try {
                $otpResult = generateEmailOtp($targetEmail);
            } catch (Throwable $ex) {
                $results[] = ['FAIL', 'generateEmailOtp() threw exception: ' . $ex->getMessage()];
                $otpResult = false;
            }
            $output = ob_get_clean();
            if ($output) {
                $results[] = ['INFO', 'Output captured: ' . esc($output)];
            }

            if ($otpResult === true) {
                $results[] = ['OK', "generateEmailOtp('{$targetEmail}') returned TRUE — email queued"];
            } else {
                $results[] = ['FAIL', "generateEmailOtp('{$targetEmail}') returned FALSE — email NOT sent"];
            }

            // 5. Show what was written to otp_tokens
            try {
                if (function_exists('db') && (db() instanceof PDO)) {
                    $rows = db()->query("SELECT * FROM otp_tokens ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
                    if ($rows) {
                        $results[] = ['INFO', 'Latest otp_tokens rows after send: ' . json_encode($rows)];
                    }
                }
            } catch (Throwable $ex) { /* ignore */ }
        }
    }

    // 6. Show PHP error log tail (last 20 lines of error_log if accessible)
    $errLog = ini_get('error_log');
    if ($errLog && file_exists($errLog) && is_readable($errLog)) {
        $lines = array_slice(file($errLog), -20);
        $relevant = array_filter($lines, fn($l) => stripos($l, 'smtp') !== false || stripos($l, 'mail') !== false || stripos($l, 'otp') !== false);
        if ($relevant) {
            $results[] = ['INFO', 'Recent PHP error log (SMTP/mail/OTP lines):<br><pre style="font-size:11px;margin:4px 0 0;white-space:pre-wrap;">'
                . esc(implode('', $relevant)) . '</pre>'];
        } else {
            $results[] = ['INFO', 'No SMTP/mail/OTP lines found in error log'];
        }
    } else {
        $results[] = ['INFO', 'PHP error_log not readable from web: ' . esc((string)$errLog)];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>OTP Email Test</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:#f0f4f8;padding:32px 16px}
    .card{background:#fff;border-radius:12px;padding:24px;max-width:700px;margin:0 auto;box-shadow:0 2px 10px rgba(0,0,0,.1)}
    h2{color:#002855;margin-bottom:6px}
    p{font-size:13px;color:#555;margin:8px 0 18px}
    .btn{background:#002855;color:#fff;border:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer}
    .btn:hover{background:#001a3a}
    table{width:100%;border-collapse:collapse;margin-top:18px;font-size:13px}
    td{padding:8px 10px;border-bottom:1px solid #eee;vertical-align:top}
    .ok {color:#155724;font-weight:700}
    .fail{color:#721c24;font-weight:700}
    .warn{color:#856404;font-weight:700}
    .info{color:#0c5460;font-weight:700}
    .warn-box{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px;font-size:12px;margin-top:18px;color:#856404}
  </style>
</head>
<body>
<div class="card">
  <h2>OTP Email Diagnostic</h2>
  <p>
    Tests the exact <code>generateEmailOtp()</code> call that <code>admin.php</code> makes when a new device is detected.<br>
    Target: <strong><?= esc($targetEmail) ?></strong>
  </p>

  <?php if (!empty($results)): ?>
  <table>
    <?php foreach ($results as [$status, $msg]): ?>
    <tr>
      <td style="width:60px;" class="<?= strtolower($status) ?>"><?= $status ?></td>
      <td><?= is_string($msg) ? $msg : esc((string)$msg) ?></td>
    </tr>
    <?php endforeach ?>
  </table>

  <?php if ($otpResult === true): ?>
    <div style="background:#d4edda;color:#155724;border:1px solid #b7dfbb;border-radius:8px;padding:12px;margin-top:16px;font-size:14px;">
      <strong>Email sent.</strong> Check <strong><?= esc($targetEmail) ?></strong> — including the <strong>Spam / Junk</strong> folder.
      If it arrived in spam, the content triggers Gmail's filter (common for "Login Code" subject lines from shared hosting).
    </div>
  <?php else: ?>
    <div style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;border-radius:8px;padding:12px;margin-top:16px;font-size:14px;">
      <strong>Email not sent.</strong> Check the FAIL entries above and cPanel &rarr; Logs &rarr; Error Log for PHP errors.
    </div>
  <?php endif ?>

  <?php else: ?>
  <form method="POST">
    <input type="hidden" name="action" value="run">
    <button class="btn" type="submit">Run OTP Email Test</button>
  </form>
  <?php endif ?>

  <div class="warn-box">
    <strong>Security reminder:</strong> Delete <code>otptest.php</code> from your server immediately after use.<br>
    cPanel &rarr; File Manager &rarr; public_html &rarr; otptest.php &rarr; Delete
  </div>
</div>
</body>
</html>
