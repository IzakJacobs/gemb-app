<?php
// ============================================================
// MBGE Access Control — export.php (clean rebuild)
// ============================================================
require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireAdmin();

$action = $_GET['action'] ?? 'menu';

function exportCSV(string $filename, array $headers, array $rows): void {
    // Log the export for POPIA audit trail
    try {
        db()->prepare(
            "INSERT INTO admin_messages
               (admin_name, message_type, message, created_at)
             VALUES (?, 'export', ?, NOW())"
        )->execute([
            $_SESSION['admin_name'] ?? 'unknown',
            "Exported {$filename} (" . count($rows) . " rows) from IP "
                . ($_SERVER['REMOTE_ADDR'] ?? '?'),
        ]);
    } catch (Exception $e) {
        error_log('MBGE export audit log failed: ' . $e->getMessage());
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, $headers);
    foreach ($rows as $row) fputcsv($out, array_values($row));
    fclose($out); exit;
}

if ($action === 'menu') {
    pageHeader('Export', 'admin');
    renderHeader('📊 Export Data', 'admin.php?action=menu');
    ?>
    <div class="container">
      <div class="menu-grid">
        <a href="export.php?action=residents" class="menu-btn"><span class="icon">🏠</span>Residents CSV</a>
        <a href="export.php?action=visitors" class="menu-btn"><span class="icon">👤</span>Visitors CSV</a>
        <a href="export.php?action=guards" class="menu-btn"><span class="icon">👮</span>Guards CSV</a>
        <a href="export.php?action=sp" class="menu-btn"><span class="icon">🔧</span>Service Providers CSV</a>
        <a href="export.php?action=logs" class="menu-btn"><span class="icon">📋</span>Access Logs CSV</a>
        <a href="export.php?action=access_today" class="menu-btn"><span class="icon">📅</span>Today's Activity</a>
      </div>
    </div>
    <?php
    pageFooter(); exit;
}

if ($action === 'residents') {
    $rows = db()->query("
        SELECT resident_erfno, resident_name, address, phone, email, status, created_at
        FROM residents
        ORDER BY CAST(resident_erfno AS UNSIGNED), resident_erfno")->fetchAll();
    exportCSV('MBGE_Residents_' . date('Ymd') . '.csv',
        ['Erf No','Full Name','Address','Phone','Email','Status','Created'], $rows);
}

if ($action === 'visitors') {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $stmt = db()->prepare("
        SELECT visitor_name, plate, idnum, visit_date, visit_date_to,
               visit_time, status, expired, arrival, resident_name, created_at
        FROM visitors
        WHERE visit_date BETWEEN ? AND ?
        ORDER BY visit_date DESC");
    $stmt->execute([$from, $to]);
    exportCSV('MBGE_Visitors_' . $from . '_to_' . $to . '.csv',
        ['Visitor','Plate','ID No','Visit Date','Until','Time','Status','Used','Arrived','Resident','Created'],
        $stmt->fetchAll());
}

if ($action === 'guards') {
    $rows = db()->query("SELECT name, username, gate, phone, created_at FROM guards ORDER BY name")->fetchAll();
    exportCSV('MBGE_Guards_' . date('Ymd') . '.csv',
        ['Name','Username','Gate','Phone','Created'], $rows);
}

if ($action === 'sp') {
    $rows = db()->query("
        SELECT service_name, company_name, id_number, notes,
               start_date, end_date, approved, expired,
               resident_erfno, resident_name, created_at
        FROM service_providers
        ORDER BY end_date DESC")->fetchAll();
    exportCSV('MBGE_ServiceProviders_' . date('Ymd') . '.csv',
        ['Service/Name','Company','ID No','Notes','Start','End','Approved','Expired','Erf','Resident','Created'],
        $rows);
}

if ($action === 'logs') {
    $from = $_GET['from'] ?? date('Y-m-d');
    $to   = $_GET['to']   ?? date('Y-m-d');
    $stmt = db()->prepare("
        SELECT event_id, gate, direction, entry_type,
               person_name, visitor_name, plate, guard_name,
               deny_reason, created_at
        FROM access_log
        WHERE DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at DESC");
    $stmt->execute([$from, $to]);
    exportCSV('MBGE_AccessLog_' . $from . '_to_' . $to . '.csv',
        ['Event ID','Gate','Dir','Type','Person','Visitor','Plate','Guard','Deny Reason','Date/Time'],
        $stmt->fetchAll());
}

if ($action === 'access_today') {
    $date = $_GET['date'] ?? date('Y-m-d');
    $stmt = db()->prepare("SELECT * FROM access_log WHERE DATE(created_at)=? ORDER BY created_at DESC");
    $stmt->execute([$date]);
    $logs    = $stmt->fetchAll();
    $granted = array_filter($logs, fn($l) => empty($l['deny_reason']));
    $denied  = array_filter($logs, fn($l) => !empty($l['deny_reason']));

    pageHeader("Activity: $date", 'admin');
    renderHeader('📅 Activity: ' . date('d M Y', strtotime($date)), 'export.php?action=menu');
    ?>
    <div class="container">
      <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
        <form method="GET" style="display:flex;gap:8px;">
          <input type="hidden" name="action" value="access_today">
          <input type="date" name="date" value="<?= $date ?>"
            style="padding:8px;border:1px solid #dee2e6;border-radius:6px;">
          <button type="submit" class="btn btn-primary">View</button>
        </form>
        <a href="export.php?action=logs&from=<?= $date ?>&to=<?= $date ?>" class="btn btn-secondary">⬇ Download CSV</a>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:14px;">
        <div class="card" style="text-align:center;padding:14px;">
          <div style="font-size:1.8rem;font-weight:700;"><?= count($logs) ?></div>
          <div style="font-size:.8rem;color:#666;">Total Events</div>
        </div>
        <div class="card" style="text-align:center;padding:14px;">
          <div style="font-size:1.8rem;font-weight:700;color:#28a745;"><?= count($granted) ?></div>
          <div style="font-size:.8rem;color:#666;">Granted</div>
        </div>
        <div class="card" style="text-align:center;padding:14px;">
          <div style="font-size:1.8rem;font-weight:700;color:#dc3545;"><?= count($denied) ?></div>
          <div style="font-size:.8rem;color:#666;">Denied</div>
        </div>
      </div>
      <div class="card">
        <div class="table-wrap"><table>
          <tr><th>Time</th><th>Gate</th><th>Type</th><th>Name / Plate</th><th>Guard</th><th>Result</th></tr>
          <?php foreach ($logs as $l):
            $isDenied = !empty($l['deny_reason']); ?>
          <tr>
            <td style="white-space:nowrap"><?= date('H:i:s', strtotime($l['created_at'])) ?></td>
            <td><?= htmlspecialchars($l['gate'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['entry_type'] ?? '') ?></td>
            <td><?= htmlspecialchars($l['person_name'] ?? $l['visitor_name'] ?? $l['plate'] ?? '—') ?></td>
            <td><?= htmlspecialchars($l['guard_name'] ?? '') ?></td>
            <td><span class="badge badge-<?= $isDenied?'danger':'success' ?>">
              <?= $isDenied?'denied':'granted' ?>
            </span></td>
          </tr>
          <?php endforeach; ?>
        </table></div>
      </div>
    </div>
    <?php
    pageFooter(); exit;
}
