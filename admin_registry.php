<?php
// ============================================================
// GEMB Access Control — admin_registry.php
// Standing report: all registered tenants and pets, filterable by
// status, with a 30-day lease-expiry warning panel.
// ============================================================
require_once __DIR__ . '/layout.php';
requireAdmin();

$status_filter  = $_GET['status'] ?? 'approved';
$valid_statuses = ['all', 'pending', 'approved', 'denied', 'expired'];
if (!in_array($status_filter, $valid_statuses, true)) {
    $status_filter = 'approved';
}

$where  = $status_filter === 'all' ? '' : 'WHERE status = ?';
$params = $status_filter === 'all' ? [] : [$status_filter];

$tStmt = db()->prepare("SELECT * FROM tenants {$where} ORDER BY lease_end ASC");
$tStmt->execute($params);
$tenants = $tStmt->fetchAll();

$pStmt = db()->prepare("SELECT * FROM pets {$where} ORDER BY resident_erfno ASC");
$pStmt->execute($params);
$pets = $pStmt->fetchAll();

$expiring = array_filter($tenants, function ($t) {
    return $t['status'] === 'approved'
        && strtotime($t['lease_end']) >= time()
        && strtotime($t['lease_end']) <= strtotime('+30 days');
});

function statusBadge(string $status): string {
    $map = [
        'pending'  => 'badge-warning',
        'approved' => 'badge-success',
        'denied'   => 'badge-danger',
        'expired'  => 'badge-info',
    ];
    $class = $map[$status] ?? 'badge-info';
    return '<span class="badge ' . $class . '">' . htmlspecialchars(ucfirst($status)) . '</span>';
}

pageHeader('Tenant & Pet Registry', 'admin');
renderHeader('📋 Tenant & Pet Registry', 'admin.php?action=menu');
?>
<div class="container">

  <div class="card" style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php foreach ($valid_statuses as $s): ?>
      <a href="admin_registry.php?status=<?= $s ?>"
         class="btn btn-sm <?= $status_filter === $s ? 'btn-primary' : 'btn-secondary' ?>">
        <?= ucfirst($s) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if ($expiring): ?>
  <div class="card">
    <div class="card-title">⏰ Leases Expiring Within 30 Days</div>
    <div class="table-wrap"><table>
      <tr><th>Erf</th><th>Tenant</th><th>Lease End</th></tr>
      <?php foreach ($expiring as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['resident_erfno']) ?></td>
          <td><?= htmlspecialchars($t['tenant_name']) ?></td>
          <td><span class="badge badge-warning"><?= date('d M Y', strtotime($t['lease_end'])) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">Tenants (<?= count($tenants) ?>)</div>
    <?php if (empty($tenants)): ?>
      <p style="color:#666;">No records for this filter.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
      <tr><th>Erf</th><th>Tenant</th><th>Lease</th><th>Status</th><th>Resident</th></tr>
      <?php foreach ($tenants as $t): ?>
        <tr>
          <td><?= htmlspecialchars($t['resident_erfno']) ?></td>
          <td><?= htmlspecialchars($t['tenant_name']) ?></td>
          <td><?= date('d M y', strtotime($t['lease_start'])) ?>&ndash;<?= date('d M y', strtotime($t['lease_end'])) ?></td>
          <td><?= statusBadge($t['status']) ?></td>
          <td><?= htmlspecialchars($t['resident_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Pets (<?= count($pets) ?>)</div>
    <?php if (empty($pets)): ?>
      <p style="color:#666;">No records for this filter.</p>
    <?php else: ?>
    <div class="table-wrap"><table>
      <tr><th>Erf</th><th>Pet</th><th>Type</th><th>Status</th><th>Resident</th></tr>
      <?php foreach ($pets as $p): ?>
        <tr>
          <td><?= htmlspecialchars($p['resident_erfno']) ?></td>
          <td><?= htmlspecialchars($p['pet_name']) ?></td>
          <td><?= htmlspecialchars(ucfirst($p['pet_type'])) ?></td>
          <td><?= statusBadge($p['status']) ?></td>
          <td><?= htmlspecialchars($p['resident_name'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

</div>
<?php pageFooter(); ?>
