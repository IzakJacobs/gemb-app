<?php
// ============================================================
// gemB / MBGE — comms_archive.php
// Role-aware document archive (Communications module)
// Accessible by: admin, security officer, resident
//
// Replaces document_archive.php. Reads from the unified
// comms_broadcasts / comms_send_log tables (channel = 'bulk' | 'levy').
// ============================================================
require_once __DIR__ . '/comms_core.php';

// ── Determine role and enforce access ─────────────────────
// Note: this file keeps its own role check (admin/security/resident)
// rather than commsRequireAuth(), because residents and security
// officers need read access to the archive but are not "comms admins".
$role     = '';
$backUrl  = '';
$resEmail = '';

if (!empty($_SESSION['admin_id'])) {
    $role    = 'admin';
    $backUrl = 'admin.php?action=menu';
} elseif (!empty($_SESSION['comms_logged_in'])) {
    // Standalone comms user — same view as admin (full archive + send logs)
    $role    = 'admin';
    $backUrl = 'comms_menu.php';
} elseif (!empty($_SESSION['security_id'])) {
    $role    = 'security';
    $backUrl = 'security.php?action=menu';
} elseif (!empty($_SESSION['resident_id'])) {
    $role    = 'resident';
    $backUrl = 'resident.php?action=menu';
    $re = db()->prepare("SELECT email FROM residents WHERE id=? LIMIT 1");
    $re->execute([$_SESSION['resident_id']]);
    $resEmail = $re->fetchColumn() ?: '';
} else {
    header('Location: admin.php'); exit;
}

$action = $_GET['action'] ?? 'list';
$docUrl = SITE_URL . '/uploads/documents/';

// ════════════════════════════════════════════════════════
// LIST — searchable archive table
// ════════════════════════════════════════════════════════
if ($action === 'list') {

    $search     = trim($_GET['q']    ?? '');
    $searchDate = trim($_GET['date'] ?? '');

    $params = [];
    $where  = ["b.channel IN ('bulk','levy')"];

    if ($role === 'security') {
        // Security sees circulars (bulk) only
        $where[] = "b.channel = 'bulk'";
    } elseif ($role === 'resident') {
        if ($resEmail) {
            // Resident sees circulars + their own levy notices
            $where[]  = "(b.channel = 'bulk' OR (b.channel = 'levy' AND EXISTS (
                            SELECT 1 FROM comms_send_log sl
                            WHERE sl.broadcast_id = b.id
                              AND sl.recipient_email = ?
                              AND sl.status = 'sent'
                         )))";
            $params[] = $resEmail;
        } else {
            $where[] = "b.channel = 'bulk'";
        }
    }

    if ($search) {
        $where[]  = "(b.title LIKE ? OR b.notes LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if ($searchDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDate)) {
        $where[]  = "DATE(b.created_at) = ?";
        $params[] = $searchDate;
    }

    $whereStr = implode(' AND ', $where);

    $stmt = db()->prepare("
        SELECT b.*
        FROM   comms_broadcasts b
        WHERE  {$whereStr}
        ORDER  BY b.created_at DESC
        LIMIT  200
    ");
    $stmt->execute($params);
    $docs = $stmt->fetchAll();

    $titles = [
        'admin'    => '📄 Document Archive',
        'security' => '📄 Estate Documents',
        'resident' => '📄 Estate Documents & Notices',
    ];

    pageHeader('Document Archive', $role);
    renderHeader($titles[$role], $backUrl);
    ?>
    <div class="container">

      <!-- Search bar -->
      <div class="card" style="padding:16px;">
        <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
          <input type="hidden" name="action" value="list">
          <div style="flex:2;min-width:180px;">
            <label style="display:block;font-size:.8rem;color:#666;margin-bottom:3px;">Search</label>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search by title or description…"
                   style="width:100%;padding:9px 12px;border:1px solid #dee2e6;border-radius:6px;">
          </div>
          <div style="min-width:160px;">
            <label style="display:block;font-size:.8rem;color:#666;margin-bottom:3px;">Date</label>
            <input type="date" name="date" value="<?= htmlspecialchars($searchDate) ?>"
                   style="padding:9px;border:1px solid #dee2e6;border-radius:6px;width:100%;">
          </div>
          <button type="submit" class="btn btn-primary">Search</button>
          <?php if ($search || $searchDate): ?>
          <a href="comms_archive.php" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- Document table -->
      <div class="card">
        <div class="card-title">
          <?= count($docs) ?> document(s) found
          <?php if ($search): ?>
            — matching "<strong><?= htmlspecialchars($search) ?></strong>"
          <?php endif; ?>
        </div>

        <?php if (empty($docs)): ?>
          <p style="color:#666;padding:8px 0;">No documents found.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr>
            <th style="width:130px;">Date</th>
            <th>Document / Description</th>
            <?php if ($role === 'admin'): ?>
            <th style="width:80px;">Type</th>
            <th style="width:80px;">Sent</th>
            <?php endif; ?>
            <th style="width:80px;">View</th>
          </tr>
          <?php foreach ($docs as $d):
            $isLevy = $d['channel'] === 'levy';
          ?>
          <tr>
            <td style="white-space:nowrap;font-size:.85rem;color:#666;">
              <?= date('d M Y', strtotime($d['created_at'])) ?><br>
              <span style="font-size:.78rem;"><?= date('H:i', strtotime($d['created_at'])) ?></span>
            </td>
            <td>
              <a href="comms_archive.php?action=view&id=<?= $d['id'] ?>"
                 style="font-weight:700;color:#003366;text-decoration:none;font-size:.95rem;">
                <?= htmlspecialchars($d['title']) ?>
              </a>
              <?php if ($d['notes']): ?>
              <div style="font-size:.8rem;color:#888;margin-top:3px;">
                <?= htmlspecialchars(mb_substr($d['notes'], 0, 100)) ?>
                <?= strlen($d['notes']) > 100 ? '…' : '' ?>
              </div>
              <?php endif; ?>
            </td>
            <?php if ($role === 'admin'): ?>
            <td>
              <?= $isLevy
                ? '<span class="badge badge-success">💰 Levy</span>'
                : '<span class="badge badge-info">📄 Circular</span>' ?>
            </td>
            <td style="text-align:center;font-weight:700;color:#28a745;">
              <?= $d['sent_to'] ?>
            </td>
            <?php endif; ?>
            <td>
              <a href="comms_archive.php?action=view&id=<?= $d['id'] ?>"
                 class="btn btn-primary btn-sm">View</a>
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
// VIEW — show full document / message
// ════════════════════════════════════════════════════════
if ($action === 'view') {
    $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$id) { header('Location: comms_archive.php'); exit; }

    $doc = commsBroadcastGet($id);
    if (!$doc || !in_array($doc['channel'], ['bulk','levy'], true)) {
        header('Location: comms_archive.php'); exit;
    }

    // Security can only view circulars (bulk)
    if ($role === 'security' && $doc['channel'] !== 'bulk') {
        header('Location: comms_archive.php'); exit;
    }

    // Resident: check they have access
    if ($role === 'resident' && $doc['channel'] === 'levy') {
        if (!$resEmail) { header('Location: comms_archive.php'); exit; }
        $check = db()->prepare(
            "SELECT id FROM comms_send_log
             WHERE broadcast_id=? AND recipient_email=? AND status='sent' LIMIT 1"
        );
        $check->execute([$id, $resEmail]);
        if (!$check->fetch()) { header('Location: comms_archive.php'); exit; }
    }

    // For resident levy — get their personal record
    $levyRecord = null;
    if ($role === 'resident' && $doc['channel'] === 'levy' && $resEmail) {
        $lr = db()->prepare(
            "SELECT * FROM comms_send_log
             WHERE broadcast_id=? AND recipient_email=? LIMIT 1"
        );
        $lr->execute([$id, $resEmail]);
        $levyRecord = $lr->fetch();
    }

    // For admin — get send summary
    $sendLog = [];
    if ($role === 'admin') {
        $sendLog = commsLogList($id);
    }

    pageHeader(htmlspecialchars($doc['title']), $role);
    renderHeader('📄 ' . htmlspecialchars($doc['title']), 'comms_archive.php');
    ?>
    <div class="container" style="max-width:700px;">

      <!-- Document header card -->
      <div class="card" style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
          <div>
            <h2 style="margin:0 0 6px;font-size:1.1rem;color:#003366;">
              <?= htmlspecialchars($doc['title']) ?>
            </h2>
            <div style="font-size:.85rem;color:#888;">
              <?= $doc['channel'] === 'bulk' ? '📄 Circular' : '💰 Levy Notice' ?>
              &nbsp;|&nbsp;
              <?= date('d M Y H:i', strtotime($doc['created_at'])) ?>
              <?php if ($role === 'admin'): ?>
              &nbsp;|&nbsp; Sent to <strong><?= $doc['sent_to'] ?></strong>
              <?php if ($doc['failed'] > 0): ?>
              &nbsp;|&nbsp; <span style="color:#dc3545;"><?= $doc['failed'] ?> failed</span>
              <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($doc['channel'] === 'bulk' && $doc['filename']): ?>
          <a href="<?= htmlspecialchars($docUrl . $doc['filename']) ?>"
             target="_blank" class="btn btn-primary">
            📥 Download PDF
          </a>
          <?php endif; ?>
        </div>

        <?php if ($doc['notes']): ?>
        <div style="margin-top:14px;padding:12px;background:#f8f9fa;border-radius:6px;
                    font-size:.9rem;color:#555;border-left:3px solid #003366;">
          <?= nl2br(htmlspecialchars($doc['notes'])) ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Resident: personal levy notice -->
      <?php if ($role === 'resident' && $doc['channel'] === 'levy' && $levyRecord): ?>
      <div class="card">
        <div class="card-title">Your Levy Notice</div>
        <?php if ($levyRecord['amount']): ?>
        <div style="background:#f0f8ff;border:2px solid #003366;border-radius:8px;
                    padding:20px;text-align:center;margin-bottom:16px;">
          <div style="font-size:.85rem;color:#666;margin-bottom:4px;">Amount Due</div>
          <div style="font-size:2.2rem;font-weight:900;color:#003366;">
            R <?= number_format((float)$levyRecord['amount'], 2) ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($levyRecord['message']): ?>
        <p style="color:#444;font-size:.95rem;line-height:1.6;">
          <?= nl2br(htmlspecialchars($levyRecord['message'])) ?>
        </p>
        <?php endif; ?>
      </div>

      <!-- Admin: send log table -->
      <?php elseif ($role === 'admin' && !empty($sendLog)): ?>
      <div class="card">
        <div class="card-title">Send Log (<?= count($sendLog) ?> recipients)</div>
        <div class="table-wrap"><table>
          <tr>
            <th>Email</th><th>Name</th>
            <?php if ($doc['channel'] === 'levy'): ?>
            <th>Amount</th>
            <?php endif; ?>
            <th>Status</th><th>Time</th>
          </tr>
          <?php foreach ($sendLog as $l): ?>
          <tr>
            <td style="font-size:.82rem;"><?= htmlspecialchars($l['recipient_email'] ?? '') ?></td>
            <td style="font-size:.82rem;"><?= htmlspecialchars($l['recipient_name'] ?? '—') ?></td>
            <?php if ($doc['channel'] === 'levy'): ?>
            <td style="font-size:.85rem;">
              <?= $l['amount'] ? 'R '.number_format((float)$l['amount'],2) : '—' ?>
            </td>
            <?php endif; ?>
            <td>
              <span class="badge badge-<?= $l['status']==='sent'?'success':'danger' ?>">
                <?= $l['status'] ?>
              </span>
            </td>
            <td style="font-size:.78rem;white-space:nowrap;">
              <?= date('H:i:s', strtotime($l['sent_at'])) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </table></div>
      </div>
      <?php endif; ?>

    </div>
    <?php pageFooter(); exit;
}

header('Location: comms_archive.php'); exit;
