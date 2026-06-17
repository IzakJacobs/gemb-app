<?php
// ============================================================
// GEMB Communications Portal — comms_menu.php
// Main hub after login. Handles menu, reports, and user management.
//
// Actions: menu (default) | reports | users
// Standalone visual design — own CSS, no shared layout with
// the estate access control system.
// ============================================================

require_once __DIR__ . '/comms_core.php';
commsRequireAuth();

// Session idle timeout — 4 hours for comms portal
if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 14400) {
    session_destroy();
    header('Location: comms_login.php'); exit;
}
$_SESSION['last_activity'] = time();

$action   = $_GET['action'] ?? 'menu';
$userName = commsCurrentUser();

// ─────────────────────────────────────────────────────────────
// SHARED LAYOUT HELPERS
// These output the standalone HTML shell — no dependency on
// layout.php's pageHeader()/pageFooter().
// ─────────────────────────────────────────────────────────────

function cmHeader(string $title, ?string $back = null): void {
    global $userName;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars($title) ?> — GEMB Communications</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --cp:      #0D47A1;
      --cp-dk:   #0A2F6B;
      --cp-lt:   #EEF4FF;
      --bg:      #F0F4FA;
      --white:   #FFFFFF;
      --border:  #C9D3E0;
      --text:    #1A1A2E;
      --muted:   #64748B;
      --success: #15803D;
      --danger:  #B91C1C;
      --warning: #92400E;
      --shadow:  0 2px 10px rgba(0,0,0,.07);
      --radius:  10px;
      --font: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
    }
    body { font-family: var(--font); background: var(--bg); color: var(--text); min-height: 100vh; }

    /* Topbar */
    .topbar {
      background: var(--cp);
      color: #fff;
      height: 58px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 20px;
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 2px 10px rgba(0,0,0,.2);
    }
    .topbar-brand { display: flex; align-items: center; gap: 10px; }
    .topbar-brand .tb-icon { font-size: 1.5rem; }
    .topbar-brand h1 { font-size: 1rem; font-weight: 700; line-height: 1.2; }
    .topbar-brand .tb-sub { font-size: .7rem; opacity: .7; }
    .topbar-right { display: flex; align-items: center; gap: 10px; }
    .user-pill {
      background: rgba(255,255,255,.15);
      border: 1px solid rgba(255,255,255,.25);
      padding: 4px 12px; border-radius: 20px;
      font-size: .8rem;
    }
    .btn-logout {
      background: transparent;
      border: 1px solid rgba(255,255,255,.4);
      color: rgba(255,255,255,.9);
      padding: 5px 14px; border-radius: 20px;
      font-size: .8rem;
      text-decoration: none;
      transition: background .15s;
    }
    .btn-logout:hover { background: rgba(255,255,255,.15); }

    /* Breadcrumb */
    .breadcrumb {
      background: var(--cp-lt);
      border-bottom: 1px solid var(--border);
      padding: 9px 20px;
      font-size: .83rem;
    }
    .breadcrumb a { color: var(--cp); text-decoration: none; font-weight: 600; }
    .breadcrumb a:hover { text-decoration: underline; }

    /* Page container */
    .page { max-width: 900px; margin: 0 auto; padding: 26px 16px 70px; }

    /* Cards */
    .card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 20px;
      margin-bottom: 20px;
    }
    .card-title {
      font-size: .95rem; font-weight: 700;
      color: var(--cp);
      margin-bottom: 14px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--border);
    }

    /* Alerts / banners */
    .alert {
      padding: 13px 16px; border-radius: 8px;
      margin-bottom: 18px; font-size: .9rem; line-height: 1.5;
    }
    .alert-warn {
      background: #FFF7ED;
      border: 1px solid #FED7AA;
      color: var(--warning);
    }
    .alert-info {
      background: #EFF6FF;
      border: 1px solid #BFDBFE;
      color: #1D4ED8;
    }
    .alert-success {
      background: #F0FDF4;
      border: 1px solid #BBF7D0;
      color: var(--success);
    }
    .alert-danger {
      background: #FEF2F2;
      border: 1px solid #FECACA;
      color: var(--danger);
    }

    /* Import notice banner */
    .import-banner {
      background: linear-gradient(135deg, #7C3AED, #4F46E5);
      color: #fff;
      border-radius: var(--radius);
      padding: 18px 22px;
      margin-bottom: 22px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 12px;
    }
    .import-banner h3 { font-size: .95rem; font-weight: 700; margin-bottom: 3px; }
    .import-banner p  { font-size: .83rem; opacity: .9; }
    .btn-import-now {
      background: #fff;
      color: #4F46E5;
      padding: 9px 20px;
      border-radius: 8px;
      font-weight: 700;
      text-decoration: none;
      font-size: .88rem;
      white-space: nowrap;
    }

    /* Contact stat bar */
    .stat-bar {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 14px 18px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      gap: 14px;
      box-shadow: var(--shadow);
    }
    .stat-bar .sb-icon  { font-size: 1.8rem; }
    .stat-bar .sb-count { font-size: 1.5rem; font-weight: 800; color: var(--cp); line-height: 1; }
    .stat-bar .sb-label { font-size: .78rem; color: var(--muted); margin-top: 2px; }
    .stat-bar .sb-btns  { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }

    /* Section headings */
    .section-heading {
      font-size: .73rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: var(--muted);
      margin-bottom: 12px;
    }

    /* Channel tiles */
    .channel-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(155px, 1fr));
      gap: 13px;
      margin-bottom: 30px;
    }
    .ctile {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: var(--white);
      border: 2px solid var(--border);
      border-radius: var(--radius);
      padding: 24px 14px 20px;
      text-decoration: none;
      color: var(--text);
      font-weight: 700;
      font-size: .88rem;
      text-align: center;
      gap: 10px;
      box-shadow: var(--shadow);
      transition: border-color .15s, transform .15s, box-shadow .15s, color .15s;
    }
    .ctile:hover {
      border-color: var(--cp);
      transform: translateY(-3px);
      box-shadow: 0 8px 24px rgba(13,71,161,.13);
      color: var(--cp);
    }
    .ctile .ci { font-size: 2rem; }
    .ctile.ctile-primary {
      border-color: var(--cp);
      background: var(--cp-lt);
      color: var(--cp);
    }
    .ctile.ctile-primary:hover {
      background: var(--cp);
      color: #fff;
    }

    /* Tables */
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .87rem; }
    th { background: var(--cp); color: #fff; padding: 9px 10px; text-align: left; font-weight: 600; }
    td { padding: 8px 10px; border-bottom: 1px solid var(--border); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: #F8FAFF; }
    .no-data { color: var(--muted); padding: 16px 0; }

    /* Badge */
    .badge {
      display: inline-block; padding: 3px 9px; border-radius: 20px;
      font-size: .73rem; font-weight: 700; color: #fff;
    }
    .badge-success { background: var(--success); color: #fff; }
    .badge-danger  { background: var(--danger);  color: #fff; }
    .badge-muted   { background: #94A3B8; color: #fff; }

    /* Buttons */
    .btn {
      display: inline-block; padding: 9px 18px; border-radius: 7px;
      font-size: .87rem; font-weight: 600; text-decoration: none;
      border: 1.5px solid transparent; cursor: pointer;
      transition: opacity .15s, transform .1s;
    }
    .btn:hover   { opacity: .88; }
    .btn:active  { transform: scale(.98); }
    .btn-primary { background: var(--cp); color: #fff; }
    .btn-success { background: var(--success); color: #fff; }
    .btn-danger  { background: var(--danger);  color: #fff; }
    .btn-outline { background: transparent; color: var(--cp); border-color: var(--cp); }
    .btn-grey    { background: #94A3B8; color: #fff; }
    .btn-sm      { padding: 5px 12px; font-size: .8rem; }
    .btn-block   { display: block; width: 100%; text-align: center; }

    /* Forms */
    .form-group { margin-bottom: 16px; }
    .form-group label {
      display: block; font-size: .8rem; font-weight: 700;
      color: var(--muted); text-transform: uppercase;
      letter-spacing: .5px; margin-bottom: 6px;
    }
    .form-group input, .form-group select {
      width: 100%; padding: 10px 12px;
      border: 1.5px solid var(--border);
      border-radius: 7px; font-size: .93rem;
      font-family: var(--font); background: var(--bg);
      transition: border-color .2s, box-shadow .2s;
    }
    .form-group input:focus, .form-group select:focus {
      outline: none;
      border-color: var(--cp);
      box-shadow: 0 0 0 3px rgba(13,71,161,.1);
      background: #fff;
    }
    .form-check {
      display: flex; align-items: center; gap: 8px;
      cursor: pointer; font-size: .9rem; font-weight: normal;
    }

    /* POPIA footer */
    .popia-foot {
      text-align: center; font-size: .7rem; color: var(--muted);
      padding: 16px; border-top: 1px solid var(--border);
      margin-top: 30px;
    }

    /* Filter pills (reports) */
    .filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
    .pill {
      padding: 5px 14px; border-radius: 20px;
      font-size: .8rem; font-weight: 600;
      text-decoration: none; border: 1.5px solid var(--border);
      color: var(--text); background: var(--white);
      transition: all .15s;
    }
    .pill.active, .pill:hover {
      background: var(--cp); color: #fff; border-color: var(--cp);
    }

    @media (max-width: 600px) {
      .channel-grid { grid-template-columns: repeat(2, 1fr); }
      .stat-bar .sb-btns { display: none; }
      .topbar-brand .tb-sub { display: none; }
    }
  </style>
</head>
<body>

  <!-- Top navigation bar -->
  <div class="topbar">
    <div class="topbar-brand">
      <span class="tb-icon">📣</span>
      <div>
        <h1>GEMB Communications</h1>
        <div class="tb-sub">Estate Communications Portal</div>
      </div>
    </div>
    <div class="topbar-right">
      <span class="user-pill">👤 <?= htmlspecialchars($userName) ?></span>
      <a href="comms_login.php?action=logout" class="btn-logout">Sign Out</a>
    </div>
  </div>

  <?php if ($back): ?>
  <div class="breadcrumb">
    <a href="<?= htmlspecialchars($back) ?>">&larr; Back</a>
  </div>
  <?php endif; ?>

  <div class="page">
    <?php
}

function cmFooter(): void {
    ?>
    <div class="popia-foot">
      GEMB Estate &nbsp;&middot;&nbsp; Communications Module
      &nbsp;&middot;&nbsp; POPIA Act 4 of 2013 &nbsp;&middot;&nbsp;
      All activity is logged.
    </div>
  </div><!-- .page -->
</body>
</html>
    <?php
}

// Helper: inline flash (own version, not using layout.php getFlash)
function cmFlash(): void {
    if (!isset($_SESSION['flash_msg'])) return;
    $type = $_SESSION['flash_type'] ?? 'info';
    $map  = ['success' => 'alert-success', 'error' => 'alert-danger',
             'info'    => 'alert-info',    'warning' => 'alert-warn'];
    $cls  = $map[$type] ?? 'alert-info';
    echo '<div class="alert ' . $cls . '">' . htmlspecialchars($_SESSION['flash_msg']) . '</div>';
    unset($_SESSION['flash_type'], $_SESSION['flash_msg']);
}


// ═══════════════════════════════════════════════════════════════
// USERS — POST HANDLERS
// Must run before any HTML output
// ═══════════════════════════════════════════════════════════════
if ($action === 'users' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $act = $_POST['form_action'] ?? '';

    // ── Add ─────────────────────────────────────────────────
    if ($act === 'add') {
        $uname    = trim($_POST['username']  ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email']     ?? '');
        $password = $_POST['password']       ?? '';

        if ($uname === '') {
            setFlash('error', 'Username is required.');
            header('Location: comms_menu.php?action=users&new=1'); exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
            header('Location: comms_menu.php?action=users&new=1'); exit;
        }
        if (strlen($password) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
            header('Location: comms_menu.php?action=users&new=1'); exit;
        }
        try {
            db()->prepare(
                "INSERT INTO comms_users (username, password, full_name, email, active) VALUES (?,?,?,?,1)"
            )->execute([
                $uname,
                password_hash($password, PASSWORD_BCRYPT),
                $fullName ?: null,
                $email    ?: null,
            ]);
            setFlash('success', "Comms user {$uname} created.");
            header('Location: comms_menu.php?action=users'); exit;
        } catch (Exception $e) {
            setFlash('error', 'That username is already taken.');
            header('Location: comms_menu.php?action=users&new=1'); exit;
        }
    }

    // ── Update ───────────────────────────────────────────────
    if ($act === 'update') {
        $uid      = (int)($_POST['uid']      ?? 0);
        $uname    = trim($_POST['username']  ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email']     ?? '');
        $password = $_POST['password']       ?? '';
        $active   = isset($_POST['active'])  ? 1 : 0;

        if (!$uid || $uname === '') {
            setFlash('error', 'Username is required.');
            header('Location: comms_menu.php?action=users&edit=' . $uid); exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            setFlash('error', 'Please enter a valid email address.');
            header('Location: comms_menu.php?action=users&edit=' . $uid); exit;
        }

        $self  = (int)($_SESSION['comms_user_id'] ?? 0);
        $count = (int)db()->query("SELECT COUNT(*) FROM comms_users WHERE active=1")->fetchColumn();
        if (!$active && $uid === $self) {
            setFlash('error', 'You cannot deactivate the account you are logged in with.');
            header('Location: comms_menu.php?action=users&edit=' . $uid); exit;
        }
        if (!$active && $count <= 1) {
            setFlash('error', 'Cannot deactivate the last remaining active user.');
            header('Location: comms_menu.php?action=users&edit=' . $uid); exit;
        }

        try {
            if ($password !== '') {
                if (strlen($password) < 8) {
                    setFlash('error', 'Password must be at least 8 characters.');
                    header('Location: comms_menu.php?action=users&edit=' . $uid); exit;
                }
                db()->prepare(
                    "UPDATE comms_users SET username=?,full_name=?,email=?,password=?,active=? WHERE id=?"
                )->execute([$uname, $fullName ?: null, $email ?: null,
                             password_hash($password, PASSWORD_BCRYPT), $active, $uid]);
            } else {
                db()->prepare(
                    "UPDATE comms_users SET username=?,full_name=?,email=?,active=? WHERE id=?"
                )->execute([$uname, $fullName ?: null, $email ?: null, $active, $uid]);
            }
            setFlash('success', 'User updated.');
        } catch (Exception $e) {
            setFlash('error', 'That username is already taken.');
        }
        header('Location: comms_menu.php?action=users'); exit;
    }

    // ── Delete ───────────────────────────────────────────────
    if ($act === 'delete') {
        $uid   = (int)($_POST['uid']  ?? 0);
        $self  = (int)($_SESSION['comms_user_id'] ?? 0);
        $count = (int)db()->query("SELECT COUNT(*) FROM comms_users WHERE active=1")->fetchColumn();

        if ($uid === $self) {
            setFlash('error', 'You cannot delete the account you are currently signed in with.');
        } elseif ($count <= 1) {
            setFlash('error', 'Cannot delete the last remaining active user.');
        } else {
            db()->prepare("DELETE FROM comms_users WHERE id=?")->execute([$uid]);
            setFlash('success', 'User removed.');
        }
        header('Location: comms_menu.php?action=users'); exit;
    }

    header('Location: comms_menu.php?action=users'); exit;
}


// ═══════════════════════════════════════════════════════════════
// MENU — main landing page
// ═══════════════════════════════════════════════════════════════
if ($action === 'menu') {

    $contactCount = commsRecipientCount('comms_contacts');
    $recent       = commsBroadcastList(null, 8);

    $channelLabels = [
        'bulk'    => ['📤', 'Bulk Message',   '#1565C0'],
        'levy'    => ['💰', 'Levy Statement', '#15803D'],
        'survey'  => ['📋', 'Survey',         '#6D28D9'],
        'vote'    => ['🗳️', 'Voting',         '#3730A3'],
        'archive' => ['📄', 'Archive',        '#374151'],
    ];

    cmHeader('Communications Menu');
    ?>

    <!-- CSV import banner — only shown when contact list is empty -->
    <?php if ($contactCount === 0): ?>
    <div class="import-banner">
      <div>
        <h3>⚠️ No contacts imported yet</h3>
        <p>A CSV contact list is required before any communication can be sent.</p>
      </div>
      <a href="comms_contacts.php?action=import" class="btn-import-now">📥 Import Contacts Now</a>
    </div>
    <?php endif; ?>

    <!-- Contact stat bar -->
    <div class="stat-bar">
      <div class="sb-icon">👥</div>
      <div>
        <div class="sb-count"><?= number_format($contactCount) ?></div>
        <div class="sb-label">active contact<?= $contactCount !== 1 ? 's' : '' ?> in standalone list</div>
      </div>
      <div class="sb-btns">
        <a href="comms_contacts.php" class="btn btn-outline btn-sm">Manage</a>
        <a href="comms_contacts.php?action=import" class="btn btn-primary btn-sm">📥 Import CSV</a>
      </div>
    </div>

    <!-- Communications channels -->
    <div class="section-heading">Send Communications</div>
    <div class="channel-grid">
      <a href="comms_contacts.php"                      class="ctile ctile-primary">
        <span class="ci">👥</span>Contact List
      </a>
      <a href="comms_bulk.php"                          class="ctile">
        <span class="ci">📤</span>Bulk Messages
      </a>
      <a href="comms_levy.php"                          class="ctile">
        <span class="ci">💰</span>Levy Statements
      </a>
      <a href="comms_surveys.php?action=list"           class="ctile">
        <span class="ci">📋</span>Surveys &amp; Polls
      </a>
      <a href="comms_voting.php?action=meetings_list"   class="ctile">
        <span class="ci">🗳️</span>Voting
      </a>
      <a href="comms_archive.php"                       class="ctile">
        <span class="ci">📄</span>Archive
      </a>
    </div>

    <!-- Administration -->
    <div class="section-heading">Administration</div>
    <div class="channel-grid">
      <a href="comms_menu.php?action=reports" class="ctile">
        <span class="ci">📊</span>Reports
      </a>
      <a href="comms_menu.php?action=users"   class="ctile">
        <span class="ci">👤</span>Comms Users
      </a>
    </div>

    <!-- Recent activity -->
    <?php if (!empty($recent)): ?>
    <div class="card">
      <div class="card-title">Recent Activity</div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Date</th><th>Channel</th><th>Title</th><th>Sent</th><th>Failed</th></tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $b):
              [$icon, $label, $color] = $channelLabels[$b['channel']] ?? ['📣', ucfirst($b['channel']), '#64748B'];
            ?>
            <tr>
              <td style="white-space:nowrap;font-size:.82rem;">
                <?= date('d M Y H:i', strtotime($b['created_at'])) ?>
              </td>
              <td>
                <span class="badge" style="background:<?= $color ?>;">
                  <?= $icon ?> <?= htmlspecialchars($label) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($b['title']) ?></td>
              <td style="color:var(--success);font-weight:700;"><?= $b['sent_to'] ?></td>
              <td style="color:<?= $b['failed']>0?'var(--danger)':'var(--muted)' ?>;font-weight:700;">
                <?= $b['failed'] ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <?php
    cmFooter(); exit;
}


// ═══════════════════════════════════════════════════════════════
// REPORTS — unified send history across all channels
// ═══════════════════════════════════════════════════════════════
if ($action === 'reports') {

    $ch     = trim($_GET['channel'] ?? '');
    $valid  = ['bulk','levy','survey','vote','archive'];
    $ch     = in_array($ch, $valid, true) ? $ch : null;

    $broadcasts = commsBroadcastList($ch, 200);

    $channelLabels = [
        'bulk'    => ['📤', 'Bulk Message',   '#1565C0'],
        'levy'    => ['💰', 'Levy Statement', '#15803D'],
        'survey'  => ['📋', 'Survey',         '#6D28D9'],
        'vote'    => ['🗳️', 'Voting',         '#3730A3'],
        'archive' => ['📄', 'Archive',        '#374151'],
    ];
    $channelDest = [
        'bulk'    => 'comms_bulk.php?action=log&id=',
        'levy'    => 'comms_levy.php?action=log&id=',
        'archive' => 'comms_archive.php?action=view&id=',
    ];

    cmHeader('Reports', 'comms_menu.php');
    ?>

    <div class="card">
      <div class="card-title">📊 Send Reports</div>

      <!-- Channel filter pills -->
      <div class="filter-pills" style="margin-bottom:18px;">
        <a href="comms_menu.php?action=reports"
           class="pill <?= $ch === null ? 'active' : '' ?>">All</a>
        <?php foreach ($channelLabels as $key => [$ic, $lb, $col]): ?>
        <a href="comms_menu.php?action=reports&channel=<?= $key ?>"
           class="pill <?= $ch === $key ? 'active' : '' ?>">
          <?= $ic ?> <?= htmlspecialchars($lb) ?>
        </a>
        <?php endforeach; ?>
      </div>

      <?php if (empty($broadcasts)): ?>
        <p class="no-data">No communications found<?= $ch ? ' for this channel' : '' ?>.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Date</th><th>Channel</th><th>Title</th><th>Sent</th><th>Failed</th><th>Log</th></tr>
          </thead>
          <tbody>
            <?php foreach ($broadcasts as $b):
              [$icon, $label, $color] = $channelLabels[$b['channel']] ?? ['📣', ucfirst($b['channel']), '#64748B'];
              $dest = $channelDest[$b['channel']] ?? null;
            ?>
            <tr>
              <td style="white-space:nowrap;font-size:.82rem;">
                <?= date('d M Y H:i', strtotime($b['created_at'])) ?>
              </td>
              <td>
                <span class="badge" style="background:<?= $color ?>;">
                  <?= $icon ?> <?= htmlspecialchars($label) ?>
                </span>
              </td>
              <td><?= htmlspecialchars($b['title']) ?></td>
              <td style="color:var(--success);font-weight:700;"><?= $b['sent_to'] ?></td>
              <td style="color:<?= $b['failed']>0?'var(--danger)':'var(--muted)' ?>;font-weight:700;">
                <?= $b['failed'] ?>
              </td>
              <td>
                <?php if ($dest): ?>
                <a href="<?= $dest . (int)$b['id'] ?>" class="btn btn-outline btn-sm">View</a>
                <?php else: ?>
                <span style="color:var(--muted);font-size:.78rem;">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php
    cmFooter(); exit;
}


// ═══════════════════════════════════════════════════════════════
// USERS — manage comms_users accounts
// ═══════════════════════════════════════════════════════════════
if ($action === 'users') {

    $self  = (int)($_SESSION['comms_user_id'] ?? 0);

    // ── Add form ─────────────────────────────────────────────
    if (isset($_GET['new'])) {
        cmHeader('Add Comms User', 'comms_menu.php?action=users');
        cmFlash();
        ?>
        <div class="card" style="max-width:520px;">
          <div class="card-title">➕ Add Comms User</div>
          <div class="alert alert-info" style="margin-bottom:16px;font-size:.87rem;">
            This account grants access to <strong>comms_login.php</strong> only —
            it cannot log into the estate access control system.
          </div>
          <form method="POST" action="comms_menu.php?action=users">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="add">
            <div class="form-group">
              <label>Username *</label>
              <input type="text" name="username" required autocomplete="off">
            </div>
            <div class="form-group">
              <label>Full Name</label>
              <input type="text" name="full_name">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" placeholder="name@example.com">
            </div>
            <div class="form-group">
              <label>Password * (min 8 characters)</label>
              <input type="password" name="password" required autocomplete="new-password" minlength="8">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Create User</button>
          </form>
        </div>
        <?php
        cmFooter(); exit;
    }

    // ── Edit form ─────────────────────────────────────────────
    if (isset($_GET['edit'])) {
        $uid   = (int)$_GET['edit'];
        $stmtE = db()->prepare("SELECT id,username,full_name,email,active FROM comms_users WHERE id=? LIMIT 1");
        $stmtE->execute([$uid]);
        $cu = $stmtE->fetch();

        if (!$cu) {
            setFlash('error', 'User not found.');
            header('Location: comms_menu.php?action=users'); exit;
        }

        cmHeader('Edit Comms User', 'comms_menu.php?action=users');
        cmFlash();
        ?>
        <div class="card" style="max-width:520px;">
          <div class="card-title">✏️ Edit — <?= htmlspecialchars($cu['username']) ?></div>
          <form method="POST" action="comms_menu.php?action=users">
            <?= csrfField() ?>
            <input type="hidden" name="form_action" value="update">
            <input type="hidden" name="uid" value="<?= $cu['id'] ?>">
            <div class="form-group">
              <label>Username *</label>
              <input type="text" name="username" required autocomplete="off"
                     value="<?= htmlspecialchars($cu['username']) ?>">
            </div>
            <div class="form-group">
              <label>Full Name</label>
              <input type="text" name="full_name" value="<?= htmlspecialchars($cu['full_name'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Email</label>
              <input type="email" name="email" value="<?= htmlspecialchars($cu['email'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>New Password (leave blank to keep current)</label>
              <input type="password" name="password" autocomplete="new-password" minlength="8"
                     placeholder="••••••••">
            </div>
            <div class="form-group">
              <label class="form-check">
                <input type="checkbox" name="active" value="1" <?= $cu['active'] ? 'checked' : '' ?>>
                Active (can sign in)
              </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Save Changes</button>
          </form>

          <?php
          $count       = (int)db()->query("SELECT COUNT(*) FROM comms_users WHERE active=1")->fetchColumn();
          $isSelf      = ((int)$cu['id'] === $self);
          $isLastActive= ($cu['active'] && $count <= 1);
          if (!$isSelf && !$isLastActive):
          ?>
          <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border);">
            <form method="POST" action="comms_menu.php?action=users"
                  onsubmit="return confirm('Delete this user permanently?')">
              <?= csrfField() ?>
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="uid" value="<?= $cu['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete User</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php
        cmFooter(); exit;
    }

    // ── List ─────────────────────────────────────────────────
    $users = db()->query(
        "SELECT id, username, full_name, email, active FROM comms_users ORDER BY username"
    )->fetchAll();
    $count = (int)db()->query("SELECT COUNT(*) FROM comms_users WHERE active=1")->fetchColumn();

    cmHeader('Comms Users', 'comms_menu.php');
    cmFlash();
    ?>
    <div class="card" style="display:flex;justify-content:flex-end;padding:14px 16px;margin-bottom:14px;">
      <a href="comms_menu.php?action=users&new=1" class="btn btn-success">+ Add User</a>
    </div>

    <div class="alert alert-info" style="font-size:.87rem;">
      These accounts grant access to the Communications Portal login (<code>comms_login.php</code>)
      only. They have no access to the estate access control system.
    </div>

    <div class="card">
      <div class="card-title">👤 Comms Users (<?= count($users) ?>)</div>
      <?php if (empty($users)): ?>
        <p class="no-data">No users yet.</p>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Username</th><th>Full Name</th><th>Email</th><th>Status</th><th>Actions</th></tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u):
              $isSelf       = ((int)$u['id'] === $self);
              $isLastActive = ($u['active'] && $count <= 1);
              $blockDelete  = $isSelf || $isLastActive;
            ?>
            <tr>
              <td style="font-weight:700;">
                <?= htmlspecialchars($u['username']) ?>
                <?php if ($isSelf): ?><span class="badge" style="background:#0D47A1;margin-left:4px;">YOU</span><?php endif; ?>
              </td>
              <td><?= htmlspecialchars($u['full_name'] ?? '—') ?></td>
              <td style="font-size:.83rem;"><?= htmlspecialchars($u['email'] ?? '—') ?></td>
              <td>
                <?= $u['active']
                  ? '<span class="badge badge-success">Active</span>'
                  : '<span class="badge badge-muted">Inactive</span>' ?>
              </td>
              <td>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <a href="comms_menu.php?action=users&edit=<?= $u['id'] ?>"
                     class="btn btn-primary btn-sm">Edit</a>

                  <?php if ($blockDelete): ?>
                    <button class="btn btn-danger btn-sm" disabled style="opacity:.35;cursor:not-allowed;"
                            title="<?= $isSelf ? 'Cannot delete your own account' : 'Cannot delete the last active user' ?>">
                      Delete
                    </button>
                  <?php else: ?>
                    <form method="POST" action="comms_menu.php?action=users" style="display:inline;">
                      <?= csrfField() ?>
                      <input type="hidden" name="form_action" value="delete">
                      <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                      <button type="submit" class="btn btn-danger btn-sm"
                              onclick="return confirm('Delete user <?= htmlspecialchars($u['username']) ?>?')">
                        Delete
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php
    cmFooter(); exit;
}

// Fallback
header('Location: comms_menu.php'); exit;
