<?php
require_once __DIR__ . '/db.php';
requireAdmin();
$csrf = csrfToken();

$msg = '';
$msgType = 'ok';

// ---- Handle POST actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['act'] ?? '';

    // Add resident
    if ($act === 'add_resident') {
        $name  = trim($_POST['name']  ?? '');
        $unit  = strtoupper(trim($_POST['unit']  ?? ''));
        $email = trim($_POST['email'] ?? '');
        $pin   = trim($_POST['pin']   ?? '');

        if (!$name || !$unit || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $pin)) {
            $msg = 'Please fill in all fields correctly (6-digit PIN required).'; $msgType = 'err';
        } else {
            $chk = db()->prepare('SELECT id FROM users WHERE email=?');
            $chk->bind_param('s', $email); $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $msg = 'Email already registered.'; $msgType = 'err';
            } else {
                $hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 12]);
                $ins  = db()->prepare('INSERT INTO users (name,unit,email,pin_hash,status) VALUES (?,?,?,?,\'approved\')');
                $ins->bind_param('ssss', $name, $unit, $email, $hash);
                $ins->execute() ? ($msg = 'Resident added.') : ($msg = 'Error adding resident.'; $msgType = 'err');
            }
        }

    // Approve resident
    } elseif ($act === 'approve') {
        $id = (int)($_POST['uid'] ?? 0);
        $upd = db()->prepare("UPDATE users SET status='approved' WHERE id=?");
        $upd->bind_param('i', $id); $upd->execute();
        $msg = 'Resident approved.';

    // Reject / remove resident
    } elseif ($act === 'remove') {
        $id = (int)($_POST['uid'] ?? 0);
        $del = db()->prepare('DELETE FROM users WHERE id=?');
        $del->bind_param('i', $id); $del->execute();
        $msg = 'Resident removed.';

    // Generate HMAC secret
    } elseif ($act === 'gen_secret') {
        $secret = bin2hex(random_bytes(24));
        setSetting('hmac_secret', $secret);
        $msg = 'New signing key generated.';

    // Set guard code
    } elseif ($act === 'set_guard') {
        $code = trim($_POST['guard_code'] ?? '');
        if (strlen($code) < 4) { $msg = 'Guard code must be at least 4 characters.'; $msgType = 'err'; }
        else { setSetting('guard_code_hash', password_hash($code, PASSWORD_BCRYPT, ['cost' => 10])); $msg = 'Guard access code updated.'; }

    // Change admin password
    } elseif ($act === 'change_pw') {
        $cur = $_POST['cur_pw'] ?? '';
        $new = $_POST['new_pw'] ?? '';
        $stmt = db()->prepare('SELECT password FROM admins WHERE id=?');
        $stmt->bind_param('i', $_SESSION['admin_id']); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row || !password_verify($cur, $row['password'])) {
            $msg = 'Current password is incorrect.'; $msgType = 'err';
        } elseif (strlen($new) < 8) {
            $msg = 'New password must be at least 8 characters.'; $msgType = 'err';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $upd  = db()->prepare('UPDATE admins SET password=? WHERE id=?');
            $upd->bind_param('si', $hash, $_SESSION['admin_id']); $upd->execute();
            $msg = 'Admin password updated.';
        }
    }
}

// ---- Fetch data ----
$residents = db()->query("SELECT * FROM users ORDER BY status='pending' DESC, id DESC")->fetch_all(MYSQLI_ASSOC);
$pending   = array_filter($residents, fn($r) => $r['status'] === 'pending');
$approved  = array_filter($residents, fn($r) => $r['status'] === 'approved');

$hmacSecret = getSetting('hmac_secret', '');
$guardSet   = getSetting('guard_code_hash', '') !== '';

// Invitations (last 200)
$invitations = db()->query('SELECT i.*,u.unit FROM invitations i JOIN users u ON i.invited_by=u.id ORDER BY i.id DESC LIMIT 200')->fetch_all(MYSQLI_ASSOC);

// Access log (last 200)
$accessLog = db()->query('SELECT * FROM access_log ORDER BY logged_at DESC LIMIT 200')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard — GEMB</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:#f0f4f8;min-height:100vh}
    header{background:#002855;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:14px 18px}
    header h1{font-size:16px}
    .so{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:7px;padding:7px 12px;font-size:13px;cursor:pointer;text-decoration:none}
    .so:hover{background:rgba(255,255,255,.25)}
    .tabs{display:flex;background:#fff;border-bottom:2px solid #e0e8f0;overflow-x:auto}
    .tab-btn{flex:1;min-width:90px;padding:12px 8px;border:none;background:transparent;font-size:13px;font-weight:600;color:#777;cursor:pointer;border-bottom:3px solid transparent;white-space:nowrap}
    .tab-btn.active{color:#002855;border-bottom-color:#002855}
    .tab-btn .bc{display:inline-block;background:#dc3545;color:#fff;border-radius:9px;padding:1px 6px;font-size:11px;margin-left:4px}
    .tc{display:none;padding:16px;max-width:680px;margin:0 auto}.tc.active{display:block}
    .card{background:#fff;border-radius:12px;padding:18px;box-shadow:0 1px 8px rgba(0,0,0,.07);margin-bottom:16px}
    .ct{color:#002855;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:14px}
    .fl{display:flex;gap:10px}.fl .fd{flex:1}
    .fd,.field{margin-bottom:12px}
    .field label,.fd label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
    .field input,.fd input,.field select{width:100%;padding:10px 12px;font-size:14px;border:1px solid #d0d7e0;border-radius:8px;outline:none}
    .field input:focus,.fd input:focus{border-color:#002855}
    .btn-add{width:100%;padding:11px;background:#007bff;color:#fff;font-size:14px;font-weight:700;border:none;border-radius:8px;cursor:pointer;margin-top:4px}
    .btn-add:hover{background:#0062cc}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{background:#f0f4f8;color:#555;padding:8px 10px;text-align:left;font-size:12px}
    td{padding:9px 10px;border-bottom:1px solid #f0f4f8;vertical-align:middle}
    tr:last-child td{border-bottom:none}
    .bsm{border:none;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;color:#fff}
    .bsm.app{background:#28a745}.bsm.rej{background:#6c757d}.bsm.rm{background:#dc3545}
    .bsm:hover{opacity:.85}
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700}
    .badge.pending{background:#fff3cd;color:#856404}.badge.approved{background:#d4edda;color:#155724}
    .badge.granted{background:#d4edda;color:#155724}.badge.denied{background:#f8d7da;color:#721c24}
    .empty{text-align:center;color:#aaa;padding:20px;font-size:13px}
    .msg-g{background:#d4edda;color:#155724;border:1px solid #b7dfc0;border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:14px}
    .msg-e{background:#f8d7da;color:#721c24;border:1px solid #f5c6c6;border-radius:6px;padding:9px 11px;font-size:13px;margin-bottom:14px}
    .secret-box{background:#f0f4f8;border:1px solid #d0d7e0;border-radius:8px;padding:10px 14px;font-family:monospace;font-size:12px;word-break:break-all;margin-bottom:10px;color:#333}
    .btn-act{padding:10px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;color:#fff;margin-right:8px;margin-bottom:8px}
    .btn-act.p{background:#002855}.btn-act.p:hover{background:#001a3a}
    .btn-act.s{background:#28a745}.btn-act.s:hover{background:#218838}
    .btn-act.d{background:#dc3545}.btn-act.d:hover{background:#c0392b}
    hr{border:none;border-top:1px solid #e0e8f0;margin:20px 0}
    .guard-ok{color:#28a745;font-weight:700}.guard-na{color:#856404;font-weight:700}
  </style>
</head>
<body>
<header>
  <h1>GEMB Estate Admin</h1>
  <a class="so" href="logout.php">Sign Out</a>
</header>

<nav class="tabs">
  <button class="tab-btn active" onclick="sw('res')">
    Residents<?php if(count($pending)): ?><span class="bc"><?= count($pending) ?></span><?php endif ?>
  </button>
  <button class="tab-btn" onclick="sw('inv')">Invitations</button>
  <button class="tab-btn" onclick="sw('log')">Access Log</button>
  <button class="tab-btn" onclick="sw('set')">Settings</button>
</nav>

<?php if ($msg): ?>
<div style="max-width:680px;margin:12px auto;padding:0 16px">
  <div class="<?= $msgType==='err'?'msg-e':'msg-g' ?>"><?= e($msg) ?></div>
</div>
<?php endif ?>

<!-- Residents -->
<div id="resTab" class="tc active">
  <?php if (count($pending)): ?>
  <div class="card" style="border-left:4px solid #ffc107">
    <div class="ct">Pending Approvals (<?= count($pending) ?>)</div>
    <table>
      <thead><tr><th>Name</th><th>Unit</th><th>Email</th><th>Registered</th><th></th></tr></thead>
      <tbody>
        <?php foreach($pending as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td><td><?= e($r['unit']) ?></td><td><?= e($r['email']) ?></td>
          <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td>
            <form method="POST" style="display:inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="approve"><input type="hidden" name="uid" value="<?= (int)$r['id'] ?>"><button class="bsm app" type="submit">Approve</button></form>
            <form method="POST" style="display:inline" onsubmit="return confirm('Reject this registration?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="remove"><input type="hidden" name="uid" value="<?= (int)$r['id'] ?>"><button class="bsm rej" type="submit">Reject</button></form>
          </td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <div class="card">
    <div class="ct">Add Resident</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="act" value="add_resident">
      <div class="fl">
        <div class="fd"><label>Full Name</label><input type="text" name="name" placeholder="John Smith" required></div>
        <div class="fd"><label>Unit</label><input type="text" name="unit" placeholder="A12" required></div>
      </div>
      <div class="field"><label>Email</label><input type="email" name="email" placeholder="resident@email.com" required></div>
      <div class="field"><label>6-Digit PIN</label><input type="text" name="pin" maxlength="6" inputmode="numeric" placeholder="e.g. 123456" required></div>
      <button class="btn-add" type="submit">Add Resident (Auto-Approved)</button>
    </form>
  </div>

  <div class="card">
    <div class="ct">Approved Residents (<?= count($approved) ?>)</div>
    <table>
      <thead><tr><th>Name</th><th>Unit</th><th>Email</th><th>Added</th><th></th></tr></thead>
      <tbody>
        <?php foreach($approved as $r): ?>
        <tr>
          <td><?= e($r['name']) ?></td><td><?= e($r['unit']) ?></td><td><?= e($r['email']) ?></td>
          <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td>
            <form method="POST" onsubmit="return confirm('Remove this resident?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="remove"><input type="hidden" name="uid" value="<?= (int)$r['id'] ?>"><button class="bsm rm" type="submit">Remove</button></form>
          </td>
        </tr>
        <?php endforeach ?>
        <?php if(!count($approved)): ?><tr><td colspan="5" class="empty">No approved residents yet</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Invitations -->
<div id="invTab" class="tc">
  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead><tr><th>Date</th><th>Visitor</th><th>Plate</th><th>Invited by</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach($invitations as $i): ?>
        <tr>
          <td><?= e($i['visit_date']) ?></td>
          <td><?= e($i['visitor_name']) ?></td>
          <td><?= e($i['plate']) ?></td>
          <td><?= e($i['invited_by_name']) ?> / <?= e($i['unit']) ?></td>
          <td><span class="badge <?= e($i['status']) ?>"><?= strtoupper(e($i['status'])) ?></span></td>
        </tr>
        <?php endforeach ?>
        <?php if(!$invitations): ?><tr><td colspan="5" class="empty">No invitations yet</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Access Log -->
<div id="logTab" class="tc">
  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead><tr><th>Date/Time</th><th>Visitor</th><th>Plate</th><th>Invited by</th><th>Verify</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach($accessLog as $l): ?>
        <tr>
          <td><?= date('d M H:i', strtotime($l['logged_at'])) ?></td>
          <td><?= e($l['visitor_name']) ?></td>
          <td><?= e($l['plate']) ?></td>
          <td><?= e($l['invited_by_name']) ?><?= $l['unit']?' / '.e($l['unit']):'' ?></td>
          <td style="font-size:11px;color:#777"><?= e($l['verify_state']) ?></td>
          <td><span class="badge <?= e($l['action']) ?>"><?= strtoupper(e($l['action'])) ?></span></td>
        </tr>
        <?php endforeach ?>
        <?php if(!$accessLog): ?><tr><td colspan="6" class="empty">No log entries yet</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Settings -->
<div id="setTab" class="tc">
  <div class="card">

    <div class="ct">Estate QR Signing Key</div>
    <p style="font-size:13px;color:#555;margin-bottom:12px;line-height:1.5">
      This key signs all visitor QR codes on the server. Guards verify signatures server-side — the key is <strong>never sent to any browser</strong>. No action needed on resident or guard devices.
    </p>
    <div class="secret-box"><?= $hmacSecret ? e(substr($hmacSecret,0,8)).'…'.e(substr($hmacSecret,-4)).' ('.strlen($hmacSecret)*4 .' bits)' : 'Not generated yet' ?></div>
    <form method="POST" style="display:inline" onsubmit="return confirm('Generate new key? Old QR codes will no longer verify.')">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="act" value="gen_secret">
      <button class="btn-act s" type="submit">Generate New Signing Key</button>
    </form>

    <hr>

    <div class="ct">Guard Access Code</div>
    <p style="font-size:13px;color:#555;margin-bottom:12px">
      Status: <?= $guardSet ? '<span class="guard-ok">Configured</span>' : '<span class="guard-na">Not set — guards cannot log in</span>' ?>
    </p>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="act" value="set_guard">
      <div class="fl">
        <div class="fd"><label>New Guard Code</label><input type="password" name="guard_code" placeholder="Min 4 characters" autocomplete="new-password" required></div>
      </div>
      <button class="btn-act p" type="submit">Update Guard Code</button>
    </form>

    <hr>

    <div class="ct">Change Admin Password</div>
    <form method="POST">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="act" value="change_pw">
      <div class="field"><label>Current Password</label><input type="password" name="cur_pw" required autocomplete="current-password"></div>
      <div class="field"><label>New Password (min 8 chars)</label><input type="password" name="new_pw" required autocomplete="new-password"></div>
      <button class="btn-act p" type="submit">Change Password</button>
    </form>

    <hr>

    <div class="ct">Data Export</div>
    <a class="btn-act s" href="export.php" style="display:inline-block;text-decoration:none;margin-bottom:8px">Export All Data (JSON)</a>
  </div>
</div>

<script>
  function sw(tab){
    ['res','inv','log','set'].forEach(t=>{
      document.getElementById(t+'Tab').classList.toggle('active',t===tab);
    });
    document.querySelectorAll('.tab-btn').forEach((btn,i)=>{
      btn.classList.toggle('active',['res','inv','log','set'][i]===tab);
    });
  }
</script>
</body>
</html>
