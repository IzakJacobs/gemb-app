<?php
// ============================================================
// GEMB Access Control — request_new.php
// Single resident entry point for new requests: Tenant or Pet.
// Routes to admin (not site manager) for approval, per delegated
// Board authority. Service Provider requests link out to the
// existing sp_request.php flow, not duplicated here.
// ============================================================
require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireResident();

$resident_erfno = $_SESSION['resident_erf'];
$resident_name  = $_SESSION['resident_name'] ?? null;

$type = $_GET['type'] ?? null; // 'tenant' or 'pet', null = show selector

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $type = $_POST['type'] ?? '';

    try {
        if ($type === 'tenant') {
            $tenant_name = trim($_POST['tenant_name'] ?? '');
            $id_number   = trim($_POST['id_number'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $lease_start = $_POST['lease_start'] ?? '';
            $lease_end   = $_POST['lease_end'] ?? '';
            $rules_ack   = isset($_POST['rules_ack']);

            if (!$tenant_name || !$lease_start || !$lease_end) {
                throw new Exception('Please complete all required fields.');
            }
            if (strtotime($lease_end) <= strtotime($lease_start)) {
                throw new Exception('Lease end date must be after lease start date.');
            }
            if (!$rules_ack) {
                throw new Exception('The tenant must accept the estate rules to proceed.');
            }

            // ADJUST: use your existing upload helper (same one sp_request.php uses for photo_path / id docs)
            $id_document = !empty($_FILES['id_document']['name'])
                ? upload_file($_FILES['id_document'], '/uploads/tenant_docs/') : null;
            $photo = !empty($_FILES['photo']['name'])
                ? upload_file($_FILES['photo'], '/uploads/sp_photos/') : null;

            db()->prepare("INSERT INTO tenants
                (resident_erfno, resident_name, tenant_name, id_number, id_document, photo, sp_phone, email,
                 lease_start, lease_end, rules_signed_at, rules_signed_ip, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'pending')"
            )->execute([
                $resident_erfno, $resident_name, $tenant_name, $id_number, $id_document, $photo, $phone, $email,
                $lease_start, $lease_end, $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            setFlash('success', 'Tenant request submitted. You will be notified once admin has reviewed it.');
            header('Location: request_new.php'); exit;

        } elseif ($type === 'pet') {
            $pet_type    = $_POST['pet_type'] ?? 'permanent';
            $pet_name    = trim($_POST['pet_name'] ?? '');
            $breed       = trim($_POST['breed'] ?? '');
            $weight      = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
            $visit_start = $_POST['visit_start'] ?? null;
            $visit_end   = $_POST['visit_end'] ?? null;

            if (!$pet_name) {
                throw new Exception("Please enter the pet's name.");
            }
            if ($pet_type === 'visitor') {
                if (!$visit_start || !$visit_end) {
                    throw new Exception('Please provide visit start and end dates.');
                }
                $days = (strtotime($visit_end) - strtotime($visit_start)) / 86400;
                if ($days < 0 || $days > 7) {
                    throw new Exception('Visitor pet stays may not exceed 7 days (Conduct Rule 1.4).');
                }
            } else {
                $visit_start = null;
                $visit_end = null;
                $check = db()->prepare(
                    "SELECT COUNT(*) FROM pets WHERE resident_erfno=? AND pet_type='permanent' AND status IN ('pending','approved')"
                );
                $check->execute([$resident_erfno]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception('This erf already has a permanent pet registered or pending. Only one permanent pet per erf is allowed.');
                }
            }

            $photo = !empty($_FILES['photo']['name'])
                ? upload_file($_FILES['photo'], '/uploads/pet_photos/') : null; // ADJUST: upload helper

            db()->prepare("INSERT INTO pets
                (resident_erfno, resident_name, pet_type, pet_name, breed, weight_kg, photo, visit_start, visit_end, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
            )->execute([$resident_erfno, $resident_name, $pet_type, $pet_name, $breed, $weight, $photo, $visit_start, $visit_end]);

            setFlash('success', 'Pet request submitted. You will be notified once admin has reviewed it.');
            header('Location: request_new.php'); exit;
        }
    } catch (Exception $e) {
        setFlash('error', $e->getMessage());
        header('Location: request_new.php' . ($type ? '?type=' . urlencode($type) : '')); exit;
    }
}

pageHeader('New Request', 'resident');
renderHeader('📝 New Request', 'my_requests.php');
?>
<div class="container">
  <?= getFlash() ?>

  <?php if (!$type): ?>
    <div class="menu-grid">
      <a href="request_new.php?type=tenant" class="menu-btn">
        <span class="icon">🏠</span>Register a Tenant
      </a>
      <a href="request_new.php?type=pet" class="menu-btn">
        <span class="icon">🐾</span>Register a Pet
      </a>
      <a href="sp_request.php" class="menu-btn">
        <span class="icon">👷</span>Register a Service Provider
      </a>
    </div>
    <p style="text-align:center;margin-top:16px;">
      <a href="my_requests.php">View my submitted requests &rarr;</a>
    </p>
  <?php endif; ?>

  <?php if ($type === 'tenant'): ?>
    <div class="card">
      <div class="card-title">Register a Tenant</div>
      <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="type" value="tenant">

        <div class="form-group"><label>Tenant full name *</label>
          <input type="text" name="tenant_name" required>
        </div>
        <div class="form-group"><label>ID number</label>
          <input type="text" name="id_number">
        </div>
        <div class="form-group"><label>Phone</label>
          <input type="tel" name="phone">
        </div>
        <div class="form-group"><label>Email</label>
          <input type="email" name="email">
        </div>
        <div class="form-group"><label>ID document (photo or PDF)</label>
          <input type="file" name="id_document" accept="image/*,.pdf">
        </div>
        <div class="form-group"><label>Tenant photo</label>
          <input type="file" name="photo" accept="image/*" capture="environment">
        </div>
        <div class="form-group"><label>Lease start *</label>
          <input type="date" name="lease_start" required>
        </div>
        <div class="form-group"><label>Lease end *</label>
          <input type="date" name="lease_end" required>
        </div>
        <div class="form-group" style="display:flex;gap:8px;align-items:flex-start;">
          <input type="checkbox" name="rules_ack" id="rules_ack" required style="width:auto;margin-top:3px;">
          <label for="rules_ack" style="font-weight:400;font-size:.85rem;">
            I confirm the tenant has received and accepts the Mossel Bay Golf Estate conduct rules,
            and this acceptance may be recorded as their written undertaking.
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Submit for Admin Approval</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($type === 'pet'): ?>
    <div class="card">
      <div class="card-title">Register a Pet</div>
      <form method="POST" enctype="multipart/form-data" id="petForm">
        <?= csrfField() ?>
        <input type="hidden" name="type" value="pet">

        <div class="form-group"><label>Pet type *</label>
          <select name="pet_type" id="pet_type" onchange="toggleVisitorFields()">
            <option value="permanent">Permanent resident pet</option>
            <option value="visitor">Visitor's pet (max 7 days)</option>
          </select>
        </div>
        <div class="form-group"><label>Pet name *</label>
          <input type="text" name="pet_name" required>
        </div>
        <div class="form-group"><label>Breed</label>
          <input type="text" name="breed">
        </div>
        <div class="form-group"><label>Adult weight (kg) <small style="color:#888;">max 15kg</small></label>
          <input type="number" name="weight_kg" step="0.1" min="0">
        </div>
        <div class="form-group"><label>Pet photo</label>
          <input type="file" name="photo" accept="image/*" capture="environment">
        </div>
        <div id="visitorFields" style="display:none;">
          <div class="form-group"><label>Visit start date *</label>
            <input type="date" name="visit_start">
          </div>
          <div class="form-group"><label>Visit end date * <small style="color:#888;">(max 7 days)</small></label>
            <input type="date" name="visit_end">
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Submit for Admin Approval</button>
      </form>
    </div>
    <script>
      function toggleVisitorFields() {
        var v = document.getElementById('pet_type').value === 'visitor';
        document.getElementById('visitorFields').style.display = v ? 'block' : 'none';
      }
    </script>
  <?php endif; ?>

</div>
<?php pageFooter(); ?>
