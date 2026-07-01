<?php
// ============================================================
// GEMB Access Control — my_requests.php
// Single status view for the resident covering Service Provider,
// Tenant, and Pet requests - avoids three separate "my X" screens.
// ============================================================
require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireResident();

$resident_erfno = $_SESSION['resident_erf'];

$stmt = db()->prepare("
    SELECT 'Service Provider' AS request_type, service_name AS detail_name, status, created_at, NULL AS denial_reason
    FROM service_providers WHERE resident_erfno = ?
    UNION ALL
    SELECT 'Tenant' AS request_type, tenant_name AS detail_name, status, created_at, denial_reason
    FROM tenants WHERE resident_erfno = ?
    UNION ALL
    SELECT CASE WHEN pet_type='visitor' THEN 'Visitor Pet' ELSE 'Pet' END AS request_type,
           pet_name AS detail_name, status, created_at, denial_reason
    FROM pets WHERE resident_erfno = ?
    ORDER BY created_at DESC
");
$stmt->execute([$resident_erfno, $resident_erfno, $resident_erfno]);
$rows = $stmt->fetchAll();

function statusBadge(string $status): string {
    $map = [
        'pending'  => 'badge-warning',
        'approved' => 'badge-success',
        'denied'   => 'badge-danger',
        'expired'  => 'badge-info',
        'invited'  => 'badge-info',   // matches service_providers status enum
        'revoked'  => 'badge-danger',
    ];
    $class = $map[$status] ?? 'badge-info';
    return '<span class="badge ' . $class . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}

pageHeader('My Requests', 'resident');
renderHeader('📋 My Requests', 'resident.php?action=menu');
?>
<div class="container">

  <?php if (empty($rows)): ?>
    <div class="card"><p style="color:#666;">No requests submitted yet.</p></div>
  <?php else: ?>
    <?php foreach ($rows as $r): ?>
      <div class="card">
        <div style="font-size:.78rem;color:#999;text-transform:uppercase;letter-spacing:.03em;">
          <?= htmlspecialchars($r['request_type']) ?>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin:2px 0 4px;">
          <div style="font-weight:700;"><?= htmlspecialchars($r['detail_name'] ?: '—') ?></div>
          <?= statusBadge($r['status']) ?>
        </div>
        <div style="font-size:.8rem;color:#999;">
          Submitted <?= date('d M Y', strtotime($r['created_at'])) ?>
        </div>
        <?php if ($r['status'] === 'denied' && $r['denial_reason']): ?>
          <div style="font-size:.83rem;color:#a02020;margin-top:6px;">
            Reason: <?= htmlspecialchars($r['denial_reason']) ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
<?php pageFooter(); ?>
