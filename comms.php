<?php
// ============================================================
// gemB / MBGE — comms.php
// Communications module — entry point / menu
//
// Single entry point for all five channels:
//   Bulk messages   -> comms_bulk.php
//   Levy statements -> comms_levy.php
//   Surveys         -> comms_surveys.php
//   Voting          -> comms_voting.php
//   Document archive-> comms_archive.php
//
// Embedded mode: linked from admin.php's menu (admin session, $_SESSION['admin_id']).
// Standalone mode: comms_login.php sets comms_logged_in.
//
// Actions:
//   menu    — channel tiles + recent activity (default)
//   reports — unified send-log / reporting across all channels
// ============================================================
require_once __DIR__ . '/comms_core.php';
commsRequireAuth();

$action = $_GET['action'] ?? 'menu';

// ════════════════════════════════════════════════════════
// MENU
// ════════════════════════════════════════════════════════
if ($action === 'menu') {

    $recent = commsBroadcastList(null, 10);

    $channelLabels = [
        'bulk'    => ['📤', 'Bulk Message',   '#1565c0'],
        'levy'    => ['💰', 'Levy Statement', '#2e7d32'],
        'survey'  => ['📋', 'Survey Notify',  '#6a1b9a'],
        'vote'    => ['🗳️', 'Voting Tokens',  '#8e44ad'],
        'archive' => ['📄', 'Archive',        '#455a64'],
    ];

    pageHeader('Communications', 'admin');
    renderHeader('📣 Communications' . (commsIsEmbedded() ? '' : ' — ' . htmlspecialchars(commsCurrentUser())),
                  commsIsEmbedded() ? 'logout.php' : 'comms_login.php?action=logout');
    ?>
    <div class="container">
      <?= getFlash() ?>

      <?php if (commsIsEmbedded()): ?>
      <div class="btn-group" style="margin-bottom:16px;">
        <a href="admin.php?action=menu" class="btn btn-navy">← Back to Admin Menu</a>
      </div>
      <?php endif; ?>

      <div class="menu-grid">
        <a href="comms_bulk.php"    class="menu-btn"><span class="icon">📤</span>Bulk Messages</a>
        <a href="comms_levy.php"    class="menu-btn"><span class="icon">💰</span>Levy Statements</a>
        <a href="comms_surveys.php?action=list" class="menu-btn"><span class="icon">📋</span>Surveys</a>
        <a href="comms_voting.php?action=meetings_list" class="menu-btn"><span class="icon">🗳️</span>Voting</a>
        <a href="comms_archive.php" class="menu-btn"><span class="icon">📄</span>Document Archive</a>
        <a href="comms.php?action=reports" class="menu-btn"><span class="icon">📊</span>Reports</a>
        <a href="comms.php?action=users" class="menu-btn"><span class="icon">👤</span>Comms Users</a>
      </div>

      <div class="card" style="margin-top:20px;">
        <div class="card-title">Recent Activity</div>
        <?php if (empty($recent)): ?>
          <p style="color:#666;">No communications sent yet.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr><th>Date</th><th>Channel</th><th>Title</th><th>Sent</th><th>Failed</th></tr>
          <?php foreach ($recent as $b):
            [$icon, $label, $color] = $channelLabels[$b['channel']] ?? ['📣', ucfirst($b['channel']), '#666'];
          ?>
          <tr>
            <td style="white-space:nowrap;font-size:.85rem;"><?= date('d M Y H:i', strtotime($b['created_at'])) ?></td>
            <td><span class="badge" style="background:<?= $color ?>;color:#fff;"><?= $icon ?> <?= htmlspecialchars($label) ?></span></td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td style="color:#28a745;font-weight:700;"><?= $b['sent_to'] ?></td>
            <td style="color:<?= $b['failed']>0?'#dc3545':'#999' ?>;font-weight:700;"><?= $b['failed'] ?></td>
          </tr>
          <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
      </div>
    </div>
    <?php pageFooter(); exit;
}

// ════════════════════════════════════════════════════════
// REPORTS — unified send-log across all channels
// ════════════════════════════════════════════════════════
if ($action === 'reports') {

    $channel = $_GET['channel'] ?? '';
    $valid   = ['bulk','levy','survey','vote','archive'];
    $channel = in_array($channel, $valid, true) ? $channel : null;

    $broadcasts = commsBroadcastList($channel, 200);

    $channelLabels = [
        'bulk'    => ['📤', 'Bulk Message',   '#1565c0'],
        'levy'    => ['💰', 'Levy Statement', '#2e7d32'],
        'survey'  => ['📋', 'Survey Notify',  '#6a1b9a'],
        'vote'    => ['🗳️', 'Voting Tokens',  '#8e44ad'],
        'archive' => ['📄', 'Archive',        '#455a64'],
    ];

    $channelDest = [
        'bulk'    => 'comms_bulk.php?action=log&id=',
        'levy'    => 'comms_levy.php?action=log&id=',
        'survey'  => null, // survey notify logs link nowhere specific yet
        'vote'    => null, // vote token logs link nowhere specific yet
        'archive' => 'comms_archive.php?action=view&id=',
    ];

    pageHeader('Communications Reports', 'admin');
    renderHeader('📊 Reports', 'comms.php');
    ?>
    <div class="container">
      <?= getFlash() ?>

      <div class="card" style="padding:16px;margin-bottom:16px;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <a href="comms.php?action=reports" class="btn <?= $channel===null ? 'btn-primary' : 'btn-secondary' ?> btn-sm">All</a>
          <?php foreach ($channelLabels as $key => [$icon, $label, $color]): ?>
          <a href="comms.php?action=reports&channel=<?= $key ?>" class="btn <?= $channel===$key ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            <?= $icon ?> <?= htmlspecialchars($label) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-title">
          <?= count($broadcasts) ?> communication(s)
          <?php if ($channel): ?> — <?= htmlspecialchars($channelLabels[$channel][1] ?? $channel) ?><?php endif; ?>
        </div>
        <?php if (empty($broadcasts)): ?>
          <p style="color:#666;">No communications found.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr><th>Date</th><th>Channel</th><th>Title</th><th>Sent</th><th>Failed</th><th>Actions</th></tr>
          <?php foreach ($broadcasts as $b):
            [$icon, $label, $color] = $channelLabels[$b['channel']] ?? ['📣', ucfirst($b['channel']), '#666'];
            $dest = $channelDest[$b['channel']] ?? null;
          ?>
          <tr>
            <td style="white-space:nowrap;font-size:.85rem;"><?= date('d M Y H:i', strtotime($b['created_at'])) ?></td>
            <td><span class="badge" style="background:<?= $color ?>;color:#fff;"><?= $icon ?> <?= htmlspecialchars($label) ?></span></td>
            <td><?= htmlspecialchars($b['title']) ?></td>
            <td style="color:#28a745;font-weight:700;"><?= $b['sent_to'] ?></td>
            <td style="color:<?= $b['failed']>0?'#dc3545':'#999' ?>;font-weight:700;"><?= $b['failed'] ?></td>
            <td>
              <?php if ($dest): ?>
              <a href="<?= $dest . $b['id'] ?>" class="btn btn-secondary btn-sm">View Log</a>
              <?php else: ?>
              <span class="muted-note" style="font-size:.78rem;">—</span>
              <?php endif; ?>
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
// USERS — manage standalone comms_users accounts (CRUD)
//
// These accounts are only used for the standalone deployment
// (comms_login.php). Embedded admins already have full comms
// access via their admin session and don't need an entry here.
// Same lockout-safety pattern as admin.php's "Admins" screen:
// you cannot delete yourself, and you cannot delete the last
// remaining active account.
// ════════════════════════════════════════════════════════
if ($action === 'users') {

    /* ── POST handlers (add / update / delete) ── */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $act = $_POST['form_action'] ?? 'add';

        /* ── Add ── */
        if ($act === 'add') {
            $username = trim($_POST['username']  ?? '');
            $fullName = trim($_POST['full_name']  ?? '');
            $email    = trim($_POST['email']      ?? '');
            $password = $_POST['password']        ?? '';

            if ($username === '') {
                setFlash('error', 'Username is required.');
                header('Location: comms.php?action=users&new=1'); exit;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                setFlash('error', 'Please enter a valid email address.');
                header('Location: comms.php?action=users&new=1'); exit;
            }
            if (strlen($password) < 8) {
                setFlash('error', 'Password must be at least 8 characters.');
                header('Location: comms.php?action=users&new=1'); exit;
            }
            try {
                db()->prepare(
                    "INSERT INTO comms_users (username, password, full_name, email, active) VALUES (?,?,?,?,1)"
                )->execute([
                    $username,
                    password_hash($password, PASSWORD_BCRYPT),
                    $fullName ?: null,
                    $email ?: null,
                ]);
                setFlash('success', "Comms user {$username} added.");
                header('Location: comms.php?action=users'); exit;
            } catch (Exception $e) {
                setFlash('error', 'Username already exists.');
                header('Location: comms.php?action=users&new=1'); exit;
            }
        }

        /* ── Update ── */
        elseif ($act === 'update') {
            $uid      = (int)($_POST['uid'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $fullName = trim($_POST['full_name'] ?? '');
            $email    = trim($_POST['email']     ?? '');
            $password = $_POST['password']       ?? '';
            $active   = isset($_POST['active']) ? 1 : 0;

            if (!$uid || $username === '') {
                setFlash('error', 'Username is required.');
                header('Location: comms.php?action=users&edit=' . $uid); exit;
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                setFlash('error', 'Please enter a valid email address.');
                header('Location: comms.php?action=users&edit=' . $uid); exit;
            }

            // Lockout safety: can't deactivate yourself or the last active user
            $self  = (int)($_SESSION['comms_user_id'] ?? 0);
            $count = (int)db()->query("SELECT COUNT(*) FROM comms_users WHERE active=1")->fetchColumn();
            if (!$active && $uid === $self) {
                setFlash('error', 'You cannot deactivate the account you are logged in with.');
                header('Location: comms.php?action=users&edit=' . $uid); exit;
            }
            if (!$active && $count <= 1) {
                setFlash('error', 'Cannot deactivate the last remaining comms user.');
                header('Location: comms.php?action=users&edit=' . $uid); exit;
            }

            try {
                if ($password !== '') {
                    if (strlen($password) < 8) {
                        setFlash('error', 'Password must be at least 8 characters.');
                        header('Location: comms.php?action=users&edit=' . $uid); exit;
                    }
                    db()->prepare(
                        "UPDATE comms_users SET username=?, full_name=?, email=?, password=?, active=? WHERE id=?"
                    )->execute([$username, $fullName ?: null, $email ?: null, password_hash($password, PASSWORD_BCRYPT), $active, $uid]);
                } else {
                    db()->prepare(
                        "UPDATE comms_users SET username=?, full_name=?, email=?, active=? WHERE id=?"
                    )->execute([$username, $fullName ?: null, $email ?: null, $active, $uid]);
                }
                setFlash('success', 'Comms user updated.');
            } catch (Exception $e) {
                setFlash('error', 'Username already exists.');
            }
            header('Location: comms.php?action=users'); exit;
        }

        /* ── Delete (with lockout safety) ── */
        elseif ($act === 'delete') {
            $uid   = (int)($_POST['uid'] ?? 0);
            $self  = (int)($_SESSION['comms_user_id'] ?? 0);
            $count = (int)db()->query("SELECT COUNT(*) FROM comms_users WHERE active=1")->fetchColumn();

            if ($uid === $self) {
                setFlash('error', 'You cannot delete the account you are logged in with.');
            } elseif ($count <= 1) {
                setFlash('error', 'Cannot delete the last remaining comms user.');
            } else {
                db()->prepare("DELETE FROM comms_users WHERE id=?")->execute([$uid]);
                setFlash('success', 'Comms user removed.');
            }
            header('Location: comms.php?action=users'); exit;
        }

        header('Location: comms.php?action=users'); exit;
    }

    // ──────────────────────────────────────────────────────
    // ADD PAGE (?action=users&new=1)
    // ──────────────────────────────────────────────────────
    if (isset($_GET['new'])) {
        pageHeader('Add Comms User', 'admin');
        renderHeader('➕ Add Comms User', 'comms.php?action=users');
        ?>
        <div class="container" style="max-width:560px;">
          <div class="card">
            <?= getFlash() ?>
            <div class="alert alert-info" style="margin-bottom:16px;font-size:.88rem;">
              This account is for the <strong>standalone Communications login</strong>
              (<code>comms_login.php</code>). It has access to all communication
              channels (bulk messages, levy statements, surveys, voting, archive,
              reports) but no access to residents, security, or access control.
            </div>
            <form method="POST" action="comms.php?action=users">
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
                <label>Email <small style="color:#888;">(optional)</small></label>
                <input type="email" name="email" placeholder="name@example.com">
              </div>
              <div class="form-group">
                <label>Password * (min 8 characters)</label>
                <input type="password" name="password" required
                       autocomplete="new-password" minlength="8">
              </div>
              <button type="submit" class="btn btn-primary btn-block">
                Create Comms User
              </button>
            </form>
          </div>
        </div>
        <?php pageFooter(); exit;
    }

    // ──────────────────────────────────────────────────────
    // EDIT PAGE (?action=users&edit=ID)
    // ──────────────────────────────────────────────────────
    if (isset($_GET['edit'])) {
        $uid   = (int)$_GET['edit'];
        $eStmt = db()->prepare("SELECT id, username, full_name, email, active FROM comms_users WHERE id=? LIMIT 1");
        $eStmt->execute([$uid]);
        $cu = $eStmt->fetch();

        if (!$cu) {
            setFlash('error', 'Comms user not found.');
            header('Location: comms.php?action=users'); exit;
        }

        pageHeader('Edit Comms User', 'admin');
        renderHeader('✏️ Edit — ' . htmlspecialchars($cu['username']), 'comms.php?action=users');
        ?>
        <div class="container" style="max-width:560px;">
          <div class="card">
            <?= getFlash() ?>
            <form method="POST" action="comms.php?action=users">
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
                <input type="password" name="password"
                       autocomplete="new-password" minlength="8" placeholder="••••••••">
              </div>
              <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                  <input type="checkbox" name="active" value="1" <?= $cu['active'] ? 'checked' : '' ?>>
                  Active (can log in)
                </label>
              </div>
              <button type="submit" class="btn btn-primary btn-block">
                Save Changes
              </button>
            </form>
          </div>
        </div>
        <?php pageFooter(); exit;
    }

    // ──────────────────────────────────────────────────────
    // LIST PAGE (default)
    // ──────────────────────────────────────────────────────
    $users = db()->query("SELECT id, username, full_name, email, active FROM comms_users ORDER BY username")->fetchAll();
    $self  = (int)($_SESSION['comms_user_id'] ?? 0);
    $count = (int)db()->query("SELECT COUNT(*) FROM comms_users WHERE active=1")->fetchColumn();

    pageHeader('Comms Users', 'admin');
    renderHeader('👤 Comms Users (Standalone Login)', 'comms.php');
    ?>
    <div class="container">
      <?= getFlash() ?>

      <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
        These accounts can log in at <code>comms_login.php</code> for the
        standalone Communications portal. Embedded administrators already
        have full access to Communications via their normal admin login and
        do not need an account here.
      </div>

      <div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:flex-end;">
        <a href="comms.php?action=users&new=1" class="btn btn-success">+ Add Comms User</a>
      </div>

      <div class="card" style="margin-top:14px;">
        <?php if (empty($users)): ?>
          <p style="color:#666;">No comms users yet.</p>
        <?php else: ?>
        <div class="table-wrap"><table>
          <tr><th>Username</th><th>Full Name</th><th>Email</th><th>Status</th><th>Actions</th></tr>
          <?php foreach ($users as $u):
            $isSelf      = ((int)$u['id'] === $self);
            $isLastActive = ($u['active'] && $count <= 1);
            $blockDelete = $isSelf || $isLastActive;
            $blockReason = $isSelf
                ? 'You cannot delete the account you are logged in with'
                : 'Cannot delete the last remaining active comms user';
          ?>
          <tr>
            <td>
              <span style="font-weight:700;"><?= htmlspecialchars($u['username']) ?></span>
              <?php if ($isSelf): ?>
                <span class="badge badge-info" style="margin-left:4px;">YOU</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($u['full_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
            <td>
              <?= $u['active']
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-muted">Inactive</span>' ?>
            </td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <a href="comms.php?action=users&edit=<?= $u['id'] ?>" class="btn btn-primary btn-sm">Edit</a>
                <?php if ($blockDelete): ?>
                  <button class="btn btn-sm btn-danger" style="opacity:.4;cursor:not-allowed;"
                          title="<?= htmlspecialchars($blockReason) ?>" disabled>Delete</button>
                <?php else: ?>
                <form method="POST" action="comms.php?action=users" style="display:inline;">
                  <?= csrfField() ?>
                  <input type="hidden" name="form_action" value="delete">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-danger"
                          onclick="return confirm('Delete comms user <?= htmlspecialchars($u['username']) ?>?')">Delete</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </table></div>
        <?php endif; ?>
      </div>

      <div class="btn-group" style="margin-top:16px;">
        <a href="comms.php" class="btn btn-navy">← Back to Communications</a>
      </div>
    </div>
    <?php pageFooter(); exit;
}

header('Location: comms.php'); exit;
