<?php
// ============================================================
// gemB / MBGE — comms_levy.php
// Personalised messages: levy statements via CSV upload + email
// Actions: menu | send | log | template
// ============================================================
require_once __DIR__ . '/comms_core.php';
commsRequireAuth();

$action  = $_GET['action'] ?? 'menu';
$backUrl = 'comms_menu.php';

// ════════════════════════════════════════════════════════
// MENU — levy send history
// ════════════════════════════════════════════════════════
if ($action === 'menu') {
    $broadcasts = commsBroadcastList('levy', 100);

    pageHeader('Levy Statements', 'admin');
    renderHeader('Levy Statements', $backUrl);
    ?>
    <div class="container">
      <?= getFlash() ?>

      <!-- Step 1 — Prepare CSV -->
      <div class="card" style="border-left:5px solid #1565c0;margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <span style="background:#1565c0;color:#fff;border-radius:50%;width:28px;height:28px;
                       display:flex;align-items:center;justify-content:center;
                       font-weight:800;font-size:.9rem;flex-shrink:0;">1</span>
          <div class="card-title" style="margin:0;border:none;padding:0;">Prepare Your Levy CSV File</div>
        </div>
        <p style="font-size:.88rem;color:#555;margin-bottom:12px;">
          Export levy data from your financial system as a CSV.
          Each row is one recipient.
        </p>
        <div style="background:#f8f9fa;border-radius:6px;padding:12px;margin-bottom:14px;font-size:.82rem;">
          <strong>Required column:</strong> <code>email</code> &nbsp;&nbsp;
          <strong>Optional:</strong> <code>name, amount, message, erf</code><br>
          <span style="color:#888;margin-top:4px;display:block;">
            In Excel: File &rarr; Save As &rarr; CSV (Comma delimited)
          </span>
          <pre style="margin:8px 0 0;color:#444;line-height:1.5;">email,name,amount,message
john@example.com,John Smith,1500.00,Levy due 30 June 2026
jane@example.com,Jane Doe,1500.00,Levy due 30 June 2026</pre>
        </div>
        <a href="comms_levy.php?action=template" class="btn btn-secondary btn-sm">
          Download Blank CSV Template
        </a>
      </div>

      <!-- Step 2 — Import and send -->
      <div class="card" style="border-left:5px solid #2e7d32;margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <span style="background:#2e7d32;color:#fff;border-radius:50%;width:28px;height:28px;
                       display:flex;align-items:center;justify-content:center;
                       font-weight:800;font-size:.9rem;flex-shrink:0;">2</span>
          <div class="card-title" style="margin:0;border:none;padding:0;">Import CSV &amp; Send Levy Notices</div>
        </div>
        <p style="font-size:.88rem;color:#555;margin-bottom:14px;">
          Upload your prepared CSV. Personalised notices are emailed immediately —
          one per row with name and amount pre-filled.
        </p>
        <a href="comms_levy.php?action=send" class="btn btn-success">
          Import &amp; Send Levy Notices
        </a>
      </div>

      <!-- Send history -->
      <div class="card">
        <div class="card-title">Send History</div>
        <?php if (empty($broadcasts)): ?>
          <p style="color:#666;">No levy notices sent yet.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr><th>Date</th><th>Title</th><th>Sent</th><th>Failed</th><th>Actions</th></tr>
          <?php foreach ($broadcasts as $b): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.85rem;">
              <?= date('d M Y H:i', strtotime($b['created_at'])) ?>
            </td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td style="color:#28a745;font-weight:700;"><?= (int)$b['sent_to'] ?></td>
            <td style="color:<?= $b['failed'] > 0 ? '#dc3545' : '#999' ?>;font-weight:700;">
              <?= (int)$b['failed'] ?>
            </td>
            <td>
              <a href="comms_levy.php?action=log&id=<?= (int)$b['id'] ?>"
                 class="btn btn-secondary btn-sm">View Log</a>
            </td>
          </tr>
          <?php endforeach ?>
        </table></div>
        <?php endif ?>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// SEND — import CSV and send personal levy notices
// ════════════════════════════════════════════════════════
if ($action === 'send') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $title   = trim($_POST['title']   ?? '');
        $subject = trim($_POST['subject'] ?? '');

        if (!$title || !$subject) {
            setFlash('error', 'Title and email subject are required.');
            header('Location: comms_levy.php?action=send'); exit;
        }

        // Block non-ASCII subject — the mail relay (out.tld-mx.com) rejects
        // subjects containing em dashes, curly quotes, or other special chars.
        if (!mb_check_encoding($subject, 'ASCII')) {
            setFlash('error',
                'Email subject contains special characters (such as — or curved quotes) '
              . 'that will cause delivery to fail. '
              . 'Use only plain letters, numbers and hyphens (-).');
            header('Location: comms_levy.php?action=send'); exit;
        }

        // Check PHP upload error code — gives a specific failure reason
        $uploadErr = $_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $errMap = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit ('
                                       . ini_get('upload_max_filesize')
                                       . '). Reduce the CSV size or ask your host to raise upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_FILE    => 'No file was selected. Please choose a CSV file.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temporary folder is missing. Contact your host.',
                UPLOAD_ERR_CANT_WRITE => 'Server could not save the uploaded file. Check folder permissions.',
                UPLOAD_ERR_EXTENSION  => 'Upload was blocked by a PHP extension.',
            ];
            $errMsg = $errMap[$uploadErr] ?? "Upload failed (PHP error code {$uploadErr}).";
            setFlash('error', $errMsg);
            header('Location: comms_levy.php?action=send'); exit;
        }

        $file = $_FILES['csv'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            setFlash('error', 'Only .csv or .txt files are allowed. '
                . 'In Excel use File > Save As > CSV (Comma delimited).');
            header('Location: comms_levy.php?action=send'); exit;
        }

        $rows = commsParseCsv($file['tmp_name']);

        if (empty($rows)) {
            setFlash('error',
                'CSV file is empty or could not be parsed. '
              . 'Make sure it is saved as plain CSV (not .xlsx) '
              . 'with an "email" column header in the first row.');
            header('Location: comms_levy.php?action=send'); exit;
        }

        $broadcastId = commsBroadcastCreate(
            'levy', 'levy', $title, null, basename($file['name']), $subject
        );

        $recipients = commsRecipients('levy_csv', ['rows' => $rows]);
        $sent = 0; $failed = 0;

        foreach ($recipients as $r) {
            if (!$r['email']) {
                commsLog(
                    $broadcastId, 'levy',
                    $r['raw_email'] ?: "Row {$r['row_index']} - no email",
                    null, 'failed',
                    $r['raw_email'] ? 'Invalid email address' : 'No email column found'
                );
                $failed++; continue;
            }

            $name        = $r['name'] ?: '';
            $amountRaw   = $r['meta']['amount'] ?? '';
            $amountClean = preg_replace('/[^0-9.,]/', '', $amountRaw);
            $amountFloat = $amountClean ? (float)str_replace(',', '.', $amountClean) : null;

            $message = '';
            foreach ($r['meta']['raw_row'] as $col => $val) {
                if (in_array(strtolower(trim((string)$col)),
                    ['message', 'note', 'notes', 'msg', 'opmerking'], true)) {
                    $message = trim((string)$val);
                    break;
                }
            }

            $html = commsBuildLevyEmail(
                $name ?: 'Resident', $amountClean, $message, $subject
            );

            $ok = commsSendAndLog(
                $broadcastId, 'levy',
                $r['email'], $name, $subject, $html,
                $amountFloat, $message ?: null
            );
            $ok ? $sent++ : $failed++;
        }

        commsBroadcastUpdateCounts($broadcastId, $sent, $failed);

        $flashMsg = "Levy notices sent to {$sent} recipient(s).";
        if ($failed) $flashMsg .= " {$failed} failed — check the log for details.";
        setFlash('success', $flashMsg);
        header('Location: comms_levy.php?action=log&id=' . $broadcastId); exit;
    }

    // ── Show the upload form ──────────────────────────────
    pageHeader('Import Levy Notices', 'admin');
    renderHeader('Import Levy Notices', 'comms_levy.php?action=menu');
    ?>
    <div class="container" style="max-width:640px;">
      <div class="card">
        <?= getFlash() ?>

        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
          Upload a CSV file exported from your financial system.<br>
          <strong>Required column:</strong> <code>email</code><br>
          <strong>Optional columns:</strong> <code>name, amount, message</code><br>
          In Excel: File &rarr; Save As &rarr; CSV (Comma delimited)
        </div>

        <div style="background:#f8f9fa;border-radius:6px;padding:12px;margin-bottom:16px;font-size:.82rem;">
          <strong>Example CSV format:</strong>
          <pre style="margin:6px 0 0;color:#444;">email,name,amount,message
resident1@gmail.com,John Smith,1500.00,Levy due 30 June 2026
resident2@gmail.com,Jane Doe,1500.00,Levy due 30 June 2026</pre>
        </div>

        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>

          <div class="form-group">
            <label>Batch Title * <small style="color:#999;">(internal reference only)</small></label>
            <input type="text" name="title" required
                   placeholder="e.g. June 2026 Levy Notices">
          </div>

          <div class="form-group">
            <label>Email Subject Line *</label>
            <input type="text" name="subject" required
                   placeholder="e.g. MBGE Estate - June 2026 Levy Notice">
            <small style="color:#888;display:block;margin-top:4px;">
              Use plain hyphens (-) only. Special characters like dashes (&#8212;) or
              curved quotes cause delivery failure on this server.
            </small>
          </div>

          <div class="form-group">
            <label>CSV File *</label>
            <input type="file" name="csv" accept=".csv,.txt" required
                   style="padding:10px;background:#f8f9fa;border:1px solid #dee2e6;
                          border-radius:6px;width:100%;">
            <small style="color:#888;display:block;margin-top:4px;">
              Must be a plain .csv file. Do not upload .xlsx or .xls files.
            </small>
          </div>

          <button type="submit" class="btn btn-success btn-block">
            Import &amp; Send Levy Notices
          </button>
        </form>

        <div class="popia-notice">
          Financial data processed under POPIA &sect;11. Send log retained for audit.
        </div>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// LOG — view send detail for a broadcast
// ════════════════════════════════════════════════════════
if ($action === 'log') {
    $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id) { header('Location: comms_levy.php'); exit; }

    $broadcast = commsBroadcastGet($id);
    if (!$broadcast || $broadcast['channel'] !== 'levy') {
        header('Location: comms_levy.php'); exit;
    }

    $logs = commsLogList($id);

    pageHeader('Send Log', 'admin');
    renderHeader('Send Log - ' . htmlspecialchars($broadcast['title']), 'comms_levy.php');
    ?>
    <div class="container">
      <?= getFlash() ?>

      <!-- Summary -->
      <div class="card" style="margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">
          <?php foreach ([
              ['Type',   'Levy',                                              '#1565c0'],
              ['Title',  $broadcast['title'],                                 '#333'],
              ['Date',   date('d M Y H:i', strtotime($broadcast['created_at'])), '#333'],
              ['Sent',   $broadcast['sent_to'],                               '#28a745'],
              ['Failed', $broadcast['failed'], $broadcast['failed'] > 0 ? '#dc3545' : '#999'],
          ] as [$lbl, $val, $col]): ?>
          <div style="text-align:center;">
            <div style="font-size:.75rem;color:#888;margin-bottom:3px;"><?= $lbl ?></div>
            <div style="font-weight:700;color:<?= $col ?>;font-size:.95rem;">
              <?= htmlspecialchars((string)$val) ?>
            </div>
          </div>
          <?php endforeach ?>
        </div>
      </div>

      <!-- Recipients table -->
      <div class="card">
        <div class="card-title">Recipients (<?= count($logs) ?>)</div>
        <?php if (empty($logs)): ?>
          <p style="color:#666;">No recipients logged.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr><th>Email</th><th>Name</th><th>Amount</th><th>Status</th><th>Time</th></tr>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td style="font-size:.82rem;"><?= htmlspecialchars($l['recipient_email'] ?? '') ?></td>
            <td style="font-size:.82rem;"><?= htmlspecialchars($l['recipient_name'] ?? '-') ?></td>
            <td style="font-size:.85rem;">
              <?= $l['amount'] ? 'R ' . number_format((float)$l['amount'], 2) : '-' ?>
            </td>
            <td>
              <span class="badge badge-<?= $l['status'] === 'sent' ? 'success' : 'danger' ?>">
                <?= htmlspecialchars($l['status']) ?>
              </span>
              <?php if ($l['status'] !== 'sent' && !empty($l['error_msg'])): ?>
              <div style="font-size:.75rem;color:#dc3545;margin-top:2px;">
                <?= htmlspecialchars($l['error_msg']) ?>
              </div>
              <?php endif ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap;">
              <?= date('H:i:s', strtotime($l['sent_at'])) ?>
            </td>
          </tr>
          <?php endforeach ?>
        </table></div>
        <?php endif ?>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// TEMPLATE — download blank levy CSV template
// ════════════════════════════════════════════════════════
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="levy_template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['email', 'name', 'amount', 'message', 'erf']);
    fputcsv($out, ['resident1@yourdomain.com', 'John Smith', '1500.00', 'Levy due 30 June 2026', '42']);
    fputcsv($out, ['resident2@yourdomain.com', 'Jane Doe',   '1500.00', 'Levy due 30 June 2026', '17']);
    fclose($out);
    exit;
}

header('Location: comms_levy.php'); exit;
