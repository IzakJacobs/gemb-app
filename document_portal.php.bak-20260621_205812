<?php
// ============================================================
// GEMB Document Portal
// Actions: menu | broadcast | levy | log
// ============================================================
require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();

requireAdmin();

$action = $_GET['action'] ?? 'menu';

// ── Upload directories ────────────────────────────────────
$docDir  = __DIR__ . '/uploads/documents/';
$docUrl  = SITE_URL . '/uploads/documents/';
if (!is_dir($docDir)) mkdir($docDir, 0755, true);

// ════════════════════════════════════════════════════════
// MENU — list all broadcasts
// ════════════════════════════════════════════════════════
if ($action === 'menu') {
    $broadcasts = db()->query("
        SELECT * FROM document_broadcasts
        ORDER BY created_at DESC
        LIMIT 100
    ")->fetchAll();

    pageHeader('Document Portal', 'admin');
    renderHeader('📄 Document Portal', 'admin.php?action=menu');
    ?>
    <div class="container">
      <?= getFlash() ?>

      <div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="document_portal.php?action=broadcast" class="btn btn-primary">
          📤 Send Circular / PDF
        </a>
        <a href="document_portal.php?action=levy" class="btn btn-success">
          💰 Import Levy Notices
        </a>
      </div>

      <div class="card">
        <div class="card-title">Send History</div>
        <?php if (empty($broadcasts)): ?>
          <p style="color:#666;">No documents sent yet.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr>
            <th>Date</th><th>Type</th><th>Title</th>
            <th>Sent</th><th>Failed</th><th>Actions</th>
          </tr>
          <?php foreach ($broadcasts as $b): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.85rem;">
              <?= date('d M Y H:i', strtotime($b['created_at'])) ?>
            </td>
            <td>
              <?php if ($b['type'] === 'circular'): ?>
                <span class="badge badge-info">📄 Circular</span>
              <?php else: ?>
                <span class="badge badge-success">💰 Levy</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td style="color:#28a745;font-weight:700;"><?= $b['sent_to'] ?></td>
            <td style="color:<?= $b['failed']>0?'#dc3545':'#999' ?>;font-weight:700;">
              <?= $b['failed'] ?>
            </td>
            <td>
              <a href="document_portal.php?action=log&id=<?= $b['id'] ?>"
                 class="btn btn-secondary btn-sm">View Log</a>
              <?php if ($b['type'] === 'circular' && $b['filename']): ?>
              <a href="<?= htmlspecialchars($docUrl . $b['filename']) ?>"
                 target="_blank" class="btn btn-primary btn-sm">📥 PDF</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// BROADCAST — upload PDF and send to all residents
// ════════════════════════════════════════════════════════
if ($action === 'broadcast') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $title = trim($_POST['title'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!$title) {
            setFlash('error', 'Title is required.');
            header('Location: document_portal.php?action=broadcast'); exit;
        }
        if (empty($_FILES['pdf']['tmp_name'])) {
            setFlash('error', 'Please select a PDF file.');
            header('Location: document_portal.php?action=broadcast'); exit;
        }

        // Validate PDF
        $file     = $_FILES['pdf'];
        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            setFlash('error', 'Only PDF files are allowed.');
            header('Location: document_portal.php?action=broadcast'); exit;
        }
        if ($file['size'] > 10 * 1024 * 1024) {
            setFlash('error', 'File too large — maximum 10 MB.');
            header('Location: document_portal.php?action=broadcast'); exit;
        }

        // Store with unique name
        $stored = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME)) . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $docDir . $stored)) {
            setFlash('error', 'File upload failed. Check server permissions.');
            header('Location: document_portal.php?action=broadcast'); exit;
        }

        // Create broadcast record
        db()->prepare("
            INSERT INTO document_broadcasts (type, title, filename, original_name, notes, sent_by)
            VALUES ('circular', ?, ?, ?, ?, ?)
        ")->execute([$title, $stored, $origName, $notes ?: null, $_SESSION['admin_id'] ?? null]);
        $broadcastId = (int)db()->lastInsertId();

        // Get all active resident emails
        $residents = db()->query("
            SELECT DISTINCT email, resident_name
            FROM residents
            WHERE status = 'active'
              AND email IS NOT NULL
              AND email != ''
              AND is_primary = 1
        ")->fetchAll();

        $downloadUrl = $docUrl . $stored;
        $sent = 0; $failed = 0;

        foreach ($residents as $r) {
            $email = filter_var(trim($r['email']), FILTER_VALIDATE_EMAIL);
            if (!$email) { $failed++; continue; }

            $name    = htmlspecialchars($r['resident_name'] ?? 'Resident');
            $subject = 'GEMB Estate — ' . $title;
            $html    = buildCircularEmail($name, $title, $downloadUrl, $notes);

            $ok = sendPortalEmail($email, $subject, $html);

            db()->prepare("
                INSERT INTO document_send_log
                  (broadcast_id, recipient_email, recipient_name, status, error_msg)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $broadcastId, $email, $r['resident_name'],
                $ok ? 'sent' : 'failed',
                $ok ? null : 'mail() returned false',
            ]);

            $ok ? $sent++ : $failed++;
        }

        // Update counts
        db()->prepare("UPDATE document_broadcasts SET sent_to=?, failed=? WHERE id=?")
            ->execute([$sent, $failed, $broadcastId]);

        setFlash('success', "Circular sent to {$sent} resident(s)." . ($failed ? " {$failed} failed." : ''));
        header('Location: document_portal.php?action=log&id=' . $broadcastId); exit;
    }

    // Count recipients preview
    $recipientCount = db()->query("
        SELECT COUNT(DISTINCT email) FROM residents
        WHERE status='active' AND email IS NOT NULL AND email != '' AND is_primary=1
    ")->fetchColumn();

    pageHeader('Send Circular', 'admin');
    renderHeader('📤 Send Circular / PDF', 'document_portal.php?action=menu');
    ?>
    <div class="container" style="max-width:600px;">
      <div class="card">
        <?= getFlash() ?>
        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
          This PDF will be emailed to <strong><?= $recipientCount ?> resident(s)</strong>
          with a download link. Max file size: 10 MB.
        </div>
        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Document Title *</label>
            <input type="text" name="title" required
                   placeholder="e.g. June 2026 AGM Minutes">
          </div>
          <div class="form-group">
            <label>PDF File *</label>
            <input type="file" name="pdf" accept=".pdf" required
                   style="padding:10px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;width:100%;">
          </div>
          <div class="form-group">
            <label>Message to Residents <small style="color:#999;">(optional)</small></label>
            <textarea name="notes" rows="3"
                      placeholder="Brief note to accompany the document…"
                      style="width:100%;padding:10px;border:1px solid #dee2e6;border-radius:6px;resize:vertical;"></textarea>
          </div>
          <button type="submit" class="btn btn-primary btn-block"
                  onclick="return confirm('Send this circular to <?= $recipientCount ?> resident(s)?')">
            📤 Send to All Residents
          </button>
        </form>
        <div class="popia-notice">Documents emailed under POPIA §11 for estate communication purposes.</div>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// LEVY — import CSV and send personal levy notices
// ════════════════════════════════════════════════════════
if ($action === 'levy') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $title   = trim($_POST['title']   ?? '');
        $subject = trim($_POST['subject'] ?? '');

        if (!$title || !$subject) {
            setFlash('error', 'Title and email subject are required.');
            header('Location: document_portal.php?action=levy'); exit;
        }
        if (empty($_FILES['csv']['tmp_name'])) {
            setFlash('error', 'Please select a CSV file.');
            header('Location: document_portal.php?action=levy'); exit;
        }

        $file = $_FILES['csv'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'])) {
            setFlash('error', 'Only CSV files are allowed.');
            header('Location: document_portal.php?action=levy'); exit;
        }

        // Parse CSV — handle BOM, semicolon delimiters, flexible column names
        $rows = [];
        if (($fh = fopen($file['tmp_name'], 'r')) !== false) {
            $header = null;
            // Auto-detect delimiter from first line (tab, semicolon, or comma)
            $firstLine = fgets($fh);
            rewind($fh);
            // Strip UTF-8 BOM if present
            $firstLine = ltrim($firstLine, "\xEF\xBB\xBF");
            $tabs      = substr_count($firstLine, "\t");
            $semis     = substr_count($firstLine, ';');
            $commas    = substr_count($firstLine, ',');
            if ($tabs >= $semis && $tabs >= $commas)       $delim = "\t";
            elseif ($semis > $commas)                      $delim = ';';
            else                                           $delim = ',';

            while (($row = fgetcsv($fh, 2000, $delim)) !== false) {
                if (!$header) {
                    // Normalise header: lowercase, strip BOM and whitespace
                    $header = array_map(function($h) {
                        return strtolower(trim(ltrim($h, "\xEF\xBB\xBF")));
                    }, $row);
                    continue;
                }
                if (count($row) < 1 || implode('', $row) === '') continue;
                $data = array_combine(
                    array_slice($header, 0, count($row)),
                    array_slice($row, 0, count($header))
                );
                $rows[] = $data;
            }
            fclose($fh);
        }

        if (empty($rows)) {
            setFlash('error', 'CSV file is empty or could not be parsed.');
            header('Location: document_portal.php?action=levy'); exit;
        }

        // Create broadcast record
        db()->prepare("
            INSERT INTO document_broadcasts
              (type, title, original_name, notes, sent_by)
            VALUES ('levy', ?, ?, ?, ?)
        ")->execute([$title, basename($file['name']), $subject, $_SESSION['admin_id'] ?? null]);
        $broadcastId = (int)db()->lastInsertId();

        $sent = 0; $failed = 0;

        foreach ($rows as $i => $row) {
            // Flexible email column matching
            $rawEmail = '';
            foreach ($row as $col => $val) {
                if (in_array(strtolower(trim($col)), ['email','e-mail','e_mail','email address','emailaddress','mail'], true)) {
                    $rawEmail = trim($val); break;
                }
            }
            // Fallback: first column that looks like an email
            if (!$rawEmail) {
                foreach ($row as $val) {
                    if (strpos($val, '@') !== false) { $rawEmail = trim($val); break; }
                }
            }

            $email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);

            if (!$email) {
                // Log invalid email rows so they show in the log
                db()->prepare("
                    INSERT INTO document_send_log
                      (broadcast_id, recipient_email, recipient_name, status, error_msg)
                    VALUES (?, ?, ?, 'failed', ?)
                ")->execute([
                    $broadcastId,
                    $rawEmail ?: "Row {$i} — no email",
                    null,
                    $rawEmail ? 'Invalid email address' : 'No email column found',
                ]);
                $failed++; continue;
            }

            $name    = trim($row['name'] ?? $row['full_name'] ?? $row['naam'] ?? $row['resident'] ?? '');
            $amount  = trim($row['amount'] ?? $row['bedrag'] ?? $row['levy'] ?? $row['amount_due'] ?? '');
            $message = trim($row['message'] ?? $row['note'] ?? $row['notes'] ?? $row['msg'] ?? $row['opmerking'] ?? '');

            // Clean amount — remove currency symbols, spaces
            $amountClean = preg_replace('/[^0-9.,]/', '', $amount);
            $amountFloat = $amountClean ? (float)str_replace(',', '.', $amountClean) : null;

            $html = buildLevyEmail($name ?: 'Resident', $amountClean, $message, $subject);
            $ok   = sendPortalEmail($email, $subject, $html);

            db()->prepare("
                INSERT INTO document_send_log
                  (broadcast_id, recipient_email, recipient_name, amount, message, status, error_msg)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $broadcastId, $email, $name ?: null,
                $amountFloat, $message ?: null,
                $ok ? 'sent' : 'failed',
                $ok ? null : 'mail() returned false',
            ]);

            $ok ? $sent++ : $failed++;
        }

        db()->prepare("UPDATE document_broadcasts SET sent_to=?, failed=? WHERE id=?")
            ->execute([$sent, $failed, $broadcastId]);

        setFlash('success', "Levy notices sent to {$sent} recipient(s)." . ($failed ? " {$failed} failed." : ''));
        header('Location: document_portal.php?action=log&id=' . $broadcastId); exit;
    }

    pageHeader('Import Levy Notices', 'admin');
    renderHeader('💰 Import Levy Notices', 'document_portal.php?action=menu');
    ?>
    <div class="container" style="max-width:640px;">
      <div class="card">
        <?= getFlash() ?>
        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
          Upload a CSV file exported from your financial system.<br>
          <strong>Required column:</strong> <code>email</code><br>
          <strong>Optional columns:</strong> <code>name, amount, message</code><br>
          In Excel: File → Save As → CSV (Comma delimited)
        </div>

        <!-- CSV format example -->
        <div style="background:#f8f9fa;border-radius:6px;padding:12px;margin-bottom:16px;font-size:.82rem;">
          <strong>Example CSV format:</strong>
          <pre style="margin:6px 0 0;color:#444;">email,name,amount,message
john@example.com,John Smith,1500.00,Levy due 30 June 2026
jane@example.com,Jane Doe,1500.00,Levy due 30 June 2026</pre>
        </div>

        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Batch Title * <small style="color:#999;">(internal reference)</small></label>
            <input type="text" name="title" required
                   placeholder="e.g. June 2026 Levy Notices">
          </div>
          <div class="form-group">
            <label>Email Subject Line *</label>
            <input type="text" name="subject" required
                   placeholder="e.g. GEMB Estate — June 2026 Levy Notice">
          </div>
          <div class="form-group">
            <label>CSV File *</label>
            <input type="file" name="csv" accept=".csv,.txt" required
                   style="padding:10px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;width:100%;">
          </div>
          <button type="submit" class="btn btn-success btn-block">
            💰 Import &amp; Send Levy Notices
          </button>
        </form>
        <div class="popia-notice">Financial data processed under POPIA §11. Send log retained for audit.</div>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// LOG — view send detail for a broadcast
// ════════════════════════════════════════════════════════
if ($action === 'log') {
    $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id) { header('Location: document_portal.php'); exit; }

    $broadcast = db()->prepare("SELECT * FROM document_broadcasts WHERE id=? LIMIT 1");
    $broadcast->execute([$id]);
    $broadcast = $broadcast->fetch();
    if (!$broadcast) { header('Location: document_portal.php'); exit; }

    $logs = db()->prepare("
        SELECT * FROM document_send_log
        WHERE broadcast_id = ?
        ORDER BY sent_at ASC
    ");
    $logs->execute([$id]);
    $logs = $logs->fetchAll();

    pageHeader('Send Log', 'admin');
    renderHeader('📋 Send Log — ' . htmlspecialchars($broadcast['title']), 'document_portal.php');
    ?>
    <div class="container">

      <!-- Summary -->
      <div class="card" style="margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">
          <?php foreach ([
              ['Type',    $broadcast['type'] === 'circular' ? '📄 Circular' : '💰 Levy', '#1565c0'],
              ['Title',   $broadcast['title'], '#333'],
              ['Date',    date('d M Y H:i', strtotime($broadcast['created_at'])), '#333'],
              ['Sent',    $broadcast['sent_to'], '#28a745'],
              ['Failed',  $broadcast['failed'],  $broadcast['failed']>0?'#dc3545':'#999'],
          ] as [$lbl,$val,$col]): ?>
          <div style="text-align:center;">
            <div style="font-size:.75rem;color:#888;margin-bottom:3px;"><?= $lbl ?></div>
            <div style="font-weight:700;color:<?= $col ?>;font-size:.95rem;">
              <?= htmlspecialchars((string)$val) ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($broadcast['type'] === 'circular' && $broadcast['filename']): ?>
        <div style="margin-top:12px;">
          <a href="<?= htmlspecialchars($docUrl . $broadcast['filename']) ?>"
             target="_blank" class="btn btn-primary btn-sm">📥 Download PDF</a>
        </div>
        <?php endif; ?>
      </div>

      <!-- Individual send log -->
      <div class="card">
        <div class="card-title">Recipients</div>
        <div class="table-wrap"><table>
          <tr>
            <th>Email</th>
            <th>Name</th>
            <?php if ($broadcast['type'] === 'levy'): ?>
            <th>Amount</th>
            <th>Message</th>
            <?php endif; ?>
            <th>Status</th>
            <th>Time</th>
          </tr>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td style="font-size:.85rem;"><?= htmlspecialchars($l['recipient_email']) ?></td>
            <td style="font-size:.85rem;"><?= htmlspecialchars($l['recipient_name'] ?? '—') ?></td>
            <?php if ($broadcast['type'] === 'levy'): ?>
            <td style="font-size:.85rem;">
              <?= $l['amount'] ? 'R '.number_format((float)$l['amount'], 2) : '—' ?>
            </td>
            <td style="font-size:.8rem;color:#666;max-width:200px;">
              <?= htmlspecialchars(mb_substr($l['message'] ?? '', 0, 80)) ?>
            </td>
            <?php endif; ?>
            <td>
              <span class="badge badge-<?= $l['status']==='sent'?'success':'danger' ?>">
                <?= $l['status'] ?>
              </span>
            </td>
            <td style="font-size:.8rem;white-space:nowrap;">
              <?= date('H:i:s', strtotime($l['sent_at'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table></div>
      </div>
    </div>
    <?php pageFooter(); exit;
}

header('Location: document_portal.php'); exit;

// ════════════════════════════════════════════════════════
// EMAIL HELPERS
// ════════════════════════════════════════════════════════

function sendPortalEmail(string $to, string $subject, string $html): bool {
    $from    = defined('MAIL_FROM') ? MAIL_FROM : 'noreply@gemb.co.za';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: GEMB Estate <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: GEMB-DocPortal/1.0',
    ]);
    return mail($to, $subject, $html, $headers);
}

function buildCircularEmail(string $name, string $title, string $url, string $notes): string {
    $notesHtml = $notes ? '<p style="color:#555;margin:16px 0;">' . nl2br(htmlspecialchars($notes)) . '</p>' : '';
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;
            box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#003366;padding:24px 28px;">
    <h1 style="color:#fff;margin:0;font-size:1.3rem;">🏡 GEMB Estate</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:.9rem;">Resident Communication</p>
  </div>
  <div style="padding:28px;">
    <p style="color:#333;margin:0 0 8px;">Dear {$name},</p>
    <p style="color:#333;margin:0 0 16px;">
      Please find the following document available for download:
    </p>
    <h2 style="color:#003366;margin:0 0 16px;font-size:1.1rem;">📄 {$title}</h2>
    {$notesHtml}
    <div style="text-align:center;margin:24px 0;">
      <a href="{$url}" target="_blank"
         style="display:inline-block;background:#003366;color:#fff;padding:14px 32px;
                border-radius:6px;text-decoration:none;font-weight:700;font-size:1rem;">
        📥 Download Document
      </a>
    </div>
    <p style="color:#888;font-size:.82rem;margin-top:24px;border-top:1px solid #eee;padding-top:16px;">
      This email was sent by GEMB Estate Management.<br>
      If you have any queries, please contact the estate office.
    </p>
  </div>
</div>
</body></html>
HTML;
}

function buildLevyEmail(string $name, string $amount, string $message, string $subject): string {
    $greeting   = $name ? "Dear {$name}," : "Dear Resident,";
    $amountHtml = $amount
        ? '<div style="background:#f0f8ff;border:2px solid #003366;border-radius:8px;
                       padding:16px;text-align:center;margin:20px 0;">
             <div style="font-size:.85rem;color:#666;margin-bottom:4px;">Amount Due</div>
             <div style="font-size:2rem;font-weight:900;color:#003366;">R ' . htmlspecialchars($amount) . '</div>
           </div>'
        : '';
    $msgHtml = $message
        ? '<p style="color:#555;margin:16px 0;">' . nl2br(htmlspecialchars($message)) . '</p>'
        : '';
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;
            box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#003366;padding:24px 28px;">
    <h1 style="color:#fff;margin:0;font-size:1.3rem;">🏡 GEMB Estate</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:.9rem;">Levy Notice</p>
  </div>
  <div style="padding:28px;">
    <p style="color:#333;margin:0 0 16px;">{$greeting}</p>
    {$amountHtml}
    {$msgHtml}
    <p style="color:#888;font-size:.82rem;margin-top:24px;border-top:1px solid #eee;padding-top:16px;">
      This levy notice was sent by GEMB Estate Management.<br>
      If you have any queries, please contact the estate office.
    </p>
  </div>
</div>
</body></html>
HTML;
}
