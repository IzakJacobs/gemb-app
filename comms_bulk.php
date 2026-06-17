<?php
// ============================================================
// gemB / GEMB — comms_bulk.php
// Bulk messages: general circulars/PDFs to all residents + email
// Actions: menu | send | log
//
// Replaces the 'broadcast' action + menu listing from
// document_portal.php. Uses comms_core.php shared functions.
// ============================================================
require_once __DIR__ . '/comms_core.php';
commsRequireAuth();

$action = $_GET['action'] ?? 'menu';
$backUrl = 'comms_menu.php';

// ── Upload directory ──────────────────────────────────────
$docDir = __DIR__ . '/uploads/documents/';
$docUrl = SITE_URL . '/uploads/documents/';
if (!is_dir($docDir)) mkdir($docDir, 0755, true);

// ════════════════════════════════════════════════════════
// MENU — bulk send history
// ════════════════════════════════════════════════════════
if ($action === 'menu') {
    $broadcasts    = commsBroadcastList('bulk', 100);
    $contactCount  = commsRecipientCount('comms_contacts');

    // Distinct group tags for optional group filter info
    $groupTags = db()->query(
        "SELECT DISTINCT group_tag FROM comms_contacts
         WHERE active=1 AND group_tag IS NOT NULL AND group_tag != ''
         ORDER BY group_tag"
    )->fetchAll(PDO::FETCH_COLUMN);

    pageHeader('Bulk Messages', 'admin');
    renderHeader('📤 Bulk Messages', $backUrl);
    ?>
    <div class="container">
      <?= getFlash() ?>

      <!-- ═══════════════════════════════════════════════
           STEP 1 — Contact List (basis of formation)
           ═══════════════════════════════════════════════ -->
      <div class="card" style="border-left:5px solid
           <?= $contactCount > 0 ? '#28a745' : '#dc3545' ?>; margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <span style="background:<?= $contactCount > 0 ? '#28a745' : '#dc3545' ?>;
                       color:#fff;border-radius:50%;width:28px;height:28px;
                       display:flex;align-items:center;justify-content:center;
                       font-weight:800;font-size:.9rem;flex-shrink:0;">1</span>
          <div class="card-title" style="margin:0;border:none;padding:0;">
            Import / Confirm Contact List
          </div>
        </div>

        <?php if ($contactCount === 0): ?>
          <div class="alert alert-danger" style="margin-bottom:14px;">
            <strong>No contacts loaded.</strong>
            You must import a CSV contact list before a circular can be sent.
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="comms_contacts.php?action=import" class="btn btn-primary">
              📥 Import Contact List (CSV)
            </a>
            <a href="comms_contacts.php?action=template" class="btn btn-secondary">
              ⬇ Download CSV Template
            </a>
          </div>
        <?php else: ?>
          <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:14px;">
            <div style="font-size:1.6rem;font-weight:800;color:#28a745;">
              <?= number_format($contactCount) ?>
            </div>
            <div>
              <div style="font-weight:600;">active contact<?= $contactCount !== 1 ? 's' : '' ?> ready</div>
              <?php if ($groupTags): ?>
              <div style="font-size:.82rem;color:#666;margin-top:2px;">
                Groups: <?= htmlspecialchars(implode(', ', $groupTags)) ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="comms_contacts.php?action=import" class="btn btn-secondary btn-sm">
              📥 Replace / Update List
            </a>
            <a href="comms_contacts.php" class="btn btn-secondary btn-sm">
              👥 View Contacts
            </a>
          </div>
        <?php endif; ?>
      </div>

      <!-- ═══════════════════════════════════════════════
           STEP 2 — Send the circular
           ═══════════════════════════════════════════════ -->
      <div class="card" style="border-left:5px solid
           <?= $contactCount > 0 ? '#1565c0' : '#ccc' ?>; margin-bottom:20px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <span style="background:<?= $contactCount > 0 ? '#1565c0' : '#ccc' ?>;
                       color:#fff;border-radius:50%;width:28px;height:28px;
                       display:flex;align-items:center;justify-content:center;
                       font-weight:800;font-size:.9rem;flex-shrink:0;">2</span>
          <div class="card-title" style="margin:0;border:none;padding:0;">
            Send Circular / PDF
          </div>
        </div>

        <?php if ($contactCount === 0): ?>
          <p style="color:#999;font-size:.9rem;">
            Complete Step 1 first — upload your contact list before sending.
          </p>
          <button class="btn btn-primary" disabled
                  style="margin-top:12px;opacity:.4;cursor:not-allowed;">
            📤 Send Circular / PDF
          </button>
        <?php else: ?>
          <p style="color:#555;font-size:.9rem;margin-bottom:14px;">
            Upload a PDF circular and email it to all
            <strong><?= number_format($contactCount) ?></strong>
            active contact<?= $contactCount !== 1 ? 's' : '' ?> in your list.
          </p>
          <a href="comms_bulk.php?action=send" class="btn btn-primary">
            📤 Send Circular / PDF
          </a>
        <?php endif; ?>
      </div>

      <!-- Send history -->
      <div class="card">
        <div class="card-title">Send History</div>
        <?php if (empty($broadcasts)): ?>
          <p style="color:#666;">No bulk messages sent yet.</p>
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
              <a href="comms_bulk.php?action=log&id=<?= $b['id'] ?>"
                 class="btn btn-secondary btn-sm">View Log</a>
              <?php if ($b['filename']): ?>
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
// SEND — upload PDF and send to all residents
// ════════════════════════════════════════════════════════
if ($action === 'send') {

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $title = trim($_POST['title'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if (!$title) {
            setFlash('error', 'Title is required.');
            header('Location: comms_bulk.php?action=send'); exit;
        }

        $upload = commsHandleUpload([
            'field'      => 'pdf',
            'extensions' => ['pdf'],
            'max_size'   => 10 * 1024 * 1024,
            'dest_dir'   => $docDir,
        ]);

        if (!$upload['ok']) {
            setFlash('error', $upload['error']);
            header('Location: comms_bulk.php?action=send'); exit;
        }

        $broadcastId = commsBroadcastCreate('bulk', 'circular', $title, $upload['stored'], $upload['original'], $notes ?: null);

        $recipients  = commsRecipients('comms_contacts');
        $downloadUrl = $docUrl . $upload['stored'];
        $sent = 0; $failed = 0;

        foreach ($recipients as $r) {
            $email = filter_var($r['email'], FILTER_VALIDATE_EMAIL);
            if (!$email) {
                commsLog($broadcastId, 'bulk', $r['email'] ?: null, $r['name'] ?? null, 'failed', 'Invalid email address');
                $failed++;
                continue;
            }

            $name    = htmlspecialchars($r['name'] ?? 'Resident');
            $subject = 'GEMB Estate — ' . $title;
            $html    = commsBuildCircularEmail($name, $title, $downloadUrl, $notes);

            $ok = commsSendAndLog($broadcastId, 'bulk', $email, $r['name'] ?? '', $subject, $html);
            $ok ? $sent++ : $failed++;
        }

        commsBroadcastUpdateCounts($broadcastId, $sent, $failed);

        setFlash('success', "Circular sent to {$sent} contact(s)." . ($failed ? " {$failed} failed." : ''));
        header('Location: comms_bulk.php?action=log&id=' . $broadcastId); exit;
    }

    $recipientCount = commsRecipientCount('comms_contacts');

    pageHeader('Send Circular', 'admin');
    renderHeader('📤 Send Circular / PDF', 'comms_bulk.php?action=menu');
    ?>
    <div class="container" style="max-width:600px;">
      <div class="card">
        <?= getFlash() ?>
        <?php if ($recipientCount === 0): ?>
        <div class="alert alert-warning" style="font-size:.88rem;margin-bottom:16px;">
          <strong>No active contacts.</strong>
          <a href="comms_contacts.php?action=import">Import a contact list</a> before sending.
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
          This PDF will be emailed to <strong><?= $recipientCount ?> contact(s)</strong>
          with a download link. Max file size: 10 MB.
          <a href="comms_contacts.php" style="margin-left:8px;">Manage contacts →</a>
        </div>
        <?php endif; ?>
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
                  <?= $recipientCount === 0 ? 'disabled' : '' ?>
                  onclick="return confirm('Send this circular to <?= $recipientCount ?> contact(s)?')">
            📤 Send to All Contacts
          </button>
        </form>
        <div class="popia-notice">Documents emailed under POPIA §11 for estate communication purposes.</div>
        <div style="margin-top:10px;font-size:.82rem;color:#888;text-align:center;">
          Recipients are drawn from the <a href="comms_contacts.php">standalone contact list</a>,
          not from resident records.
        </div>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// LOG — send log for one broadcast
// ════════════════════════════════════════════════════════
if ($action === 'log') {
    $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id) { header('Location: comms_bulk.php'); exit; }

    $broadcast = commsBroadcastGet($id);
    if (!$broadcast || $broadcast['channel'] !== 'bulk') { header('Location: comms_bulk.php'); exit; }

    $logs = commsLogList($id);

    pageHeader('Send Log', 'admin');
    renderHeader('📋 Send Log — ' . htmlspecialchars($broadcast['title']), 'comms_bulk.php');
    ?>
    <div class="container">
      <?= getFlash() ?>

      <!-- Summary -->
      <div class="card" style="margin-bottom:14px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;">
          <?php foreach ([
              ['Type',    '📄 Circular', '#1565c0'],
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
        <?php if ($broadcast['filename']): ?>
        <div style="margin-top:12px;">
          <a href="<?= htmlspecialchars($docUrl . $broadcast['filename']) ?>"
             target="_blank" class="btn btn-primary btn-sm">📥 Download PDF</a>
        </div>
        <?php endif; ?>
      </div>

      <!-- Recipients table -->
      <div class="card">
        <div class="card-title">Recipients (<?= count($logs) ?>)</div>
        <?php if (empty($logs)): ?>
          <p style="color:#666;">No recipients logged.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr><th>Email</th><th>Name</th><th>Status</th><th>Time</th></tr>
          <?php foreach ($logs as $l): ?>
          <tr>
            <td style="font-size:.82rem;"><?= htmlspecialchars($l['recipient_email'] ?? '') ?></td>
            <td style="font-size:.82rem;"><?= htmlspecialchars($l['recipient_name'] ?? '—') ?></td>
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

header('Location: comms_bulk.php'); exit;
