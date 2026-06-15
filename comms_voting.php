<?php
// ============================================================
// comms_voting.php — Voting Channel (Communications module)
// Merges the former admin_meetings.php (Meeting / Voting Register)
// and admin_votes.php (Voting Engine) into one file.
//
// A "meeting" (e.g. AGM November 2026) is the top-level voting
// register. Each meeting contains one or more motions, numbered
// sequentially (Motion 1, 2, 3...) within that meeting.
//
// MEETING actions (namespace: meetings_*):
//   meetings_list   — view all meetings (default)
//   meetings_create — new meeting form
//   meetings_edit   — edit draft meeting
//   meetings_open   — draft -> open
//   meetings_close  — open -> closed
//   meetings_delete — delete draft meeting only (must have no motions)
//   voter_register  — upload levy roll CSV for a meeting
//   send_tokens     — generate + email voting tokens (uses comms_core)
//
// MOTION actions (namespace unchanged, scoped via ?meeting_id=):
//   list      — motions for a meeting
//   create    — new motion form
//   edit      — edit draft motion
//   open      — draft -> open (voting begins)
//   close     — open -> closed (computes and writes result)
//   delete    — delete draft motion only
//   proxies   — register / view proxy votes for a motion
//   capture   — admin captures manual or proxy vote per erf
//   results   — full result display + CSV download
//   csv       — download results CSV
//
// Security:
//   commsRequireAuth() — embedded admin OR standalone comms session
//   verifyCsrfToken() on every POST
//   PDO prepared statements throughout
//   htmlspecialchars() on all output
//   Status transitions validated server-side
//   One-vote-per-erf enforced at DB level (unique key)
// ============================================================

require_once __DIR__ . "/comms_core.php";
if (session_status() === PHP_SESSION_NONE) session_start();

commsRequireAuth();

$action    = $_GET['action'] ?? 'meetings_list';
$adminId   = commsCurrentUserId() ?? 0;

// NOTE on parameter mapping:
//  - MEETING actions (meetings_*, voter_register, send_tokens) use ?id=
//    as the meeting id.
//  - MOTION actions (list/create/edit/open/close/delete/proxies/capture/
//    results/csv) use ?meeting_id= for the meeting and ?id= for the motion.
// $meetingId is set per-namespace below so both blocks get the right value
// without renaming every reference in either original file.
$meetingActions = ['meetings_list','meetings_create','meetings_edit','meetings_open','meetings_close','meetings_delete','voter_register','send_tokens'];

if (in_array($action, $meetingActions, true)) {
    $meetingId = (int)($_GET['id'] ?? 0);
    $motionId  = 0;
} else {
    $meetingId = (int)($_GET['meeting_id'] ?? 0);
    $motionId  = (int)($_GET['id'] ?? 0);
}

// ── Helper: load a meeting or redirect ────────────────────
function loadMeeting(int $id): array {
    $stmt = db()->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) {
        setFlash('error', 'Meeting not found.');
        header('Location: comms_voting.php?action=meetings_list'); exit;
    }
    return $m;
}

// ============================================================
// LIST
// ============================================================
if ($action === 'meetings_list') {

    $stmt = db()->query(
        "SELECT mt.*,
                (SELECT COUNT(*) FROM motions mo WHERE mo.meeting_id = mt.id) AS motion_count,
                (SELECT COUNT(*) FROM motions mo WHERE mo.meeting_id = mt.id AND mo.status = 'open')   AS motions_open,
                (SELECT COUNT(*) FROM motions mo WHERE mo.meeting_id = mt.id AND mo.status = 'closed') AS motions_closed,
                (SELECT COUNT(*) FROM motions mo WHERE mo.meeting_id = mt.id AND mo.status = 'draft')  AS motions_draft
         FROM meetings mt
         ORDER BY mt.created_at DESC"
    );
    $meetings = $stmt->fetchAll();

    pageHeader('Voting Register', 'admin');
    renderHeader('🗳️ Voting Register — Meetings', 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <div class="card-toolbar">
          <h2 class="card-title">🗳️ Meetings</h2>
          <a href="comms_voting.php?action=meetings_create" class="btn btn-primary">＋ New Meeting</a>
        </div>

        <?= getFlash() ?>

        <?php if (empty($meetings)): ?>
          <p class="muted-note">No meetings yet. Click <strong>New Meeting</strong> to create one
             (e.g. "AGM November 2026").</p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Meeting</th>
                <th>Date</th>
                <th>Status</th>
                <th>Motions</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($meetings as $mt): ?>
              <?php
                $statusClass = match($mt['status']) {
                    'open'   => 'badge-green',
                    'closed' => 'badge-grey',
                    default  => 'badge-amber',
                };
              ?>
              <tr>
                <td><?= $mt['id'] ?></td>
                <td><strong><?= htmlspecialchars($mt['title']) ?></strong></td>
                <td><?= $mt['meeting_date'] ? date('d M Y', strtotime($mt['meeting_date'])) : '<span class="muted-note">—</span>' ?></td>
                <td><span class="badge <?= $statusClass ?>"><?= ucfirst($mt['status']) ?></span></td>
                <td>
                  <span style="font-size:.85rem;">
                    <?= (int)$mt['motion_count'] ?> total
                    <?php if ($mt['motions_draft']):  ?><br><span class="muted-note"><?= (int)$mt['motions_draft'] ?> draft</span><?php endif; ?>
                    <?php if ($mt['motions_open']):   ?><br><span style="color:#2e7d32;"><?= (int)$mt['motions_open'] ?> open</span><?php endif; ?>
                    <?php if ($mt['motions_closed']): ?><br><span class="muted-note"><?= (int)$mt['motions_closed'] ?> closed</span><?php endif; ?>
                  </span>
                </td>
                <td>
                  <div class="btn-group-sm">

                    <a href="comms_voting.php?action=list&meeting_id=<?= $mt['id'] ?>" class="btn btn-sm btn-blue">📋 Motions</a>

                    <?php if ($mt['status'] === 'draft'): ?>
                      <a href="comms_voting.php?action=meetings_edit&id=<?= $mt['id'] ?>" class="btn btn-sm btn-amber">⚙️ Edit</a>
                      <form method="POST" action="comms_voting.php?action=meetings_open&id=<?= $mt['id'] ?>" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-green"
                                onclick="return confirm('Open this meeting? You will then be able to open individual motions for voting.')">
                          ▶ Open
                        </button>
                      </form>
                      <?php if ((int)$mt['motion_count'] === 0): ?>
                      <form method="POST" action="comms_voting.php?action=meetings_delete&id=<?= $mt['id'] ?>" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-red"
                                onclick="return confirm('Delete this meeting permanently?')">
                          🗑 Delete
                        </button>
                      </form>
                      <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($mt['status'] === 'open' || $mt['status'] === 'closed'): ?>
                      <a href="comms_voting.php?action=voter_register&id=<?= $mt['id'] ?>" class="btn btn-sm btn-amber">📋 Voter Register</a>
                      <a href="comms_voting.php?action=send_tokens&id=<?= $mt['id'] ?>" class="btn btn-sm btn-green">✉️ Send Tokens</a>
                    <?php endif; ?>

                    <?php if ($mt['status'] === 'open'): ?>
                      <?php if ((int)$mt['motions_open'] === 0 && (int)$mt['motions_draft'] === 0): ?>
                      <form method="POST" action="comms_voting.php?action=meetings_close&id=<?= $mt['id'] ?>" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-red"
                                onclick="return confirm('Close this meeting? All motions are already closed.')">
                          ⏹ Close Meeting
                        </button>
                      </form>
                      <?php else: ?>
                        <span class="muted-note" style="font-size:.75rem;">Close all motions before closing the meeting</span>
                      <?php endif; ?>
                    <?php endif; ?>

                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <div class="btn-group" style="margin-top:20px;">
          <a href="comms.php" class="btn btn-navy">← Back to Communications</a>
        </div>
      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// CREATE
// ============================================================
if ($action === 'meetings_create') {

    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $title = trim($_POST['title']        ?? '');
        $date  = trim($_POST['meeting_date'] ?? '');

        if ($title === '') $errors[] = 'Meeting title is required.';

        if (empty($errors)) {
            db()->prepare(
                "INSERT INTO meetings (title, meeting_date, status, created_by)
                 VALUES (?,?,'draft',?)"
            )->execute([$title, $date ?: null, $adminId]);

            setFlash('success', 'Meeting created as draft. Add motions, then open it for voting.');
            header('Location: comms_voting.php?action=meetings_list'); exit;
        }
    }

    pageHeader('New Meeting', 'admin');
    renderHeader('🗳️ New Meeting', 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <h2 class="card-title">🗳️ Create Meeting / Voting Register</h2>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>

          <div class="form-group">
            <label>Meeting Title <span class="required">*</span></label>
            <input type="text" name="title" maxlength="255" required
                   placeholder="e.g. AGM November 2026"
                   value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label>Meeting Date <span class="muted-note">(optional)</span></label>
            <input type="date" name="meeting_date"
                   value="<?= htmlspecialchars($_POST['meeting_date'] ?? '') ?>">
          </div>

          <p class="muted-note">
            After creating the meeting, add agenda items requiring a vote as
            Motion 1, Motion 2, etc. from the Motions screen.
          </p>

          <div class="btn-group">
            <button type="submit" class="btn btn-primary">Create Meeting →</button>
            <a href="comms_voting.php?action=meetings_list" class="btn btn-navy">Cancel</a>
          </div>
        </form>
      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// EDIT — draft only
// ============================================================
if ($action === 'meetings_edit' && $meetingId > 0) {

    $meeting = loadMeeting($meetingId);
    if ($meeting['status'] !== 'draft') {
        setFlash('error', 'Only draft meetings can be edited.');
        header('Location: comms_voting.php?action=meetings_list'); exit;
    }

    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $title = trim($_POST['title']        ?? '');
        $date  = trim($_POST['meeting_date'] ?? '');

        if ($title === '') $errors[] = 'Meeting title is required.';

        if (empty($errors)) {
            db()->prepare(
                "UPDATE meetings SET title=?, meeting_date=? WHERE id=? AND status='draft'"
            )->execute([$title, $date ?: null, $meetingId]);

            setFlash('success', 'Meeting updated.');
            header('Location: comms_voting.php?action=meetings_list'); exit;
        }

        $meeting['title']        = $title;
        $meeting['meeting_date'] = $date;
    }

    pageHeader('Edit Meeting', 'admin');
    renderHeader('🗳️ Edit Meeting', 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <h2 class="card-title">⚙️ Edit Meeting</h2>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>

          <div class="form-group">
            <label>Meeting Title <span class="required">*</span></label>
            <input type="text" name="title" maxlength="255" required
                   value="<?= htmlspecialchars($meeting['title']) ?>">
          </div>

          <div class="form-group">
            <label>Meeting Date <span class="muted-note">(optional)</span></label>
            <input type="date" name="meeting_date"
                   value="<?= htmlspecialchars($meeting['meeting_date'] ?? '') ?>">
          </div>

          <div class="btn-group">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="comms_voting.php?action=meetings_list" class="btn btn-navy">← Back</a>
          </div>
        </form>
      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// OPEN — draft → open
// ============================================================
if ($action === 'meetings_open' && $meetingId > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $meeting = loadMeeting($meetingId);
        if ($meeting['status'] !== 'draft') {
            setFlash('error', 'Only draft meetings can be opened.');
        } else {
            db()->prepare("UPDATE meetings SET status='open', updated_at=NOW() WHERE id=? AND status='draft'")
               ->execute([$meetingId]);
            setFlash('success', 'Meeting opened. You can now open individual motions for voting from the Motions screen.');
        }
    }
    header('Location: comms_voting.php?action=meetings_list'); exit;
}

// ============================================================
// CLOSE — open → closed
// ============================================================
if ($action === 'meetings_close' && $meetingId > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $meeting = loadMeeting($meetingId);
        if ($meeting['status'] !== 'open') {
            setFlash('error', 'Only open meetings can be closed.');
        } else {
            // Guard: all motions must be closed (or there must be none)
            $stmt = db()->prepare(
                "SELECT COUNT(*) FROM motions WHERE meeting_id=? AND status IN ('draft','open')"
            );
            $stmt->execute([$meetingId]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('error', 'All motions must be closed before closing the meeting.');
            } else {
                db()->prepare("UPDATE meetings SET status='closed', updated_at=NOW() WHERE id=? AND status='open'")
                   ->execute([$meetingId]);
                setFlash('success', 'Meeting closed.');
            }
        }
    }
    header('Location: comms_voting.php?action=meetings_list'); exit;
}

// ============================================================
// DELETE — draft only, must have no motions
// ============================================================
if ($action === 'meetings_delete' && $meetingId > 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $meeting = loadMeeting($meetingId);
        if ($meeting['status'] !== 'draft') {
            setFlash('error', 'Only draft meetings can be deleted.');
        } else {
            $stmt = db()->prepare("SELECT COUNT(*) FROM motions WHERE meeting_id=?");
            $stmt->execute([$meetingId]);
            if ((int)$stmt->fetchColumn() > 0) {
                setFlash('error', 'Cannot delete a meeting that has motions. Delete the motions first.');
            } else {
                db()->prepare("DELETE FROM meetings WHERE id=? AND status='draft'")->execute([$meetingId]);
                setFlash('success', 'Meeting deleted.');
            }
        }
    }
    header('Location: comms_voting.php?action=meetings_list'); exit;
}

// ============================================================
// VOTER REGISTER — upload levy roll CSV (Erf, Owner Name, Email, Phone)
// Re-uploadable: replaces existing rows for this meeting.
// ============================================================
if ($action === 'voter_register' && $meetingId > 0) {

    $meeting = loadMeeting($meetingId);
    $errors  = [];
    $preview = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        if (empty($_FILES['levy_csv']['tmp_name']) || $_FILES['levy_csv']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please choose a CSV file to upload.';
        } else {
            $rows = [];
            $fh   = fopen($_FILES['levy_csv']['tmp_name'], 'r');
            if ($fh === false) {
                $errors[] = 'Could not read the uploaded file.';
            } else {
                $lineNum = 0;
                while (($line = fgetcsv($fh)) !== false) {
                    $lineNum++;
                    if (count($line) < 1) continue;

                    $line = array_map('trim', $line);

                    // Skip empty rows
                    if (count(array_filter($line, fn($v) => $v !== '')) === 0) continue;

                    // Skip header row (detect by non-numeric-ish first cell containing "erf")
                    if ($lineNum === 1 && stripos((string)($line[0] ?? ''), 'erf') !== false) {
                        continue;
                    }

                    if (count($line) < 3) continue; // need at least Erf + 2 more columns

                    $erf = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $line[0] ?? ''));
                    if ($erf === '') continue;

                    // Find which column (after the erf) contains a valid email —
                    // levy roll exports vary: either
                    //   Erf, Owner Name, Email, Phone   (email in col 3)
                    // or
                    //   Erf, Surname, FirstName, Email  (email in col 4, no phone)
                    $emailCol = null;
                    for ($c = 1; $c < count($line); $c++) {
                        if (filter_var($line[$c], FILTER_VALIDATE_EMAIL)) {
                            $emailCol = $c;
                            break;
                        }
                    }
                    if ($emailCol === null) continue; // no valid email anywhere — skip row

                    $email = $line[$emailCol];

                    // Everything before the email column (excluding erf) is the name —
                    // join multiple columns (e.g. Surname + FirstName) with a space.
                    $nameParts = array_slice($line, 1, $emailCol - 1);
                    $nameParts = array_filter($nameParts, fn($v) => $v !== '');
                    $name = trim(implode(' ', $nameParts));

                    // Anything after the email column is treated as phone (first non-empty)
                    $phone = '';
                    for ($c = $emailCol + 1; $c < count($line); $c++) {
                        if ($line[$c] !== '') { $phone = $line[$c]; break; }
                    }

                    if ($name === '') continue;

                    $rows[] = [$erf, $name, $email, $phone];
                }
                fclose($fh);
            }

            if (empty($rows) && empty($errors)) {
                $errors[] = 'No valid rows found. Expected columns: Erf, Owner Name, Email, Phone.';
            }

            if (empty($errors)) {
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    // Replace existing register for this meeting
                    $pdo->prepare("DELETE FROM vote_eligible_owners WHERE meeting_id = ?")
                        ->execute([$meetingId]);

                    $ins = $pdo->prepare(
                        "INSERT INTO vote_eligible_owners
                         (meeting_id, erf_number, owner_name, owner_email, owner_phone)
                         VALUES (?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE
                           owner_name = VALUES(owner_name),
                           owner_email = VALUES(owner_email),
                           owner_phone = VALUES(owner_phone)"
                    );
                    foreach ($rows as $r) {
                        $ins->execute([$meetingId, $r[0], $r[1], $r[2], $r[3]]);
                    }

                    // Clear any previously-generated tokens — register changed
                    $pdo->prepare("DELETE FROM vote_tokens WHERE meeting_id = ?")
                        ->execute([$meetingId]);

                    $pdo->commit();
                    setFlash('success', count($rows) . ' erf(s) loaded into the voter register. '
                        . 'Any previously generated tokens were cleared — click "Send Tokens" to generate new ones.');
                    header('Location: comms_voting.php?action=voter_register&id=' . $meetingId); exit;
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    $errors[] = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }

    // Current register
    $stmt = db()->prepare(
        "SELECT veo.*,
                vt.token, vt.sent_at, vt.used_at, vt.device_token
         FROM vote_eligible_owners veo
         LEFT JOIN vote_tokens vt
                ON vt.meeting_id = veo.meeting_id AND vt.erf_number = veo.erf_number
         WHERE veo.meeting_id = ?
         ORDER BY veo.erf_number"
    );
    $stmt->execute([$meetingId]);
    $register = $stmt->fetchAll();

    pageHeader('Voter Register', 'admin');
    renderHeader('📋 Voter Register — ' . htmlspecialchars($meeting['title']), 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <h2 class="card-title">📋 Voter Register — <?= htmlspecialchars($meeting['title']) ?></h2>

        <?= getFlash() ?>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
          Upload the levy roll as a CSV with columns: <strong>Erf, Owner Name, Email, Phone</strong>
          (a header row is optional and will be detected automatically).
          Re-uploading <strong>replaces</strong> the entire register for this meeting and
          clears any tokens already generated — use this to correct mistakes
          <em>before</em> clicking "Send Tokens".
        </div>

        <form method="POST" enctype="multipart/form-data" style="margin-bottom:24px;">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Levy Roll CSV</label>
            <input type="file" name="levy_csv" accept=".csv" required>
          </div>
          <div class="btn-group">
            <button type="submit" class="btn btn-primary">Upload / Replace Register</button>
            <a href="comms_voting.php?action=meetings_list" class="btn btn-navy">← Back to Meetings</a>
          </div>
        </form>

        <?php if (!empty($register)): ?>
          <h3 style="margin-bottom:8px;">
            Current Register — <?= count($register) ?> erf(s)
          </h3>
          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Erf</th>
                  <th>Owner Name</th>
                  <th>Email</th>
                  <th>Phone</th>
                  <th>Token Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($register as $r): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($r['erf_number']) ?></strong></td>
                  <td><?= htmlspecialchars($r['owner_name']) ?></td>
                  <td><?= htmlspecialchars($r['owner_email']) ?></td>
                  <td><?= htmlspecialchars($r['owner_phone'] ?? '') ?></td>
                  <td style="font-size:.82rem;">
                    <?php if (!$r['token']): ?>
                      <span class="muted-note">Not generated</span>
                    <?php elseif ($r['used_at']): ?>
                      <span class="badge badge-green">Used — device locked</span>
                    <?php elseif ($r['sent_at']): ?>
                      <span class="badge badge-amber">Sent, not yet used</span>
                    <?php else: ?>
                      <span class="muted-note">Generated, not sent</span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="muted-note">No voter register uploaded yet for this meeting.</p>
        <?php endif; ?>

      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// SEND TOKENS — generate (if needed) and email tokens to
// owner_email from the levy roll register
// ============================================================
if ($action === 'send_tokens' && $meetingId > 0) {

    $meeting = loadMeeting($meetingId);

    $stmt = db()->prepare("SELECT * FROM vote_eligible_owners WHERE meeting_id = ? ORDER BY erf_number");
    $stmt->execute([$meetingId]);
    $owners = $stmt->fetchAll();

    if (empty($owners)) {
        setFlash('error', 'No voter register found for this meeting. Upload the levy roll CSV first.');
        header('Location: comms_voting.php?action=voter_register&id=' . $meetingId); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $mode = $_POST['send_mode'] ?? 'unsent'; // 'unsent' or 'all'

        $sent  = 0;
        $skip  = 0;
        $sendErrors = [];
        $voteLoginUrl = (defined('SITE_URL') ? SITE_URL : '') . '/vote_login.php';

        $broadcastId = commsBroadcastCreate('vote', 'vote_tokens', $meeting['title'], null, null, null);

        foreach ($owners as $o) {
            $erf = $o['erf_number'];

            // Find or create token
            $tstmt = db()->prepare("SELECT * FROM vote_tokens WHERE meeting_id=? AND erf_number=?");
            $tstmt->execute([$meetingId, $erf]);
            $vt = $tstmt->fetch();

            if ($vt && $vt['sent_at'] && $mode === 'unsent') {
                $skip++;
                continue;
            }

            if (!$vt) {
                // Generate a unique 6-digit token for this meeting
                do {
                    $token = (string)random_int(100000, 999999);
                    $chk = db()->prepare("SELECT id FROM vote_tokens WHERE meeting_id=? AND token=?");
                    $chk->execute([$meetingId, $token]);
                } while ($chk->fetch());

                db()->prepare(
                    "INSERT INTO vote_tokens (meeting_id, erf_number, token) VALUES (?,?,?)"
                )->execute([$meetingId, $erf, $token]);
            } else {
                $token = $vt['token'];
                // Don't regenerate or resend if already used (device-locked)
                if ($vt['used_at']) {
                    $skip++;
                    continue;
                }
            }

            // ── Email the registered owner (levy roll email — NOT resident portal email) ──
            $subject = 'Your Voting Token - ' . $meeting['title'];
            $message = "You are eligible to vote in: {$meeting['title']}\n\n"
                     . "Erf Number: {$erf}\n"
                     . "Voting Token: {$token}\n\n"
                     . "Enter your erf number and the 6-digit token above on the voting login page. "
                     . "This token can only be used once, from one device.";

            $html = commsBuildNotifyEmail($o['owner_name'], 'Your Voting Token', $message, $voteLoginUrl, 'Go to Voting Login');

            $ok = commsSendAndLog($broadcastId, 'vote', $o['owner_email'], $o['owner_name'], $subject, $html);

            if ($ok) {
                db()->prepare("UPDATE vote_tokens SET sent_at=NOW() WHERE meeting_id=? AND erf_number=?")
                    ->execute([$meetingId, $erf]);
                $sent++;
            } else {
                $sendErrors[] = $erf . ': send failed (mail() returned false — check server mail config/logs)';
                $skip++;
            }
        }

        commsBroadcastUpdateCounts($broadcastId, $sent, $skip);

        $msg = "Tokens sent: {$sent}. Skipped (already sent/used): {$skip}.";
        if (!empty($sendErrors)) {
            $msg .= ' [DEBUG errors: ' . implode(' | ', array_slice($sendErrors, 0, 5)) . ']';
        }
        setFlash('success', $msg);
        header('Location: comms_voting.php?action=voter_register&id=' . $meetingId); exit;
    }

    // Counts for the confirmation screen
    $tstmt = db()->prepare(
        "SELECT
           SUM(CASE WHEN token IS NULL THEN 1 ELSE 0 END) AS not_generated,
           SUM(CASE WHEN token IS NOT NULL AND sent_at IS NULL THEN 1 ELSE 0 END) AS not_sent,
           SUM(CASE WHEN sent_at IS NOT NULL AND used_at IS NULL THEN 1 ELSE 0 END) AS sent_unused,
           SUM(CASE WHEN used_at IS NOT NULL THEN 1 ELSE 0 END) AS used
         FROM vote_eligible_owners veo
         LEFT JOIN vote_tokens vt ON vt.meeting_id = veo.meeting_id AND vt.erf_number = veo.erf_number
         WHERE veo.meeting_id = ?"
    );
    $tstmt->execute([$meetingId]);
    $counts = $tstmt->fetch();

    pageHeader('Send Tokens', 'admin');
    renderHeader('✉️ Send Tokens — ' . htmlspecialchars($meeting['title']), 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <h2 class="card-title">✉️ Send Voting Tokens — <?= htmlspecialchars($meeting['title']) ?></h2>

        <?= getFlash() ?>

        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
          Tokens are emailed to the <strong>registered owner email from the levy roll</strong>
          (not the resident portal email). Each erf gets one 6-digit token covering
          <strong>all motions</strong> in this meeting. The token is permanently
          locked to the first device used to enter it.
        </div>

        <table class="data-table" style="max-width:480px;margin-bottom:20px;">
          <tr><td>Not yet generated</td><td><strong><?= (int)($counts['not_generated'] ?? 0) ?></strong></td></tr>
          <tr><td>Generated, not sent</td><td><strong><?= (int)($counts['not_sent'] ?? 0) ?></strong></td></tr>
          <tr><td>Sent, not yet used</td><td><strong><?= (int)($counts['sent_unused'] ?? 0) ?></strong></td></tr>
          <tr><td>Used (device-locked)</td><td><strong><?= (int)($counts['used'] ?? 0) ?></strong></td></tr>
          <tr><td><strong>Total in register</strong></td><td><strong><?= count($owners) ?></strong></td></tr>
        </table>

        <form method="POST" onsubmit="return confirm('Send voting tokens by email now?')">
          <?= csrfField() ?>
          <div class="form-group">
            <label>
              <input type="radio" name="send_mode" value="unsent" checked>
              Send to erven that have <strong>not yet been sent</strong> a token (recommended)
            </label><br>
            <label>
              <input type="radio" name="send_mode" value="all">
              Resend to <strong>all</strong> erven that haven't used their token yet
              (already-used/device-locked tokens are never resent)
            </label>
          </div>
          <div class="btn-group">
            <button type="submit" class="btn btn-primary">Send Tokens Now</button>
            <a href="comms_voting.php?action=voter_register&id=<?= $meetingId ?>" class="btn btn-navy">← Back to Register</a>
          </div>
        </form>
      </div>
    </main>

    <?php pageFooter(); exit;
}

// Resolution type labels and thresholds
const RESOLUTION_TYPES = [
    'ordinary' => ['label' => 'Ordinary Resolution', 'threshold' => 50.01],
    'material' => ['label' => 'Material Resolution',  'threshold' => 60.00],
    'moi'      => ['label' => 'MOI / Condonation',    'threshold' => 75.00],
];

// ── Helper: load a motion or redirect ─────────────────────
function loadMotion(int $id): array {
    $stmt = db()->prepare("SELECT * FROM motions WHERE id = ?");
    $stmt->execute([$id]);
    $m = $stmt->fetch();
    if (!$m) {
        setFlash('error', 'Motion not found.');
        header('Location: comms_voting.php?action=meetings_list'); exit;
    }
    return $m;
}

// ── Helper: compute live vote counts for a motion ─────────
function voteCounts(int $motionId): array {
    $stmt = db()->prepare(
        "SELECT
           SUM(vc.capture_method = 'online')  AS online,
           SUM(vc.capture_method = 'manual')  AS manual,
           SUM(vc.capture_method = 'proxy')   AS proxy,
           SUM(vt.vote_option = 'for')        AS cnt_for,
           SUM(vt.vote_option = 'against')    AS cnt_against,
           SUM(vt.vote_option = 'abstain')    AS cnt_abstain,
           SUM(vt.vote_option = 'noshow')     AS cnt_noshow,
           SUM(vt.is_blank = 1)               AS cnt_blank,
           COUNT(vc.id)                        AS total_voted
         FROM vote_cast vc
         JOIN vote_tally vt ON vt.cast_id = vc.id
         WHERE vc.motion_id = ?"
    );
    $stmt->execute([$motionId]);
    $r = $stmt->fetch();

    $online   = (int)$r['online'];
    $manual   = (int)$r['manual'];
    $proxy    = (int)$r['proxy'];
    $for      = (int)$r['cnt_for'];
    $against  = (int)$r['cnt_against'];
    $abstain  = (int)$r['cnt_abstain'];
    $noshow   = (int)$r['cnt_noshow'];
    $blank    = (int)$r['cnt_blank'];
    $total    = (int)$r['total_voted'];

    $physical       = $online + $manual;
    $forAgainst     = $for + $against;
    $resultPct      = $forAgainst > 0 ? round($for / $forAgainst * 100, 2) : 0;

    return compact('online','manual','proxy','for','against',
                   'abstain','noshow','blank','total',
                   'physical','forAgainst','resultPct');
}

// ── Helper: check quorum ───────────────────────────────────
function checkQuorum(array $counts, array $motion): array {
    $physicalOk = $counts['physical'] >= (int)$motion['quorum_physical'];
    $totalOk    = $counts['total']    >= (int)$motion['quorum_total'];
    $met        = $physicalOk && $totalOk;
    return [
        'met'        => $met,
        'physicalOk' => $physicalOk,
        'totalOk'    => $totalOk,
    ];
}

// ============================================================
// LIST — motions for a single meeting
// ============================================================
if ($action === 'list') {

    if ($meetingId <= 0) {
        header('Location: comms_voting.php?action=meetings_list'); exit;
    }

    $meeting = loadMeeting($meetingId);

    $stmt = db()->prepare(
        "SELECT m.*,
                (SELECT COUNT(*) FROM vote_cast vc WHERE vc.motion_id = m.id) AS votes_cast,
                (SELECT COUNT(*) FROM vote_proxy vp WHERE vp.motion_id = m.id) AS proxies
         FROM motions m
         WHERE m.meeting_id = ?
         ORDER BY m.motion_number ASC"
    );
    $stmt->execute([$meetingId]);
    $motions = $stmt->fetchAll();

    $meetingStatusClass = match($meeting['status']) {
        'open'   => 'badge-green',
        'closed' => 'badge-grey',
        default  => 'badge-amber',
    };

    pageHeader('Voting — Motions', 'admin');
    renderHeader('🗳️ Voting', 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <div class="card-toolbar">
          <div>
            <h2 class="card-title">🗳️ <?= htmlspecialchars($meeting['title']) ?></h2>
            <p style="margin-top:4px;">
              <span class="badge <?= $meetingStatusClass ?>"><?= ucfirst($meeting['status']) ?></span>
              <?php if ($meeting['meeting_date']): ?>
                &nbsp;<span class="muted-note"><?= date('d M Y', strtotime($meeting['meeting_date'])) ?></span>
              <?php endif; ?>
            </p>
          </div>
          <?php if ($meeting['status'] !== 'closed'): ?>
            <a href="comms_voting.php?action=create&meeting_id=<?= $meetingId ?>" class="btn btn-primary">＋ New Motion</a>
          <?php endif; ?>
        </div>

        <?= getFlash() ?>

        <?php if (empty($motions)): ?>
          <p class="muted-note">No motions yet for this meeting.
             <?php if ($meeting['status'] !== 'closed'): ?>Click <strong>New Motion</strong> to add agenda items requiring a vote.<?php endif; ?>
          </p>
        <?php else: ?>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>#</th>
                <th>Motion</th>
                <th>Status</th>
                <th>Proxies</th>
                <th>Votes Cast</th>
                <th>Opens</th>
                <th>Closes</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($motions as $m): ?>
              <?php
                $statusClass = match($m['status']) {
                    'open'   => 'badge-green',
                    'closed' => 'badge-grey',
                    default  => 'badge-amber',
                };
              ?>
              <tr>
                <td><?= (int)$m['motion_number'] ?></td>
                <td><strong><?= htmlspecialchars($m['title']) ?></strong></td>
                <td><span class="badge <?= $statusClass ?>"><?= ucfirst($m['status']) ?></span></td>
                <td class="text-center"><?= (int)$m['proxies'] ?></td>
                <td class="text-center"><?= (int)$m['votes_cast'] ?></td>
                <td><?= $m['opens_at']  ? date('d M Y H:i', strtotime($m['opens_at']))  : '<span class="muted-note">Immediate</span>' ?></td>
                <td><?= $m['closes_at'] ? date('d M Y H:i', strtotime($m['closes_at'])) : '<span class="muted-note">No auto-close</span>' ?></td>
                <td>
                  <div class="btn-group-sm">

                    <?php if ($m['status'] === 'draft'): ?>
                      <a href="comms_voting.php?action=edit&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" class="btn btn-sm btn-amber">⚙️ Edit</a>
                      <?php if ($meeting['status'] !== 'closed'): ?>
                      <form method="POST" action="comms_voting.php?action=open&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-green"
                                onclick="return confirm('Open this motion for voting? Residents will be able to cast votes immediately.')">
                          ▶ Open
                        </button>
                      </form>
                      <?php endif; ?>
                      <form method="POST" action="comms_voting.php?action=delete&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-red"
                                onclick="return confirm('Delete this motion permanently?')">
                          🗑 Delete
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if ($m['status'] === 'open'): ?>
                      <a href="comms_voting.php?action=proxies&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" class="btn btn-sm btn-navy">📋 Proxies</a>
                      <a href="comms_voting.php?action=capture&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" class="btn btn-sm btn-blue">✏️ Capture Vote</a>
                      <a href="comms_voting.php?action=results&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" class="btn btn-sm btn-blue">📊 Live</a>
                      <form method="POST" action="comms_voting.php?action=close&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-red"
                                onclick="return confirm('Close voting and compute the final result? This cannot be undone.')">
                          ⏹ Close
                        </button>
                      </form>
                    <?php endif; ?>

                    <?php if ($m['status'] === 'closed'): ?>
                      <a href="comms_voting.php?action=results&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" class="btn btn-sm btn-blue">📊 Results</a>
                      <a href="comms_voting.php?action=csv&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" class="btn btn-sm btn-green">⬇ CSV</a>
                    <?php endif; ?>

                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <div class="btn-group" style="margin-top:20px;">
          <a href="comms_voting.php?action=meetings_list" class="btn btn-navy">← Back to Meetings</a>
        </div>
      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// CREATE — new motion within a meeting
// ============================================================
if ($action === 'create') {

    if ($meetingId <= 0) {
        header('Location: comms_voting.php?action=meetings_list'); exit;
    }

    $meeting = loadMeeting($meetingId);
    if ($meeting['status'] === 'closed') {
        setFlash('error', 'This meeting is closed. Motions cannot be added.');
        header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
    }

    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $title   = trim($_POST['title']           ?? '');
        $desc    = trim($_POST['description']      ?? '');
        $type    = trim($_POST['resolution_type']  ?? '');
        $opens   = trim($_POST['opens_at']         ?? '');
        $closes  = trim($_POST['closes_at']        ?? '');
        $erven   = (int)($_POST['total_erven']     ?? 394);
        $qPhys   = (int)($_POST['quorum_physical'] ?? 50);
        $qTotal  = (int)($_POST['quorum_total']    ?? 80);

        if ($title === '')                         $errors[] = 'Motion title is required.';
        if (!array_key_exists($type, RESOLUTION_TYPES)) $errors[] = 'Select a valid resolution type.';
        if ($qPhys < 1)                            $errors[] = 'Physical quorum must be at least 1.';
        if ($qTotal < $qPhys)                      $errors[] = 'Total quorum must be ≥ physical quorum.';
        if ($closes !== '' && $opens !== '' && $closes <= $opens) $errors[] = 'Close time must be after open time.';

        if (empty($errors)) {
            // Next sequential motion number within this meeting
            $numStmt = db()->prepare("SELECT COALESCE(MAX(motion_number), 0) + 1 FROM motions WHERE meeting_id=?");
            $numStmt->execute([$meetingId]);
            $nextNumber = (int)$numStmt->fetchColumn();

            db()->prepare(
                "INSERT INTO motions
                 (meeting_id, motion_number, title, description, resolution_type, status, created_by,
                  opens_at, closes_at, total_erven, quorum_physical, quorum_total)
                 VALUES (?,?,?,?,?,'draft',?,?,?,?,?,?)"
            )->execute([
                $meetingId, $nextNumber,
                $title, $desc ?: null, $type, $adminId,
                $opens ?: null, $closes ?: null,
                $erven, $qPhys, $qTotal,
            ]);
            setFlash('success', "Motion {$nextNumber} created as draft. Review and open when ready.");
            header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
        }
    }

    // Preview of the motion number this will become
    $numStmt = db()->prepare("SELECT COALESCE(MAX(motion_number), 0) + 1 FROM motions WHERE meeting_id=?");
    $numStmt->execute([$meetingId]);
    $previewNumber = (int)$numStmt->fetchColumn();

    pageHeader('New Motion', 'admin');
    renderHeader('🗳️ New Motion', 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <h2 class="card-title">🗳️ Create Motion <span class="muted-note">— <?= htmlspecialchars($meeting['title']) ?>, Motion <?= $previewNumber ?></span></h2>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>

          <div class="form-group">
            <label>Motion Title <span class="required">*</span></label>
            <input type="text" name="title" maxlength="255" required
                   value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label>Description / Background <span class="muted-note">(optional)</span></label>
            <textarea name="description" rows="4" maxlength="2000"
                      placeholder="Provide context for voters..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label>Resolution Type <span class="required">*</span></label>
            <select name="resolution_type" required>
              <option value="">— Select —</option>
              <?php foreach (RESOLUTION_TYPES as $val => $rt): ?>
                <option value="<?= $val ?>" <?= ($_POST['resolution_type'] ?? '') === $val ? 'selected' : '' ?>>
                  <?= $rt['label'] ?> (<?= $rt['threshold'] ?>% required)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Opens At <span class="muted-note">(blank = open immediately when activated)</span></label>
              <input type="datetime-local" name="opens_at" value="<?= htmlspecialchars($_POST['opens_at'] ?? '') ?>">
            </div>
            <div class="form-group">
              <label>Closes At <span class="muted-note">(blank = admin closes manually)</span></label>
              <input type="datetime-local" name="closes_at" value="<?= htmlspecialchars($_POST['closes_at'] ?? '') ?>">
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Total Eligible Erven</label>
              <input type="number" name="total_erven" min="1" max="9999"
                     value="<?= (int)($_POST['total_erven'] ?? 394) ?>">
              <small class="muted-note">Default 394 — change only if membership has changed.</small>
            </div>
          </div>

          <div style="background:#f8f9fa;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
            <strong style="font-size:.9rem;">Quorum Settings</strong>
            <p class="muted-note" style="margin:4px 0 10px;">
              Both conditions must be met: physical present ≥ minimum AND total (physical+proxy) ≥ minimum.
            </p>
            <div class="form-row">
              <div class="form-group">
                <label>Minimum Physical Present (online + manual)</label>
                <input type="number" name="quorum_physical" min="1" max="394"
                       value="<?= (int)($_POST['quorum_physical'] ?? 50) ?>">
              </div>
              <div class="form-group">
                <label>Minimum Total (physical + proxy)</label>
                <input type="number" name="quorum_total" min="1" max="394"
                       value="<?= (int)($_POST['quorum_total'] ?? 80) ?>">
              </div>
            </div>
          </div>

          <div class="btn-group">
            <button type="submit" class="btn btn-primary">Create Motion →</button>
            <a href="comms_voting.php?action=list&meeting_id=<?= $meetingId ?>" class="btn btn-navy">Cancel</a>
          </div>
        </form>
      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// EDIT — draft only
// ============================================================
if ($action === 'edit' && $motionId > 0) {

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($motion['status'] !== 'draft') {
        setFlash('error', 'Only draft motions can be edited.');
        header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
    }

    $errors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $title   = trim($_POST['title']           ?? '');
        $desc    = trim($_POST['description']      ?? '');
        $type    = trim($_POST['resolution_type']  ?? '');
        $opens   = trim($_POST['opens_at']         ?? '');
        $closes  = trim($_POST['closes_at']        ?? '');
        $erven   = (int)($_POST['total_erven']     ?? 394);
        $qPhys   = (int)($_POST['quorum_physical'] ?? 50);
        $qTotal  = (int)($_POST['quorum_total']    ?? 80);

        if ($title === '')                              $errors[] = 'Motion title is required.';
        if (!array_key_exists($type, RESOLUTION_TYPES)) $errors[] = 'Select a valid resolution type.';
        if ($qPhys < 1)                                 $errors[] = 'Physical quorum must be at least 1.';
        if ($qTotal < $qPhys)                           $errors[] = 'Total quorum must be ≥ physical quorum.';

        if (empty($errors)) {
            db()->prepare(
                "UPDATE motions SET title=?, description=?, resolution_type=?,
                 opens_at=?, closes_at=?, total_erven=?, quorum_physical=?, quorum_total=?
                 WHERE id=? AND status='draft'"
            )->execute([
                $title, $desc ?: null, $type,
                $opens ?: null, $closes ?: null,
                $erven, $qPhys, $qTotal, $motionId,
            ]);
            setFlash('success', 'Motion updated.');
            header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
        }

        $motion['title']           = $title;
        $motion['description']     = $desc;
        $motion['resolution_type'] = $type;
        $motion['opens_at']        = $opens;
        $motion['closes_at']       = $closes;
        $motion['total_erven']     = $erven;
        $motion['quorum_physical'] = $qPhys;
        $motion['quorum_total']    = $qTotal;
    }

    $opensVal  = $motion['opens_at']  ? date('Y-m-d\TH:i', strtotime($motion['opens_at']))  : '';
    $closesVal = $motion['closes_at'] ? date('Y-m-d\TH:i', strtotime($motion['closes_at'])) : '';

    pageHeader('Edit Motion', 'admin');
    renderHeader('🗳️ Edit Motion', 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <h2 class="card-title">⚙️ Edit Motion <span class="muted-note">— Motion <?= (int)$motion['motion_number'] ?></span></h2>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Motion Title <span class="required">*</span></label>
            <input type="text" name="title" maxlength="255" required value="<?= htmlspecialchars($motion['title']) ?>">
          </div>
          <div class="form-group">
            <label>Description / Background</label>
            <textarea name="description" rows="4" maxlength="2000"><?= htmlspecialchars($motion['description'] ?? '') ?></textarea>
          </div>
          <div class="form-group">
            <label>Resolution Type <span class="required">*</span></label>
            <select name="resolution_type" required>
              <?php foreach (RESOLUTION_TYPES as $val => $rt): ?>
                <option value="<?= $val ?>" <?= $motion['resolution_type'] === $val ? 'selected' : '' ?>>
                  <?= $rt['label'] ?> (<?= $rt['threshold'] ?>% required)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Opens At</label>
              <input type="datetime-local" name="opens_at" value="<?= htmlspecialchars($opensVal) ?>">
            </div>
            <div class="form-group">
              <label>Closes At</label>
              <input type="datetime-local" name="closes_at" value="<?= htmlspecialchars($closesVal) ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Total Eligible Erven</label>
              <input type="number" name="total_erven" min="1" max="9999" value="<?= (int)$motion['total_erven'] ?>">
            </div>
          </div>
          <div style="background:#f8f9fa;border-radius:8px;padding:14px 16px;margin-bottom:16px;">
            <strong style="font-size:.9rem;">Quorum Settings</strong>
            <div class="form-row" style="margin-top:10px;">
              <div class="form-group">
                <label>Minimum Physical Present</label>
                <input type="number" name="quorum_physical" min="1" max="394" value="<?= (int)$motion['quorum_physical'] ?>">
              </div>
              <div class="form-group">
                <label>Minimum Total (physical + proxy)</label>
                <input type="number" name="quorum_total" min="1" max="394" value="<?= (int)$motion['quorum_total'] ?>">
              </div>
            </div>
          </div>
          <div class="btn-group">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="comms_voting.php?action=list&meeting_id=<?= $meetingId ?>" class="btn btn-navy">← Back</a>
          </div>
        </form>
      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// OPEN — draft → open
// ============================================================
if ($action === 'open' && $motionId > 0) {
    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        if ($motion['status'] !== 'draft') {
            setFlash('error', 'Only draft motions can be opened.');
        } else {
            db()->prepare("UPDATE motions SET status='open', updated_at=NOW() WHERE id=? AND status='draft'")
               ->execute([$motionId]);
            setFlash('success', 'Motion is now open for voting.');
        }
    }
    header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
}

// ============================================================
// DELETE — draft only
// ============================================================
if ($action === 'delete' && $motionId > 0) {
    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        if ($motion['status'] !== 'draft') {
            setFlash('error', 'Only draft motions can be deleted.');
        } else {
            db()->prepare("DELETE FROM motions WHERE id=? AND status='draft'")->execute([$motionId]);
            setFlash('success', 'Motion deleted.');
        }
    }
    header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
}

// ============================================================
// CLOSE — open → closed + compute result
// ============================================================
if ($action === 'close' && $motionId > 0) {
    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        if ($motion['status'] !== 'open') {
            setFlash('error', 'Only open motions can be closed.');
            header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
        }

        $counts   = voteCounts($motionId);
        $quorum   = checkQuorum($counts, $motion);
        $rt       = RESOLUTION_TYPES[$motion['resolution_type']];
        $threshold = $rt['threshold'];
        $passed   = $quorum['met'] && $counts['resultPct'] >= $threshold;

        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Write result snapshot
            $pdo->prepare(
                "INSERT INTO vote_result
                 (motion_id, total_erven, count_online, count_manual, count_proxy,
                  count_for, count_against, count_abstain, count_noshow, count_blank,
                  physical_present, total_voted, quorum_met, threshold_pct, result_pct,
                  passed, closed_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $motionId,
                $motion['total_erven'],
                $counts['online'],
                $counts['manual'],
                $counts['proxy'],
                $counts['for'],
                $counts['against'],
                $counts['abstain'],
                $counts['noshow'],
                $counts['blank'],
                $counts['physical'],
                $counts['total'],
                $quorum['met'] ? 1 : 0,
                $threshold,
                $counts['resultPct'],
                $passed ? 1 : 0,
                $adminId,
            ]);

            // Close the motion
            $pdo->prepare("UPDATE motions SET status='closed', updated_at=NOW() WHERE id=?")
               ->execute([$motionId]);

            $pdo->commit();
            $outcome = $passed ? 'PASSED ✅' : 'FAILED ❌';
            setFlash('success', "Voting closed. Motion {$outcome} — see Results for full detail.");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('error', 'Error computing result. Please try again.');
        }
    }
    header("Location: comms_voting.php?action=results&id={$motionId}&meeting_id={$meetingId}"); exit;
}

// ============================================================
// PROXIES — register and view proxy votes
// ============================================================
if ($action === 'proxies' && $motionId > 0) {

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $erf     = strtoupper(trim($_POST['erf_number']       ?? ''));
        $holder  = trim($_POST['proxy_holder']                ?? '');
        $holderErf = strtoupper(trim($_POST['proxy_holder_erf'] ?? ''));
        $formRef = trim($_POST['form_reference']              ?? '');
        $notes   = trim($_POST['notes']                       ?? '');

        $perrors = [];
        if ($erf === '')    $perrors[] = 'Erf number is required.';
        if ($holder === '') $perrors[] = 'Proxy holder name is required.';

        // Check erf has not already voted
        if (empty($perrors)) {
            $already = db()->prepare("SELECT id FROM vote_cast WHERE motion_id=? AND erf_number=?");
            $already->execute([$motionId, $erf]);
            if ($already->fetch()) $perrors[] = "Erf {$erf} has already cast a vote — proxy cannot be registered.";
        }

        if (empty($perrors)) {
            try {
                db()->prepare(
                    "INSERT INTO vote_proxy
                     (motion_id, erf_number, proxy_holder, proxy_holder_erf, form_reference, captured_by, notes)
                     VALUES (?,?,?,?,?,?,?)"
                )->execute([
                    $motionId, $erf, $holder,
                    $holderErf ?: null, $formRef ?: null,
                    $adminId, $notes ?: null,
                ]);
                setFlash('success', "Proxy registered for Erf {$erf}.");
            } catch (Exception $e) {
                setFlash('error', "Proxy already registered for Erf {$erf}.");
            }
            header("Location: comms_voting.php?action=proxies&id={$motionId}&meeting_id={$meetingId}"); exit;
        }
        $_SESSION['perrors'] = $perrors;
        $_SESSION['ppost']   = $_POST;
        header("Location: comms_voting.php?action=proxies&id={$motionId}&meeting_id={$meetingId}"); exit;
    }

    $proxies = db()->prepare(
        "SELECT * FROM vote_proxy WHERE motion_id=? ORDER BY captured_at DESC"
    );
    $proxies->execute([$motionId]);
    $proxies = $proxies->fetchAll();

    $perrors = $_SESSION['perrors'] ?? [];
    $ppost   = $_SESSION['ppost']   ?? [];
    unset($_SESSION['perrors'], $_SESSION['ppost']);

    pageHeader('Proxy Votes', 'admin');
    renderHeader('📋 Proxy Votes', 'comms.php');
    ?>

    <main class="pc-main">

      <div class="card card-wide" style="margin-bottom:16px;">
        <div class="card-toolbar">
          <div>
            <h2 class="card-title">📋 Proxy Register — Motion <?= (int)$motion['motion_number'] ?>: <?= htmlspecialchars($motion['title']) ?></h2>
            <span class="badge badge-green">Open</span>
            &nbsp; <span class="muted-note"><?= count($proxies) ?> proxy vote<?= count($proxies) != 1 ? 's' : '' ?> registered</span>
          </div>
        </div>
      </div>

      <!-- Existing proxies -->
      <?php if (!empty($proxies)): ?>
      <div class="card card-wide" style="margin-bottom:16px;">
        <h3 class="section-title">Registered Proxies</h3>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr><th>Erf</th><th>Proxy Holder</th><th>Holder Erf</th><th>Form Ref</th><th>Captured</th><th>Notes</th></tr>
            </thead>
            <tbody>
            <?php foreach ($proxies as $p): ?>
              <tr>
                <td><strong><?= htmlspecialchars($p['erf_number']) ?></strong></td>
                <td><?= htmlspecialchars($p['proxy_holder']) ?></td>
                <td><?= htmlspecialchars($p['proxy_holder_erf'] ?? '—') ?></td>
                <td><?= htmlspecialchars($p['form_reference']  ?? '—') ?></td>
                <td style="font-size:.8rem;"><?= date('d M Y H:i', strtotime($p['captured_at'])) ?></td>
                <td style="font-size:.8rem;"><?= htmlspecialchars($p['notes'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- Register new proxy -->
      <?php if ($motion['status'] === 'open'): ?>
      <div class="card card-wide">
        <h3 class="section-title">Register New Proxy</h3>

        <?php if (!empty($perrors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($perrors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?= getFlash() ?>

        <form method="POST">
          <?= csrfField() ?>
          <div class="form-row">
            <div class="form-group">
              <label>Erf Number <span class="required">*</span>
                <span class="muted-note">(erf granting the proxy)</span>
              </label>
              <input type="text" name="erf_number" style="text-transform:uppercase;"
                     value="<?= htmlspecialchars(strtoupper($ppost['erf_number'] ?? '')) ?>"
                     placeholder="e.g. E15227" maxlength="20">
            </div>
            <div class="form-group">
              <label>Proxy Holder Name <span class="required">*</span></label>
              <input type="text" name="proxy_holder" maxlength="100"
                     value="<?= htmlspecialchars($ppost['proxy_holder'] ?? '') ?>"
                     placeholder="Full name of person holding proxy">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Proxy Holder Erf <span class="muted-note">(if also a member)</span></label>
              <input type="text" name="proxy_holder_erf" style="text-transform:uppercase;"
                     value="<?= htmlspecialchars(strtoupper($ppost['proxy_holder_erf'] ?? '')) ?>"
                     placeholder="e.g. E15300" maxlength="20">
            </div>
            <div class="form-group">
              <label>Form Reference <span class="muted-note">(paper form number)</span></label>
              <input type="text" name="form_reference" maxlength="50"
                     value="<?= htmlspecialchars($ppost['form_reference'] ?? '') ?>"
                     placeholder="e.g. PROXY-2026-001">
            </div>
          </div>
          <div class="form-group">
            <label>Notes <span class="muted-note">(optional)</span></label>
            <input type="text" name="notes" maxlength="255"
                   value="<?= htmlspecialchars($ppost['notes'] ?? '') ?>">
          </div>
          <div class="btn-group">
            <button type="submit" class="btn btn-primary">Register Proxy</button>
            <a href="comms_voting.php?action=list&meeting_id=<?= $meetingId ?>" class="btn btn-navy">← Back to Motions</a>
          </div>
        </form>
      </div>
      <?php endif; ?>

    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// CAPTURE — admin captures manual or proxy vote per erf
// ============================================================
if ($action === 'capture' && $motionId > 0) {

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($motion['status'] !== 'open') {
        setFlash('error', 'Votes can only be captured for open motions.');
        header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
    }

    $cerrors = [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $erf    = strtoupper(trim($_POST['erf_number']     ?? ''));
        $method = trim($_POST['capture_method']            ?? '');
        $vote   = trim($_POST['vote_option']               ?? '');
        $blank  = empty($vote) ? 1 : 0;
        if ($blank) $vote = 'abstain';

        $validMethods = ['manual', 'proxy'];
        $validVotes   = ['for', 'against', 'abstain', 'noshow'];

        if ($erf === '')                       $cerrors[] = 'Erf number is required.';
        if (!in_array($method, $validMethods)) $cerrors[] = 'Select a valid capture method.';
        if (!in_array($vote, $validVotes))     $cerrors[] = 'Select a valid vote option.';

        // If proxy method — check proxy is registered
        $proxyId = null;
        if (empty($cerrors) && $method === 'proxy') {
            $pcheck = db()->prepare("SELECT id FROM vote_proxy WHERE motion_id=? AND erf_number=?");
            $pcheck->execute([$motionId, $erf]);
            $prow = $pcheck->fetch();
            if (!$prow) {
                $cerrors[] = "No proxy is registered for Erf {$erf}. Register the proxy first.";
            } else {
                $proxyId = $prow['id'];
            }
        }

        // Check not already voted
        if (empty($cerrors)) {
            $already = db()->prepare("SELECT id FROM vote_cast WHERE motion_id=? AND erf_number=?");
            $already->execute([$motionId, $erf]);
            if ($already->fetch()) $cerrors[] = "Erf {$erf} has already cast a vote for this motion.";
        }

        if (empty($cerrors)) {
            // ── Retry with backoff for transient DB errors only ──
            $maxAttempts = 3;
            $attempt     = 0;

            while ($attempt < $maxAttempts) {
                $attempt++;
                try {
                    $pdo = db();
                    $pdo->beginTransaction();

                    $ins = $pdo->prepare(
                        "INSERT INTO vote_cast
                         (motion_id, erf_number, capture_method, cast_by_role, cast_by_id, proxy_id, ip_address)
                         VALUES (?,?,?,'admin',?,?,?)"
                    );
                    $ins->execute([
                        $motionId, $erf, $method, $adminId,
                        $proxyId, $_SERVER['REMOTE_ADDR'] ?? null,
                    ]);
                    $castId = (int)$pdo->lastInsertId();

                    $pdo->prepare(
                        "INSERT INTO vote_tally (cast_id, vote_option, is_blank) VALUES (?,?,?)"
                    )->execute([$castId, $vote, $blank]);

                    $pdo->commit();
                    setFlash('success', "Vote captured for Erf {$erf} — " . strtoupper($vote) . " (" . ucfirst($method) . ").");
                    header("Location: comms_voting.php?action=capture&id={$motionId}&meeting_id={$meetingId}"); exit;

                } catch (\Throwable $e) {
                    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();

                    if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                        setFlash('error', "Erf {$erf} has already cast a vote.");
                        header("Location: comms_voting.php?action=capture&id={$motionId}&meeting_id={$meetingId}"); exit;
                    }

                    $transient = str_contains($e->getMessage(), 'Lock wait timeout')
                              || str_contains($e->getMessage(), 'Deadlock')
                              || str_contains($e->getMessage(), '1205')
                              || str_contains($e->getMessage(), '1213')
                              || str_contains($e->getMessage(), 'server has gone away')
                              || str_contains($e->getMessage(), 'Lost connection')
                              || str_contains($e->getMessage(), 'Too many connections');

                    if ($transient && $attempt < $maxAttempts) {
                        usleep(random_int(100000, 300000));
                        continue;
                    }

                    setFlash('error', 'Error capturing vote. Please try again.');
                    header("Location: comms_voting.php?action=capture&id={$motionId}&meeting_id={$meetingId}"); exit;
                }
            }
        }
    }

    // Recent captures
    $recent = db()->prepare(
        "SELECT vc.erf_number, vc.capture_method, vt.vote_option, vt.is_blank, vc.cast_at
         FROM vote_cast vc
         JOIN vote_tally vt ON vt.cast_id = vc.id
         WHERE vc.motion_id=? AND vc.capture_method IN ('manual','proxy')
         ORDER BY vc.cast_at DESC LIMIT 20"
    );
    $recent->execute([$motionId]);
    $recent = $recent->fetchAll();

    $counts = voteCounts($motionId);
    $quorum = checkQuorum($counts, $motion);

    pageHeader('Capture Vote', 'admin');
    renderHeader('✏️ Capture Vote', 'comms.php');
    ?>

    <main class="pc-main">

      <!-- Live count strip -->
      <div class="card card-wide" style="margin-bottom:16px;">
        <h2 class="card-title" style="margin-bottom:10px;">
          🗳️ Motion <?= (int)$motion['motion_number'] ?>: <?= htmlspecialchars($motion['title']) ?>
          <span class="badge badge-green" style="margin-left:8px;">Open</span>
        </h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-top:8px;">
          <?php
            $tiles = [
              ['Online',   $counts['online'],   '#1565c0'],
              ['Manual',   $counts['manual'],   '#6a1b9a'],
              ['Proxy',    $counts['proxy'],    '#e65100'],
              ['For',      $counts['for'],      '#2e7d32'],
              ['Against',  $counts['against'],  '#c62828'],
              ['Abstain',  $counts['abstain'],  '#757575'],
              ['Physical', $counts['physical'], '#0277bd'],
              ['Total',    $counts['total'],    '#1a237e'],
            ];
            foreach ($tiles as [$label, $val, $color]):
          ?>
          <div style="background:#fff;border:2px solid <?= $color ?>;border-radius:8px;
                      padding:10px;text-align:center;">
            <div style="font-size:1.5rem;font-weight:800;color:<?= $color ?>;"><?= $val ?></div>
            <div style="font-size:.75rem;color:#666;"><?= $label ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <!-- Quorum status -->
        <div style="margin-top:12px;padding:10px 14px;border-radius:6px;
                    background:<?= $quorum['met'] ? '#e8f5e9' : '#fff3e0' ?>;
                    border:1px solid <?= $quorum['met'] ? '#a5d6a7' : '#ffcc02' ?>;">
          <strong>Quorum:</strong>
          <?php if ($quorum['met']): ?>
            ✅ Met — <?= $counts['physical'] ?> physical (min <?= $motion['quorum_physical'] ?>)
            + <?= $counts['proxy'] ?> proxy = <?= $counts['total'] ?> total (min <?= $motion['quorum_total'] ?>)
          <?php else: ?>
            ⚠️ Not yet met —
            Physical: <?= $counts['physical'] ?>/<?= $motion['quorum_physical'] ?>
            <?= $quorum['physicalOk'] ? '✅' : '❌' ?> &nbsp;·&nbsp;
            Total: <?= $counts['total'] ?>/<?= $motion['quorum_total'] ?>
            <?= $quorum['totalOk'] ? '✅' : '❌' ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Capture form -->
      <div class="card card-wide" style="margin-bottom:16px;">
        <h3 class="section-title">Capture Vote</h3>

        <?php if (!empty($cerrors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($cerrors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?= getFlash() ?>

        <form method="POST">
          <?= csrfField() ?>
          <div class="form-row">
            <div class="form-group">
              <label>Erf Number <span class="required">*</span></label>
              <input type="text" name="erf_number" required style="text-transform:uppercase;"
                     placeholder="e.g. E15227" maxlength="20"
                     value="<?= htmlspecialchars(strtoupper($_POST['erf_number'] ?? '')) ?>">
            </div>
            <div class="form-group">
              <label>Capture Method <span class="required">*</span></label>
              <select name="capture_method" required>
                <option value="">— Select —</option>
                <option value="manual" <?= ($_POST['capture_method'] ?? '') === 'manual' ? 'selected' : '' ?>>
                  Manual (written / show of hands)
                </option>
                <option value="proxy"  <?= ($_POST['capture_method'] ?? '') === 'proxy'  ? 'selected' : '' ?>>
                  Proxy (pre-submitted form)
                </option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>Vote <span class="required">*</span>
              <span class="muted-note">(leave blank to record as Abstain)</span>
            </label>
            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px;">
              <?php
                $voteOpts = [
                  'for'     => ['label'=>'For',     'color'=>'#2e7d32'],
                  'against' => ['label'=>'Against', 'color'=>'#c62828'],
                  'abstain' => ['label'=>'Abstain', 'color'=>'#757575'],
                  'noshow'  => ['label'=>'No Show', 'color'=>'#9e9e9e'],
                ];
                foreach ($voteOpts as $val => $opt):
              ?>
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
                             padding:10px 18px;border:2px solid <?= $opt['color'] ?>;
                             border-radius:8px;font-weight:600;
                             <?= ($_POST['vote_option'] ?? '') === $val ? 'background:' . $opt['color'] . ';color:#fff;' : 'color:' . $opt['color'] . ';' ?>">
                <input type="radio" name="vote_option" value="<?= $val ?>"
                       <?= ($_POST['vote_option'] ?? '') === $val ? 'checked' : '' ?>
                       style="accent-color:<?= $opt['color'] ?>;">
                <?= $opt['label'] ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="btn-group" style="margin-top:12px;">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Confirm vote capture for this erf? This cannot be changed.')">
              Capture Vote ✔
            </button>
            <a href="comms_voting.php?action=list&meeting_id=<?= $meetingId ?>" class="btn btn-navy">← Back to Motions</a>
          </div>
        </form>
      </div>

      <!-- Recent captures -->
      <?php if (!empty($recent)): ?>
      <div class="card card-wide">
        <h3 class="section-title">Recent Captures (Manual &amp; Proxy)</h3>
        <div class="table-responsive">
          <table class="data-table">
            <thead><tr><th>Erf</th><th>Method</th><th>Vote</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $rc): ?>
              <tr>
                <td><strong><?= htmlspecialchars($rc['erf_number']) ?></strong></td>
                <td><span class="badge badge-info"><?= ucfirst($rc['capture_method']) ?></span></td>
                <td>
                  <?php
                    $vColor = match($rc['vote_option']) {
                        'for'     => '#2e7d32',
                        'against' => '#c62828',
                        default   => '#757575',
                    };
                  ?>
                  <span style="color:<?= $vColor ?>;font-weight:700;">
                    <?= strtoupper($rc['vote_option']) ?>
                    <?= $rc['is_blank'] ? '<span class="muted-note">(blank)</span>' : '' ?>
                  </span>
                </td>
                <td style="font-size:.8rem;"><?= date('H:i:s', strtotime($rc['cast_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// RESULTS — full result display
// ============================================================
if ($action === 'results' && $motionId > 0) {

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];
    $rt        = RESOLUTION_TYPES[$motion['resolution_type']];

    // For closed motions use stored result; for open use live counts
    if ($motion['status'] === 'closed') {
        $res = db()->prepare("SELECT * FROM vote_result WHERE motion_id=?");
        $res->execute([$motionId]);
        $result = $res->fetch();
        $counts = [
            'online'     => $result['count_online'],
            'manual'     => $result['count_manual'],
            'proxy'      => $result['count_proxy'],
            'for'        => $result['count_for'],
            'against'    => $result['count_against'],
            'abstain'    => $result['count_abstain'],
            'noshow'     => $result['count_noshow'],
            'blank'      => $result['count_blank'],
            'physical'   => $result['physical_present'],
            'total'      => $result['total_voted'],
            'forAgainst' => $result['count_for'] + $result['count_against'],
            'resultPct'  => $result['result_pct'],
        ];
        $quorum = ['met' => $result['quorum_met']];
        $passed = $result['passed'];
    } else {
        $counts = voteCounts($motionId);
        $quorum = checkQuorum($counts, $motion);
        $passed = $quorum['met'] && $counts['resultPct'] >= $rt['threshold'];
        $result = null;
    }

    pageHeader('Results', 'admin');
    renderHeader('📊 Vote Results', 'comms.php');
    ?>

    <main class="pc-main">

      <!-- Header -->
      <div class="card card-wide" style="margin-bottom:16px;">
        <div class="card-toolbar">
          <div>
            <h2 class="card-title">📊 Motion <?= (int)$motion['motion_number'] ?>: <?= htmlspecialchars($motion['title']) ?></h2>
            <p style="margin-top:4px;">
              <span class="badge <?= $motion['status'] === 'open' ? 'badge-green' : 'badge-grey' ?>">
                <?= ucfirst($motion['status']) ?>
              </span>
              &nbsp;
              <span style="font-size:.85rem;color:#666;">
                <?= htmlspecialchars($rt['label']) ?> — <?= $rt['threshold'] ?>% required
              </span>
            </p>
          </div>
          <?php if ($motion['status'] === 'closed'): ?>
          <a href="comms_voting.php?action=csv&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>" class="btn btn-green">⬇ Download CSV</a>
          <?php endif; ?>
        </div>
      </div>

      <!-- Outcome banner (closed motions) -->
      <?php if ($motion['status'] === 'closed'): ?>
      <div style="padding:20px 24px;border-radius:10px;text-align:center;margin-bottom:16px;
                  background:<?= $passed ? '#e8f5e9' : '#ffebee' ?>;
                  border:2px solid <?= $passed ? '#4caf50' : '#f44336' ?>;">
        <div style="font-size:2.5rem;"><?= $passed ? '✅' : '❌' ?></div>
        <div style="font-size:1.4rem;font-weight:800;color:<?= $passed ? '#2e7d32' : '#c62828' ?>;margin-top:6px;">
          MOTION <?= $passed ? 'PASSED' : 'FAILED' ?>
        </div>
        <div style="font-size:.9rem;color:#555;margin-top:4px;">
          <?= $counts['resultPct'] ?>% For (<?= $rt['threshold'] ?>% required)
          <?php if (!$quorum['met']): ?>
            &nbsp;·&nbsp; <strong style="color:#e65100;">Quorum not met</strong>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Vote breakdown -->
      <div class="card card-wide" style="margin-bottom:16px;">
        <h3 class="section-title">Vote Breakdown</h3>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:12px;margin-top:8px;">
          <?php
            $tiles = [
              ['For',      $counts['for'],      '#2e7d32'],
              ['Against',  $counts['against'],  '#c62828'],
              ['Abstain',  $counts['abstain'],  '#757575'],
              ['No Show',  $counts['noshow'],   '#9e9e9e'],
              ['Online',   $counts['online'],   '#1565c0'],
              ['Manual',   $counts['manual'],   '#6a1b9a'],
              ['Proxy',    $counts['proxy'],    '#e65100'],
              ['Physical', $counts['physical'], '#0277bd'],
              ['Total',    $counts['total'],    '#1a237e'],
            ];
            foreach ($tiles as [$label, $val, $color]):
          ?>
          <div style="background:#fff;border:2px solid <?= $color ?>;border-radius:8px;
                      padding:12px;text-align:center;">
            <div style="font-size:1.8rem;font-weight:800;color:<?= $color ?>;"><?= $val ?></div>
            <div style="font-size:.75rem;color:#666;"><?= $label ?></div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Result percentage bar -->
        <?php if ($counts['forAgainst'] > 0): ?>
        <div style="margin-top:20px;">
          <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:4px;">
            <span style="color:#2e7d32;font-weight:700;">For: <?= $counts['resultPct'] ?>%</span>
            <span style="color:#c62828;font-weight:700;">Against: <?= round(100 - $counts['resultPct'], 2) ?>%</span>
          </div>
          <div style="background:#f8d7da;border-radius:20px;height:24px;overflow:hidden;">
            <div style="background:#2e7d32;width:<?= min(100, $counts['resultPct']) ?>%;height:100%;
                        border-radius:20px;transition:width .5s;position:relative;">
              <?php if ($counts['resultPct'] >= $rt['threshold']): ?>
                <div style="position:absolute;right:6px;top:3px;font-size:.75rem;color:#fff;font-weight:700;">
                  ✔ <?= $counts['resultPct'] ?>%
                </div>
              <?php endif; ?>
            </div>
          </div>
          <!-- Threshold marker -->
          <div style="position:relative;height:16px;">
            <div style="position:absolute;left:<?= $rt['threshold'] ?>%;transform:translateX(-50%);
                        font-size:.7rem;color:#e65100;white-space:nowrap;">
              ▲ <?= $rt['threshold'] ?>% required
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Quorum detail -->
      <div class="card card-wide" style="margin-bottom:16px;">
        <h3 class="section-title">Quorum</h3>
        <table class="data-table" style="max-width:480px;">
          <thead><tr><th>Requirement</th><th>Required</th><th>Actual</th><th>Status</th></tr></thead>
          <tbody>
            <tr>
              <td>Physical present (online + manual)</td>
              <td><?= $motion['quorum_physical'] ?></td>
              <td><?= $counts['physical'] ?></td>
              <td><?= $counts['physical'] >= $motion['quorum_physical'] ? '✅' : '❌' ?></td>
            </tr>
            <tr>
              <td>Total (physical + proxy)</td>
              <td><?= $motion['quorum_total'] ?></td>
              <td><?= $counts['total'] ?></td>
              <td><?= $counts['total'] >= $motion['quorum_total'] ? '✅' : '❌' ?></td>
            </tr>
            <tr>
              <td><strong>Quorum met</strong></td>
              <td colspan="2">Both conditions above must be satisfied</td>
              <td><?= $quorum['met'] ? '✅ Yes' : '❌ No' ?></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card card-wide">
        <div class="btn-group">
          <?php if ($motion['status'] === 'closed'): ?>
            <a href="comms_voting.php?action=csv&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>" class="btn btn-green">⬇ Download CSV</a>
          <?php endif; ?>
          <?php if ($motion['status'] === 'open'): ?>
            <a href="comms_voting.php?action=capture&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>" class="btn btn-primary">✏️ Capture Vote</a>
            <a href="comms_voting.php?action=proxies&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>" class="btn btn-navy">📋 Proxies</a>
          <?php endif; ?>
          <a href="comms_voting.php?action=list&meeting_id=<?= $meetingId ?>" class="btn btn-navy">← Back to Motions</a>
        </div>
      </div>

    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// CSV — download results
// ============================================================
if ($action === 'csv' && $motionId > 0) {

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];
    $rt        = RESOLUTION_TYPES[$motion['resolution_type']];

    $res = db()->prepare("SELECT * FROM vote_result WHERE motion_id=?");
    $res->execute([$motionId]);
    $result = $res->fetch();

    if (!$result) {
        setFlash('error', 'No result record found. The motion may not be closed yet.');
        header("Location: comms_voting.php?action=results&id={$motionId}&meeting_id={$meetingId}"); exit;
    }

    function csvVoteRow(array $f): string {
        return implode(',', array_map(fn($v) => '"' . str_replace('"','""',$v??'') . '"', $f)) . "\r\n";
    }

    $filename = 'vote_result_' . $motionId . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fwrite($out, csvVoteRow(['MBGE HOA — Vote Result Certificate']));
    fwrite($out, csvVoteRow([]));
    fwrite($out, csvVoteRow(['Motion',          $motion['title']]));
    fwrite($out, csvVoteRow(['Resolution Type', $rt['label']]));
    fwrite($out, csvVoteRow(['Threshold',       $rt['threshold'] . '%']));
    fwrite($out, csvVoteRow(['Status',          'Closed']));
    fwrite($out, csvVoteRow(['Closed At',       date('d M Y H:i', strtotime($result['closed_at']))]));
    fwrite($out, csvVoteRow(['Generated',       date('d M Y H:i')]));
    fwrite($out, csvVoteRow([]));

    fwrite($out, csvVoteRow(['QUORUM', '', '']));
    fwrite($out, csvVoteRow(['Physical present (online + manual)', $result['physical_present'], 'Required: ' . $motion['quorum_physical']]));
    fwrite($out, csvVoteRow(['Total voted (physical + proxy)',     $result['total_voted'],      'Required: ' . $motion['quorum_total']]));
    fwrite($out, csvVoteRow(['Quorum met', $result['quorum_met'] ? 'YES' : 'NO', '']));
    fwrite($out, csvVoteRow([]));

    fwrite($out, csvVoteRow(['VOTE BREAKDOWN', '', '']));
    fwrite($out, csvVoteRow(['Online votes',  $result['count_online'],  '']));
    fwrite($out, csvVoteRow(['Manual votes',  $result['count_manual'],  '']));
    fwrite($out, csvVoteRow(['Proxy votes',   $result['count_proxy'],   '']));
    fwrite($out, csvVoteRow(['Total cast',    $result['total_voted'],   '']));
    fwrite($out, csvVoteRow([]));
    fwrite($out, csvVoteRow(['For',           $result['count_for'],     '']));
    fwrite($out, csvVoteRow(['Against',       $result['count_against'], '']));
    fwrite($out, csvVoteRow(['Abstain',       $result['count_abstain'], '(excluded from threshold)']));
    fwrite($out, csvVoteRow(['No Show',       $result['count_noshow'],  '(excluded from threshold)']));
    fwrite($out, csvVoteRow(['Blank ballots', $result['count_blank'],   '(recorded as abstain)']));
    fwrite($out, csvVoteRow([]));

    $forAgainst = $result['count_for'] + $result['count_against'];
    fwrite($out, csvVoteRow(['RESULT', '', '']));
    fwrite($out, csvVoteRow(['For + Against base',  $forAgainst,           '']));
    fwrite($out, csvVoteRow(['Result percentage',   $result['result_pct'] . '%', 'For / (For+Against)']));
    fwrite($out, csvVoteRow(['Required threshold',  $result['threshold_pct'] . '%', '']));
    fwrite($out, csvVoteRow(['MOTION',              $result['passed'] ? 'PASSED' : 'FAILED', '']));

    fclose($out);
    exit;
}

// ============================================================
// Fallback
// ============================================================
header('Location: comms_voting.php?action=meetings_list');
exit;
