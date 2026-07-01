<?php
// ============================================================
// GEMB Access Control — admin_approvals.php
// Combined Tenant + Pet approval queue for admin, approved under
// delegated Board authority (MOI Art. 20.5.3).
// Service Provider approvals remain on their existing screen —
// not duplicated here.
// tenants: resident_erfno, resident_name, tenant_name, ..., status
// pets:    resident_erfno, resident_name, pet_name, pet_type, ..., status
// ============================================================
require_once __DIR__ . '/layout.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $request_type = $_POST['request_type'] ?? '';
    $id           = (int)($_POST['id'] ?? 0);
    $action       = $_POST['form_action'] ?? '';
    $reason       = trim($_POST['reason'] ?? '');

    $table = match ($request_type) {
        'tenant' => 'tenants',
        'pet'    => 'pets',
        default  => null,
    };

    if ($table && $id && in_array($action, ['approve', 'deny'], true)) {
        $status = $action === 'approve' ? 'approved' : 'denied';
        db()->prepare(
            "UPDATE {$table} SET status=?, approved_by=?, approved_at=NOW(), denial_reason=? WHERE id=?"
        )->execute([
            $status,
            $_SESSION['admin_name'] ?? 'Admin',
            $action === 'deny' ? $reason : null,
            $id,
        ]);

        // TODO: notify resident (email/in-app) of approval/denial.
        // TODO: if tenant approved, trigger permit_card.php / permit_slip.php generation.

        setFlash('success', ucfirst($request_type) . " request " . ($action === 'approve' ? 'approved' : 'denied') . '.');
    }
    header('Location: admin_approvals.php'); exit;
}

$tenants = db()->query("SELECT * FROM tenants WHERE status='pending' ORDER BY created_at ASC")->fetchAll();
$pets    = db()->query("SELECT * FROM pets WHERE status='pending' ORDER BY created_at ASC")->fetchAll();

pageHeader('Tenant & Pet Approvals', 'admin');
renderHeader('📋 Tenant & Pet Approvals', 'admin.php?action=menu');
?>
<div class="container">
  <?= getFlash() ?>
  <p style="font-size:.85rem;color:#666;margin-bottom:16px;">
    Approved here under delegated Board authority (MOI Art. 20.5.3).
    Service provider requests remain on the existing Service Providers screen.
  </p>

  <div class="card">
    <div class="card-title">Pending Tenant Requests (<?= count($tenants) ?>)</div>
    <?php if (empty($tenants)): ?>
      <p style="color:#666;">No pending tenant requests.</p>
    <?php endif; ?>
    <?php foreach ($tenants as $t): ?>
      <div style="border-top:1px solid #eee;padding:12px 0;">
        <div style="font-size:.82rem;color:#999;">
          Erf <strong><?= htmlspecialchars($t['resident_erfno']) ?></strong>
          — submitted by <?= htmlspecialchars($t['resident_name'] ?? '—') ?>
          on <?= date('d M Y', strtotime($t['created_at'])) ?>
        </div>
        <div style="font-weight:700;margin:4px 0;"><?= htmlspecialchars($t['tenant_name']) ?></div>
        <div style="font-size:.85rem;color:#555;">
          Lease: <?= date('d M Y', strtotime($t['lease_start'])) ?> &ndash; <?= date('d M Y', strtotime($t['lease_end'])) ?>
        </div>
        <div style="font-size:.85rem;color:#555;margin-bottom:8px;">
          Phone: <?= htmlspecialchars($t['sp_phone'] ?: '—') ?>
          &middot; Rules signed:
          <?= $t['rules_signed_at']
              ? '<span class="badge badge-success">Yes</span>'
              : '<span class="badge badge-warning">No</span>' ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <form method="POST" onsubmit="return confirm('Approve this tenant?');">
            <?= csrfField() ?>
            <input type="hidden" name="request_type" value="tenant">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <input type="hidden" name="form_action" value="approve">
            <button type="submit" class="btn btn-success btn-sm">Approve</button>
          </form>
          <form method="POST" style="display:flex;gap:6px;flex:1;min-width:220px;"
                onsubmit="return confirm('Deny this tenant request?');">
            <?= csrfField() ?>
            <input type="hidden" name="request_type" value="tenant">
            <input type="hidden" name="id" value="<?= $t['id'] ?>">
            <input type="hidden" name="form_action" value="deny">
            <input type="text" name="reason" placeholder="Reason (shown to resident)"
                   style="flex:1;padding:6px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:.85rem;">
            <button type="submit" class="btn btn-danger btn-sm">Deny</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-title">Pending Pet Requests (<?= count($pets) ?>)</div>
    <?php if (empty($pets)): ?>
      <p style="color:#666;">No pending pet requests.</p>
    <?php endif; ?>
    <?php foreach ($pets as $p): ?>
      <div style="border-top:1px solid #eee;padding:12px 0;">
        <div style="font-size:.82rem;color:#999;">
          Erf <strong><?= htmlspecialchars($p['resident_erfno']) ?></strong>
          — submitted by <?= htmlspecialchars($p['resident_name'] ?? '—') ?>
          on <?= date('d M Y', strtotime($p['created_at'])) ?>
        </div>
        <div style="font-weight:700;margin:4px 0;">
          <?= htmlspecialchars($p['pet_name']) ?>
          <span class="badge badge-info"><?= htmlspecialchars(ucfirst($p['pet_type'])) ?></span>
        </div>
        <div style="font-size:.85rem;color:#555;margin-bottom:8px;">
          <?= htmlspecialchars($p['breed'] ?: 'Breed not specified') ?>
          <?php if ($p['weight_kg']): ?>
            &middot; <?= htmlspecialchars($p['weight_kg']) ?>kg
            <?php if ($p['weight_kg'] > 15): ?>
              <span class="badge badge-danger">exceeds 15kg limit</span>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($p['pet_type'] === 'visitor'): ?>
            <br>Visit: <?= date('d M Y', strtotime($p['visit_start'])) ?> &ndash; <?= date('d M Y', strtotime($p['visit_end'])) ?>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <form method="POST" onsubmit="return confirm('Approve this pet?');">
            <?= csrfField() ?>
            <input type="hidden" name="request_type" value="pet">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <input type="hidden" name="form_action" value="approve">
            <button type="submit" class="btn btn-success btn-sm">Approve</button>
          </form>
          <form method="POST" style="display:flex;gap:6px;flex:1;min-width:220px;"
                onsubmit="return confirm('Deny this pet request?');">
            <?= csrfField() ?>
            <input type="hidden" name="request_type" value="pet">
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <input type="hidden" name="form_action" value="deny">
            <input type="text" name="reason" placeholder="Reason (shown to resident)"
                   style="flex:1;padding:6px 10px;border:1px solid #dee2e6;border-radius:6px;font-size:.85rem;">
            <button type="submit" class="btn btn-danger btn-sm">Deny</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <p style="text-align:center;"><a href="admin_registry.php">View full Tenant &amp; Pet Registry &rarr;</a></p>
</div>
<?php pageFooter(); ?>
