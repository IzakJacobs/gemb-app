<?php
// ============================================================
// gemB / MBGE — comms_contacts.php
// Standalone contact list management for the Communications module.
// Completely independent of residents or any other application table.
// Populated via CSV import or manual entry.
//
// Actions: list | new | edit | import | export
// ============================================================
require_once __DIR__ . '/comms_core.php';
commsRequireAuth();

$action = $_GET['action'] ?? 'list';

// ════════════════════════════════════════════════════════
// EXPORT — download all contacts as CSV (GET)
// ════════════════════════════════════════════════════════
if ($action === 'export') {
    $rows = db()->query(
        "SELECT email, name, erf, phone, group_tag, active
         FROM comms_contacts ORDER BY name, email"
    )->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="comms_contacts_' . date('Ymd') . '.csv"');
    header('Cache-Control: no-cache, no-store');

    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    fputcsv($fh, ['email', 'name', 'erf', 'phone', 'group_tag', 'active']);
    foreach ($rows as $r) {
        fputcsv($fh, [
            $r['email'],
            $r['name']      ?? '',
            $r['erf']       ?? '',
            $r['phone']     ?? '',
            $r['group_tag'] ?? '',
            $r['active'],
        ]);
    }
    fclose($fh);
    exit;
}

// ════════════════════════════════════════════════════════
// POST HANDLERS
// ════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $act = $_POST['form_action'] ?? '';

    // ── Add ──────────────────────────────────────────────
    if ($act === 'add') {
        $email    = strtolower(trim($_POST['email']     ?? ''));
        $name     = trim($_POST['name']      ?? '');
        $erf      = trim($_POST['erf']       ?? '');
        $phone    = trim($_POST['phone']     ?? '');
        $groupTag = trim($_POST['group_tag'] ?? '');
        $active   = isset($_POST['active']) ? 1 : 0;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'A valid email address is required.');
            header('Location: comms_contacts.php?action=new'); exit;
        }
        try {
            db()->prepare(
                "INSERT INTO comms_contacts (email, name, erf, phone, group_tag, active)
                 VALUES (?, ?, ?, ?, ?, ?)"
            )->execute([$email, $name ?: null, $erf ?: null, $phone ?: null, $groupTag ?: null, $active]);
            setFlash('success', "Contact added: {$email}");
        } catch (Exception $e) {
            setFlash('error', 'That email address is already in the contact list.');
            header('Location: comms_contacts.php?action=new'); exit;
        }
        header('Location: comms_contacts.php'); exit;
    }

    // ── Update ───────────────────────────────────────────
    if ($act === 'update') {
        $id       = (int)($_POST['id']       ?? 0);
        $email    = strtolower(trim($_POST['email']     ?? ''));
        $name     = trim($_POST['name']      ?? '');
        $erf      = trim($_POST['erf']       ?? '');
        $phone    = trim($_POST['phone']     ?? '');
        $groupTag = trim($_POST['group_tag'] ?? '');
        $active   = isset($_POST['active']) ? 1 : 0;

        if (!$id || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'A valid email address is required.');
            header('Location: comms_contacts.php?action=edit&id=' . $id); exit;
        }
        try {
            db()->prepare(
                "UPDATE comms_contacts SET email=?, name=?, erf=?, phone=?, group_tag=?, active=? WHERE id=?"
            )->execute([$email, $name ?: null, $erf ?: null, $phone ?: null, $groupTag ?: null, $active, $id]);
            setFlash('success', 'Contact updated.');
        } catch (Exception $e) {
            setFlash('error', 'That email address is already in the contact list.');
            header('Location: comms_contacts.php?action=edit&id=' . $id); exit;
        }
        header('Location: comms_contacts.php'); exit;
    }

    // ── Delete ───────────────────────────────────────────
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare("DELETE FROM comms_contacts WHERE id=?")->execute([$id]);
            setFlash('success', 'Contact removed.');
        }
        header('Location: comms_contacts.php'); exit;
    }

    // ── CSV Import ───────────────────────────────────────
    if ($act === 'import') {
        $mode = $_POST['import_mode'] ?? 'upsert'; // append | upsert | replace

        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'No CSV file uploaded.');
            header('Location: comms_contacts.php?action=import'); exit;
        }

        $ext = strtolower(pathinfo($_FILES['csv']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            setFlash('error', 'Please upload a .csv or .txt file.');
            header('Location: comms_contacts.php?action=import'); exit;
        }

        $rows = commsParseCsv($_FILES['csv']['tmp_name']);
        if (empty($rows)) {
            setFlash('error', 'No data rows found in the CSV. Check the file has a header row and data.');
            header('Location: comms_contacts.php?action=import'); exit;
        }

        if ($mode === 'replace') {
            db()->exec("DELETE FROM comms_contacts");
        }

        $added = $updated = $skipped = $invalid = 0;

        foreach ($rows as $row) {
            $email = $name = $erf = $phone = $groupTag = '';

            foreach ($row as $col => $val) {
                $k = strtolower(trim((string)$col));
                $v = trim((string)$val);
                if (in_array($k, ['email','e-mail','e_mail','email address','emailaddress','mail'], true)) {
                    $email = $v;
                } elseif (in_array($k, ['name','full_name','fullname','full name','resident','owner','contact name','contact'], true)) {
                    $name = $v;
                } elseif (in_array($k, ['erf','erf_number','erfno','erf number','erf no'], true)) {
                    $erf = $v;
                } elseif (in_array($k, ['phone','cell','mobile','telephone','tel','cellphone','cell number'], true)) {
                    $phone = $v;
                } elseif (in_array($k, ['group','group_tag','grouptag','category','list','tag'], true)) {
                    $groupTag = $v;
                }
            }

            // Fallback: scan for @ in any column
            if (!$email) {
                foreach ($row as $val) {
                    if (str_contains((string)$val, '@')) { $email = trim((string)$val); break; }
                }
            }

            $email = strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid++;
                continue;
            }

            // Find existing row
            $stmt = db()->prepare("SELECT id FROM comms_contacts WHERE email=? LIMIT 1");
            $stmt->execute([$email]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                if ($mode === 'append') {
                    $skipped++;
                } else {
                    // upsert or replace — update name/erf/phone/group but keep active status
                    db()->prepare(
                        "UPDATE comms_contacts SET name=?, erf=?, phone=?, group_tag=? WHERE id=?"
                    )->execute([$name ?: null, $erf ?: null, $phone ?: null, $groupTag ?: null, $existingId]);
                    $updated++;
                }
            } else {
                db()->prepare(
                    "INSERT INTO comms_contacts (email, name, erf, phone, group_tag) VALUES (?,?,?,?,?)"
                )->execute([$email, $name ?: null, $erf ?: null, $phone ?: null, $groupTag ?: null]);
                $added++;
            }
        }

        $parts = ["{$added} added"];
        if ($updated) $parts[] = "{$updated} updated";
        if ($skipped) $parts[] = "{$skipped} skipped (already exists)";
        if ($invalid) $parts[] = "{$invalid} invalid/missing email";

        setFlash('success', 'Import complete: ' . implode(', ', $parts) . '.');
        header('Location: comms_contacts.php'); exit;
    }

    header('Location: comms_contacts.php'); exit;
}

// ════════════════════════════════════════════════════════
// ADD NEW
// ════════════════════════════════════════════════════════
if ($action === 'new') {
    pageHeader('Add Contact', 'admin');
    renderHeader('➕ Add Contact', 'comms_contacts.php');
    ?>
    <div class="container" style="max-width:560px;">
      <div class="card">
        <?= getFlash() ?>
        <form method="POST" action="comms_contacts.php">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="add">

          <div class="form-group">
            <label>Email Address *</label>
            <input type="email" name="email" required placeholder="name@example.com"
                   autocomplete="off">
          </div>
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" placeholder="e.g. John Smith">
          </div>
          <div class="form-group">
            <label>ERF / Unit Number</label>
            <input type="text" name="erf" placeholder="e.g. 142">
          </div>
          <div class="form-group">
            <label>Phone / Cell</label>
            <input type="text" name="phone" placeholder="e.g. 082 123 4567">
          </div>
          <div class="form-group">
            <label>Group / Tag <small style="color:#888;">(optional — for filtering)</small></label>
            <input type="text" name="group_tag" placeholder="e.g. Owners, Tenants">
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="active" value="1" checked>
              Active (included in bulk sends)
            </label>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Add Contact</button>
        </form>
      </div>
      <div class="btn-group" style="margin-top:12px;">
        <a href="comms_contacts.php" class="btn btn-navy">← Back to Contacts</a>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// EDIT
// ════════════════════════════════════════════════════════
if ($action === 'edit') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { header('Location: comms_contacts.php'); exit; }

    $stmt = db()->prepare("SELECT * FROM comms_contacts WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $contact = $stmt->fetch();

    if (!$contact) {
        setFlash('error', 'Contact not found.');
        header('Location: comms_contacts.php'); exit;
    }

    pageHeader('Edit Contact', 'admin');
    renderHeader('✏️ Edit Contact', 'comms_contacts.php');
    ?>
    <div class="container" style="max-width:560px;">
      <div class="card">
        <?= getFlash() ?>
        <form method="POST" action="comms_contacts.php">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="update">
          <input type="hidden" name="id" value="<?= $contact['id'] ?>">

          <div class="form-group">
            <label>Email Address *</label>
            <input type="email" name="email" required autocomplete="off"
                   value="<?= htmlspecialchars($contact['email']) ?>">
          </div>
          <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name"
                   value="<?= htmlspecialchars($contact['name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>ERF / Unit Number</label>
            <input type="text" name="erf"
                   value="<?= htmlspecialchars($contact['erf'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Phone / Cell</label>
            <input type="text" name="phone"
                   value="<?= htmlspecialchars($contact['phone'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label>Group / Tag</label>
            <input type="text" name="group_tag"
                   value="<?= htmlspecialchars($contact['group_tag'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="active" value="1"
                     <?= $contact['active'] ? 'checked' : '' ?>>
              Active (included in bulk sends)
            </label>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
        </form>

        <div style="margin-top:20px;padding-top:16px;border-top:1px solid #eee;">
          <form method="POST" action="comms_contacts.php"
                onsubmit="return confirm('Delete this contact permanently?')">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="delete">
            <input type="hidden" name="id" value="<?= $contact['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete This Contact</button>
          </form>
        </div>
      </div>
      <div class="btn-group" style="margin-top:12px;">
        <a href="comms_contacts.php" class="btn btn-navy">← Back to Contacts</a>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// CSV IMPORT
// ════════════════════════════════════════════════════════
if ($action === 'import') {
    $totalCount = (int)db()->query("SELECT COUNT(*) FROM comms_contacts")->fetchColumn();

    pageHeader('Import Contacts', 'admin');
    renderHeader('📥 Import Contacts from CSV', 'comms_contacts.php');
    ?>
    <div class="container" style="max-width:640px;">
      <?= getFlash() ?>

      <div class="card">
        <div class="card-title">Upload CSV File</div>

        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
          <strong>Expected columns (header names are flexible):</strong><br>
          <code>email</code> (required) &nbsp;·&nbsp;
          <code>name</code> &nbsp;·&nbsp;
          <code>erf</code> &nbsp;·&nbsp;
          <code>phone</code> &nbsp;·&nbsp;
          <code>group_tag</code><br><br>
          Delimiters detected automatically: comma, semicolon, or tab.
          UTF-8 and Excel (BOM) files are both supported.
        </div>

        <form method="POST" enctype="multipart/form-data" action="comms_contacts.php">
          <?= csrfField() ?>
          <input type="hidden" name="form_action" value="import">

          <div class="form-group">
            <label>CSV File *</label>
            <input type="file" name="csv" accept=".csv,.txt" required
                   style="padding:10px;background:#f8f9fa;border:1px solid #dee2e6;
                          border-radius:6px;width:100%;">
          </div>

          <div class="form-group">
            <label>Import Mode</label>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:6px;">
              <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:normal;">
                <input type="radio" name="import_mode" value="upsert" checked style="margin-top:3px;">
                <span>
                  <strong>Add &amp; Update</strong> — add new contacts, update existing ones
                  (name / ERF / phone / group refreshed if email already exists).
                </span>
              </label>
              <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:normal;">
                <input type="radio" name="import_mode" value="append" style="margin-top:3px;">
                <span>
                  <strong>Append Only</strong> — add new contacts, skip any email already in the list.
                </span>
              </label>
              <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-weight:normal;">
                <input type="radio" name="import_mode" value="replace" style="margin-top:3px;">
                <span>
                  <strong>Replace All</strong> — <span style="color:#dc3545;font-weight:700;">clears the entire contact list</span>
                  (<?= $totalCount ?> contact<?= $totalCount !== 1 ? 's' : '' ?>) then imports the CSV fresh.
                </span>
              </label>
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-block"
                  onclick="return this.form.import_mode.value !== 'replace'
                           || confirm('This will DELETE all <?= $totalCount ?> existing contact(s) before importing. Continue?')">
            📥 Import CSV
          </button>
        </form>
      </div>

      <div class="card" style="margin-top:14px;">
        <div class="card-title">CSV Template</div>
        <p style="font-size:.88rem;color:#555;margin:0 0 10px;">
          Download a blank template to fill in, or export your current contacts to edit them.
        </p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="comms_contacts.php?action=template" class="btn btn-secondary btn-sm">
            ⬇ Download Blank Template
          </a>
          <?php if ($totalCount > 0): ?>
          <a href="comms_contacts.php?action=export" class="btn btn-secondary btn-sm">
            ⬇ Export Current Contacts
          </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="btn-group" style="margin-top:12px;">
        <a href="comms_contacts.php" class="btn btn-navy">← Back to Contacts</a>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// BLANK TEMPLATE DOWNLOAD (GET)
// ════════════════════════════════════════════════════════
if ($action === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="comms_contacts_template.csv"');
    header('Cache-Control: no-cache, no-store');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($fh, ['email', 'name', 'erf', 'phone', 'group_tag']);
    fputcsv($fh, ['owner@example.com', 'John Smith', '142', '082 123 4567', 'Owners']);
    fputcsv($fh, ['tenant@example.com', 'Jane Doe',  '88',  '071 987 6543', 'Tenants']);
    fclose($fh);
    exit;
}

// ════════════════════════════════════════════════════════
// LIST (default)
// ════════════════════════════════════════════════════════

// Search + filter
$search   = trim($_GET['q']     ?? '');
$groupFilter = trim($_GET['group'] ?? '');
$showAll  = ($_GET['show'] ?? '') === 'all';

// Build query
$where  = [];
$params = [];

if (!$showAll) {
    $where[]  = 'active = 1';
}
if ($search !== '') {
    $where[]  = '(email LIKE ? OR name LIKE ? OR erf LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($groupFilter !== '') {
    $where[]  = 'group_tag = ?';
    $params[] = $groupFilter;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$contacts = db()->prepare(
    "SELECT * FROM comms_contacts {$whereSql} ORDER BY name, email"
);
$contacts->execute($params);
$contacts = $contacts->fetchAll();

// Counts
$totalCount  = (int)db()->query("SELECT COUNT(*) FROM comms_contacts")->fetchColumn();
$activeCount = (int)db()->query("SELECT COUNT(*) FROM comms_contacts WHERE active=1")->fetchColumn();

// Distinct group tags for filter dropdown
$groupTags = db()->query(
    "SELECT DISTINCT group_tag FROM comms_contacts WHERE group_tag IS NOT NULL AND group_tag != '' ORDER BY group_tag"
)->fetchAll(PDO::FETCH_COLUMN);

pageHeader('Contacts', 'admin');
renderHeader('👥 Contact List', 'comms_menu.php');
?>
<div class="container">
  <?= getFlash() ?>

  <!-- Stats + actions bar -->
  <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;
                            justify-content:space-between;padding:14px 16px;margin-bottom:14px;">
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;">
      <span style="font-size:.9rem;color:#555;">
        <strong style="color:#003366;"><?= $activeCount ?></strong> active &nbsp;/&nbsp;
        <strong><?= $totalCount ?></strong> total
      </span>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="comms_contacts.php?action=new"    class="btn btn-success btn-sm">+ Add Contact</a>
      <a href="comms_contacts.php?action=import" class="btn btn-primary btn-sm">📥 Import CSV</a>
      <?php if ($totalCount > 0): ?>
      <a href="comms_contacts.php?action=export" class="btn btn-secondary btn-sm">⬇ Export CSV</a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Search / filter -->
  <div class="card" style="padding:14px 16px;margin-bottom:14px;">
    <form method="GET" action="comms_contacts.php"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
      <div style="flex:1;min-width:160px;">
        <label style="font-size:.8rem;color:#666;display:block;margin-bottom:4px;">Search</label>
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
               placeholder="Email, name or ERF…"
               style="width:100%;padding:8px 10px;border:1px solid #dee2e6;border-radius:6px;">
      </div>
      <?php if ($groupTags): ?>
      <div style="min-width:140px;">
        <label style="font-size:.8rem;color:#666;display:block;margin-bottom:4px;">Group</label>
        <select name="group"
                style="padding:8px 10px;border:1px solid #dee2e6;border-radius:6px;width:100%;">
          <option value="">All groups</option>
          <?php foreach ($groupTags as $gt): ?>
          <option value="<?= htmlspecialchars($gt) ?>"
                  <?= $groupFilter === $gt ? 'selected' : '' ?>>
            <?= htmlspecialchars($gt) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div>
        <label style="font-size:.8rem;color:#666;display:block;margin-bottom:4px;">Status</label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:8px 0;font-size:.9rem;font-weight:normal;">
          <input type="checkbox" name="show" value="all" <?= $showAll ? 'checked' : '' ?>>
          Include inactive
        </label>
      </div>
      <div>
        <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        <a href="comms_contacts.php" class="btn btn-secondary btn-sm" style="margin-left:4px;">Reset</a>
      </div>
    </form>
  </div>

  <!-- Contact table -->
  <div class="card">
    <div class="card-title">
      <?= count($contacts) ?> contact<?= count($contacts) !== 1 ? 's' : '' ?>
      <?= $search || $groupFilter || $showAll ? ' (filtered)' : '' ?>
    </div>

    <?php if (empty($contacts)): ?>
      <div style="color:#666;padding:20px 0;text-align:center;">
        <?php if ($totalCount === 0): ?>
          <p style="margin:0 0 12px;">No contacts yet.</p>
          <a href="comms_contacts.php?action=import" class="btn btn-primary">📥 Import from CSV</a>
          &nbsp;
          <a href="comms_contacts.php?action=new" class="btn btn-success">+ Add Manually</a>
        <?php else: ?>
          No contacts match your search.
        <?php endif; ?>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Email</th>
            <th>Name</th>
            <th>ERF</th>
            <th>Phone</th>
            <th>Group</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contacts as $c): ?>
          <tr>
            <td style="font-size:.85rem;"><?= htmlspecialchars($c['email']) ?></td>
            <td style="font-size:.85rem;"><?= htmlspecialchars($c['name'] ?? '—') ?></td>
            <td style="font-size:.85rem;"><?= htmlspecialchars($c['erf']  ?? '—') ?></td>
            <td style="font-size:.85rem;"><?= htmlspecialchars($c['phone'] ?? '—') ?></td>
            <td style="font-size:.82rem;">
              <?php if ($c['group_tag']): ?>
              <span class="badge" style="background:#455a64;color:#fff;font-size:.75rem;">
                <?= htmlspecialchars($c['group_tag']) ?>
              </span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <?= $c['active']
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-muted">Inactive</span>' ?>
            </td>
            <td>
              <a href="comms_contacts.php?action=edit&id=<?= $c['id'] ?>"
                 class="btn btn-primary btn-sm">Edit</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="btn-group" style="margin-top:16px;">
    <a href="comms_menu.php" class="btn btn-navy">← Back to Communications</a>
  </div>
</div>
<?php pageFooter();
