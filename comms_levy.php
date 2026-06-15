<?php
// ============================================================
// gemB / MBGE — comms_levy.php
// Personalised messages: levy statements via CSV upload + email
// Actions: menu | send | log
//
// Replaces the 'levy' action from document_portal.php.
// Uses comms_core.php shared functions (commsParseCsv,
// commsRecipients('levy_csv', ...), commsBuildLevyEmail).
// ============================================================
require_once __DIR__ . '/comms_core.php';
commsRequireAuth();

$action  = $_GET['action'] ?? 'menu';
$backUrl = 'comms.php';

// ════════════════════════════════════════════════════════
// MENU — levy send history
// ════════════════════════════════════════════════════════
if ($action === 'menu') {
    $broadcasts = commsBroadcastList('levy', 100);

    pageHeader('Levy Statements', 'admin');
    renderHeader('💰 Levy Statements', $backUrl);
    ?>
    <div class="container">
      <?= getFlash() ?>

      <div style="display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="comms_levy.php?action=send" class="btn btn-success">
          💰 Import &amp; Send Levy Notices
        </a>
      </div>

      <div class="card">
        <div class="card-title">Send History</div>
        <?php if (empty($broadcasts)): ?>
          <p style="color:#666;">No levy notices sent yet.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr>
            <th>Date</th><th>Title</th>
            <th>Sent</th><th>Failed</th><th>Actions</th>
          </tr>
          <?php foreach ($broadcasts as $b): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.85rem;">
              <?= date('d M Y H:i', strtotime($b['created_at'])) ?>
            </td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td style="color:#28a745;font-weight:700;"><?= $b['sent_to'] ?></td>
            <td style="color:<?= $b['failed']>0?'#dc3545':'#999' ?>;font-weight:700;">
              <?= $b['failed'] ?>
            </td>
            <td>
              <a href="comms_levy.php?action=log&id=<?= $b['id'] ?>"
                 class="btn btn-secondary btn-sm">View Log</a>
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
        if (empty($_FILES['csv']['tmp_name'])) {
            setFlash('error', 'Please select a CSV file.');
            header('Location: comms_levy.php?action=send'); exit;
        }

        $file = $_FILES['csv'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            setFlash('error', 'Only CSV files are allowed.');
            header('Location: comms_levy.php?action=send'); exit;
        }

        $rows = commsParseCsv($file['tmp_name']);

        if (empty($rows)) {
            setFlash('error', 'CSV file is empty or could not be parsed.');
            header('Location: comms_levy.php?action=send'); exit;
        }

        $broadcastId = commsBroadcastCreate('levy', 'levy', $title, null, basename($file['name']), $subject);

        $recipients = commsRecipients('levy_csv', ['rows' => $rows]);
        $sent = 0; $failed = 0;

        foreach ($recipients as $r) {
            if (!$r['email']) {
                commsLog(
                    $broadcastId, 'levy',
                    $r['raw_email'] ?: "Row {$r['row_index']} — no email",
                    null, 'failed',
                    $r['raw_email'] ? 'Invalid email address' : 'No email column found'
                );
                $failed++; continue;
            }

            $name        = $r['name'] ?: '';
            $amountRaw   = $r['meta']['amount'] ?? '';
            $amountClean = preg_replace('/[^0-9.,]/', '', $amountRaw);
            $amountFloat = $amountClean ? (float)str_replace(',', '.', $amountClean) : null;

            // message column comes from the raw CSV row, not commsRecipients() —
            // re-derive flexibly here.
            $message = '';
            foreach ($r['meta']['raw_row'] as $col => $val) {
                if (in_array(strtolower(trim((string)$col)), ['message','note','notes','msg','opmerking'], true)) {
                    $message = trim((string)$val);
                    break;
                }
            }

            $html = commsBuildLevyEmail($name ?: 'Resident', $amountClean, $message, $subject);

            $ok = commsSendAndLog($broadcastId, 'levy', $r['email'], $name, $subject, $html, $amountFloat, $message ?: null);
            $ok ? $sent++ : $failed++;
        }

        commsBroadcastUpdateCounts($broadcastId, $sent, $failed);

        setFlash('success', "Levy notices sent to {$sent} recipient(s)." . ($failed ? " {$failed} failed." : ''));
        header('Location: comms_levy.php?action=log&id=' . $broadcastId); exit;
    }

    pageHeader('Import Levy Notices', 'admin');
    renderHeader('💰 Import Levy Notices', 'comms_levy.php?action=menu');
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
                   placeholder="e.g. MBGE Estate — June 2026 Levy Notice">
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
    if (!$id) { header('Location: comms_levy.php'); exit; }

    $broadcast = commsBroadcastGet($id);
    if (!$broadcast || $broadcast['channel'] !== 'levy') { header('Location: comms_levy.php'); exit; }

    $logs = commsLogList($id);

    pageHeader('Send Log', 'admin');
    renderHeader('📋 Send Log — ' . htmlspecialchars($broadcast['title']), 'comms_levy.php');
    ?>
    <div class="container">

      <!-- Summary -->
      <div class="card" style="margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">
          <?php foreach ([
              ['Type',    '💰 Levy', '#1565c0'],
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
            <td style="font-size:.82rem;"><?= htmlspecialchars($l['recipient_name'] ?? '—') ?></td>
            <td style="font-size:.85rem;">
              <?= $l['amount'] ? 'R '.number_format((float)$l['amount'],2) : '—' ?>
            </td>
            <td>
              <span class="badge badge-<?= $l['status']==='sent'?'success':'danger' ?>">
                <?= $l['status'] ?>
              </span>
              <?php if ($l['status'] !== 'sent' && $l['error_msg']): ?>
              <div style="font-size:.75rem;color:#dc3545;"><?= htmlspecialchars($l['error_msg']) ?></div>
              <?php endif; ?>
            </td>
            <td style="font-size:.78rem;white-space:nowrap;">
              <?= date('H:i:s', strtotime($l['sent_at'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
      </div>

    </div>
    <?php pageFooter(); exit;
}

header('Location: comms_levy.php'); exit;
