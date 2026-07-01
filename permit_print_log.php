<?php
/**
 * permit_print_log.php — Permit Print Audit Log
 * Accessible by: admin and security officer
 * Shows: all printed permits with SP details, operator, timestamp, and PDF download link
 * Retention: displayed purge_after date; purged by cron when purge_after < CURDATE()
 */
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['security_id']) && empty($_SESSION['admin_id'])) {
    header('Location: security.php?action=login'); exit;
}

// ── Serve stored PDF if requested ───────────────────────
if (isset($_GET['download'])) {
    $logId = (int)$_GET['download'];
    $row   = db()->prepare("SELECT pdf_path, unique_code, permit_type FROM permit_print_log WHERE id=? LIMIT 1");
    $row->execute([$logId]);
    $row = $row->fetch();

    if (!$row) { http_response_code(404); die('Record not found'); }

    $absPath = __DIR__ . $row['pdf_path'];
    if (!file_exists($absPath)) { http_response_code(404); die('PDF file not found on disk'); }

    $filename = $row['permit_type'] . '_' . $row['unique_code'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($absPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($absPath);
    exit;
}

// ── Filters ──────────────────────────────────────────────
$filterType = in_array($_GET['type'] ?? '', ['card','slip',''], true) ? ($_GET['type'] ?? '') : '';
$filterCode = preg_replace('/[^A-Z0-9]/i', '', strtoupper($_GET['code'] ?? ''));
$rawFrom    = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$rawTo      = $_GET['to']   ?? date('Y-m-d');
$dateFrom   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawFrom) ? $rawFrom : date('Y-m-d', strtotime('-30 days'));
$dateTo     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawTo)   ? $rawTo   : date('Y-m-d');

$where  = ["DATE(ppl.printed_at) BETWEEN ? AND ?"];
$params = [$dateFrom, $dateTo];

if ($filterType) { $where[] = "ppl.permit_type = ?"; $params[] = $filterType; }
if ($filterCode) { $where[] = "ppl.unique_code LIKE ?"; $params[] = $filterCode . '%'; }

$whereSQL = implode(' AND ', $where);

$logs = db()->prepare("
    SELECT ppl.*,
           sp.service_name, sp.resident_name, sp.resident_erfno,
           sp.category, sp.end_date
    FROM permit_print_log ppl
    LEFT JOIN service_providers sp ON sp.id = ppl.sp_id
    WHERE {$whereSQL}
    ORDER BY ppl.printed_at DESC
    LIMIT 500
");
$logs->execute($params);
$logs = $logs->fetchAll();

// ── Page ─────────────────────────────────────────────────
require_once __DIR__ . '/layout.php';
pageHeader('Permit Print Log', 'security');
renderHeader('🖨️ Permit Print Audit Log', 'security.php?action=menu');
?>
<div class="container">
  <?= getFlash() ?>

  <!-- Filter bar -->
  <div class="card">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
      <div>
        <label style="display:block;font-size:.8rem;color:#666;margin-bottom:3px;">From</label>
        <input type="date" name="from" value="<?= htmlspecialchars($dateFrom) ?>"
               style="padding:8px;border:1px solid #dee2e6;border-radius:6px;">
      </div>
      <div>
        <label style="display:block;font-size:.8rem;color:#666;margin-bottom:3px;">To</label>
        <input type="date" name="to" value="<?= htmlspecialchars($dateTo) ?>"
               style="padding:8px;border:1px solid #dee2e6;border-radius:6px;">
      </div>
      <div>
        <label style="display:block;font-size:.8rem;color:#666;margin-bottom:3px;">Type</label>
        <select name="type" style="padding:8px;border:1px solid #dee2e6;border-radius:6px;">
          <option value="">All</option>
          <option value="card"  <?= $filterType==='card' ?'selected':'' ?>>Card</option>
          <option value="slip"  <?= $filterType==='slip' ?'selected':'' ?>>Slip</option>
        </select>
      </div>
      <div>
        <label style="display:block;font-size:.8rem;color:#666;margin-bottom:3px;">Code</label>
        <input type="text" name="code" value="<?= htmlspecialchars($filterCode) ?>"
               placeholder="e.g. 758045" maxlength="10"
               style="padding:8px;border:1px solid #dee2e6;border-radius:6px;width:110px;font-family:monospace;">
      </div>
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="permit_print_log.php" class="btn btn-secondary">Reset</a>
    </form>
  </div>

  <!-- Summary -->
  <div style="font-size:.85rem;color:#666;margin-bottom:10px;">
    <?= count($logs) ?> record<?= count($logs) !== 1 ? 's' : '' ?> found
    — <?= date('d M Y', strtotime($dateFrom)) ?> to <?= date('d M Y', strtotime($dateTo)) ?>
  </div>

  <?php if (empty($logs)): ?>
    <div class="card"><p style="color:#666;">No permit print records in this date range.</p></div>
  <?php else: ?>
  <div class="card" style="padding:0;overflow:hidden;">
    <div class="table-wrap">
      <table>
        <tr>
          <th>Printed</th>
          <th>Type</th>
          <th>Code</th>
          <th>Service Provider</th>
          <th>Resident / Erf</th>
          <th>Printed by</th>
          <th>Permit valid to</th>
          <th>Purge after</th>
          <th>PDF</th>
        </tr>
        <?php foreach ($logs as $l):
          $isExpired  = $l['purge_after'] < date('Y-m-d');
          $pdfExists  = file_exists(__DIR__ . $l['pdf_path']);
        ?>
        <tr style="<?= $isExpired ? 'opacity:.55;' : '' ?>">
          <td style="white-space:nowrap;font-size:.82rem;">
            <?= date('d M Y H:i', strtotime($l['printed_at'])) ?>
          </td>
          <td>
            <span class="badge badge-<?= $l['permit_type']==='card' ? 'info' : 'success' ?>">
              <?= $l['permit_type']==='card' ? '💳 Card' : '📄 Slip' ?>
            </span>
          </td>
          <td style="font-family:monospace;font-size:.85rem;font-weight:bold;color:#1a3c5e;">
            <?= htmlspecialchars($l['unique_code']) ?>
          </td>
          <td style="font-size:.88rem;">
            <?= htmlspecialchars($l['service_name'] ?? '—') ?>
          </td>
          <td style="font-size:.82rem;color:#666;">
            <?php if ($l['resident_erfno']): ?>
              Erf <?= htmlspecialchars($l['resident_erfno']) ?>
              <?= htmlspecialchars($l['resident_name'] ?? '') ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
          <td style="font-size:.82rem;">
            <?= htmlspecialchars($l['printed_by_name']) ?>
          </td>
          <td style="font-size:.82rem;<?= $l['end_date'] < date('Y-m-d') ? 'color:#dc3545;' : '' ?>">
            <?= date('d M Y', strtotime($l['end_date'] ?? $l['purge_after'])) ?>
          </td>
          <td style="font-size:.78rem;color:#<?= $isExpired ? 'aaa' : '888' ?>;">
            <?= date('d M Y', strtotime($l['purge_after'])) ?>
          </td>
          <td>
            <?php if ($pdfExists): ?>
              <a href="permit_print_log.php?download=<?= $l['id'] ?>"
                 target="_blank"
                 class="btn btn-primary btn-sm">View</a>
            <?php else: ?>
              <span style="font-size:.75rem;color:#aaa;">Purged</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="popia-notice" style="margin-top:12px;">
    Permit PDFs are retained for the SP validity period + 30 days, then purged automatically.
    Access to this log and its stored PDFs is restricted to security officers and administrators.
    Processing under POPIA §11 for HOA access control governance.
  </div>
</div>
<?php pageFooter(); exit; ?>
