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
//   voter_register  — upload Vote Register for a meeting
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
require_once __DIR__ . "/comms_meeting_permissions.php";
require_once __DIR__ . "/gemb_file_reader.php";

if (file_exists(__DIR__ . '/SimpleXLSXGen.php')) require_once __DIR__ . '/SimpleXLSXGen.php';
if (session_status() === PHP_SESSION_NONE) session_start();

commsRequireAuth();

$action    = $_GET['action'] ?? 'meetings_list';
$adminId   = commsCurrentUserId() ?? 0;
$isVotingAdministrator = (
    commsCurrentUserRole() === 'administrator'
);

// NOTE on parameter mapping:
//  - MEETING actions (meetings_*, voter_register, send_tokens) use ?id=
//    as the meeting id.
//  - MOTION actions (list/create/edit/open/close/delete/proxies/capture/
//    results/csv) use ?meeting_id= for the meeting and ?id= for the motion.
// $meetingId is set per-namespace below so both blocks get the right value
// without renaming every reference in either original file.

$meetingActions = ['meetings_list','meetings_create','meetings_edit','meetings_open','meetings_close','meetings_delete','meetings_delete_test','voter_register','voter_register_sample_csv','voter_register_sample_xlsx','send_tokens','meetings_export'];

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

    
$stmt = db()->prepare(
    "SELECT mt.*,
            mt.auditor_export_at,
            (SELECT COUNT(*) FROM motions mo
             WHERE mo.meeting_id = mt.id) AS motion_count,
            (SELECT COUNT(*) FROM motions mo
             WHERE mo.meeting_id = mt.id
               AND mo.status = 'open') AS motions_open,
            (SELECT COUNT(*) FROM motions mo
             WHERE mo.meeting_id = mt.id
               AND mo.status = 'closed') AS motions_closed,
            (SELECT COUNT(*) FROM motions mo
             WHERE mo.meeting_id = mt.id
               AND mo.status = 'draft') AS motions_draft
     FROM meetings mt
     INNER JOIN meeting_staff_assignments msa
         ON msa.meeting_id = mt.id
     INNER JOIN comms_users cu
         ON cu.id = msa.user_id
     WHERE msa.user_id = ?
       AND msa.active = 1
       AND cu.active = 1
     ORDER BY mt.created_at DESC"
);

$stmt->execute([$adminId]);
$meetings = $stmt->fetchAll();


    pageHeader('Vote Register', 'admin');
    renderHeader('🗳️ Vote Register', 'comms_menu.php');
    ?>

    <main class="pc-main">

      <div class="card card-wide">
        <div class="card-toolbar">
          <div>
            <h2 class="card-title">🗳️ Vote Register</h2>
          </div>
          
          <?php if ($isVotingAdministrator): ?>
    <a href="comms_voting.php?action=meetings_create"
       class="btn btn-primary">＋ New Meeting</a>
<?php endif; ?>

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
               $statusClass = 'badge-amber';

if ($mt['status'] === 'open') {
    $statusClass = 'badge-green';
} elseif ($mt['status'] === 'closed') {
    $statusClass = 'badge-grey';
}
              ?>
              <tr>
                <td><?= $mt['id'] ?></td>
               
                <td>
  <strong><?= htmlspecialchars($mt['title']) ?></strong>
  <?php if ($mt['is_test']): ?>
    <span class="badge badge-amber" style="font-size:.75rem;">TEST</span>
  <?php endif; ?>
</td>
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
        
               </tr>
              <tr>
                <td colspan="6" style="padding:6px 10px 12px;background:#f8fafc;">
                  <div class="btn-group-sm">
                    <a href="comms_voting.php?action=list&meeting_id=<?= $mt['id'] ?>" class="btn btn-sm btn-blue">📋 Motions</a>
                    
                    <?php if ($isVotingAdministrator && $mt['status'] === 'draft'): ?>
                    
                      <a href="comms_voting.php?action=meetings_edit&id=<?= $mt['id'] ?>" class="btn btn-sm btn-amber">⚙️ Edit</a>
                      <form method="POST" action="comms_voting.php?action=meetings_open&id=<?= $mt['id'] ?>" style="display:inline;">
                        <?= csrfField() ?>
                        <button type="submit" class="btn btn-sm btn-green"
                                onclick="return confirm('Open this meeting? You will then be able to open individual motions for voting.')">
                          ▶ Open
                        </button>
                      </form>
                      <?php if ((int)$mt['motion_count'] === 0): ?>
                        <?php if ($mt['is_test']): ?>
                        <form method="POST" action="comms_voting.php?action=meetings_delete_test&id=<?= $mt['id'] ?>" style="display:inline;">
                          <?= csrfField() ?>
                          <button type="submit" class="btn btn-sm btn-red"
                                  onclick="return confirm('PURGE ALL TEST DATA? Cannot be undone.')">🧹 Purge Test Data</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" action="comms_voting.php?action=meetings_delete&id=<?= $mt['id'] ?>" style="display:inline;">
                          <?= csrfField() ?>
                          <button type="submit" class="btn btn-sm btn-red"
                                  onclick="return confirm('Delete this meeting permanently?')">🗑 Delete</button>
                        </form>
                        <?php endif; ?>
                      <?php endif; ?>
                    <?php endif; ?>
                    
                    
             <?php if ($mt['status'] === 'draft'): ?>
                    
                    <a href="comms_voting.php?action=voter_register&id=<?= $mt['id'] ?>"
                        class="btn btn-sm btn-amber">📋 Vote Register</a>
                    <?php endif; ?>

                    <?php if ($mt['status'] === 'open' || $mt['status'] === 'closed'): ?>
                    
                <?php if ($isVotingAdministrator): ?>
<a href="comms_voting.php?action=send_tokens&id=<?= $mt['id'] ?>"
     class="btn btn-sm btn-green">✉️ Send Tokens</a>
<?php endif; ?>

            <?php if ($isVotingAdministrator): ?>
    <a href="comms_vote_capture.php?meeting_id=<?= $mt['id'] ?>"
       class="btn btn-sm btn-blue">✏️ Batch Capture</a>
<?php else: ?>
    <a href="comms_vote_capture.php?meeting_id=<?= $mt['id'] ?>"
       class="btn btn-sm btn-blue">🔍 Verify Ballots</a>
<?php endif; ?>
            <?php endif; ?>
                    
                    
                    <?php if ($isVotingAdministrator && $mt['status'] === 'open'): ?>
                    
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
                    
              <?php if ($mt['status'] === 'closed'): ?>

    <a href="comms_voting.php?action=meetings_export&id=<?= $mt['id'] ?>"
       class="btn btn-sm btn-navy">📦 Auditor Export</a>

    <?php if ($isVotingAdministrator): ?>
        <?php if ($mt['auditor_export_at']): ?>
                      
                        <form method="POST" action="comms_voting.php?action=meetings_delete&id=<?= $mt['id'] ?>" style="display:inline;">
                          <?= csrfField() ?>
                          <button type="submit" class="btn btn-sm btn-red"
                                  onclick="return confirm('Delete this closed meeting and all its data permanently?\n\nAuditor export was downloaded on <?= date('d M Y H:i', strtotime($mt['auditor_export_at'])) ?>.')">
                            🗑 Delete
                          </button>
                        </form>
                      <?php else: ?>
                        <button class="btn btn-sm btn-red" disabled
                                style="opacity:.4;cursor:not-allowed;"
                                title="Download the Auditor Export before deleting">
                          🗑 Delete
                        </button>
                        <span class="muted-note" style="font-size:.75rem;">
                          — download Auditor Export first
                        </span>
                        <?php endif; ?>
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
          <a href="comms_menu.php" class="btn btn-navy">← Back to Communications</a>
        </div>
      </div>
    </main>

    <script>
    function confirmSend() {
        const mode = document.querySelector('input[name="send_mode"]:checked').value;
        const notGenerated = <?= (int)($counts['not_generated'] ?? 0) ?>;
        const notSent      = <?= (int)($counts['not_sent']      ?? 0) ?>;
        const sentUnused   = <?= (int)($counts['sent_unused']   ?? 0) ?>;
        let count, msg;
        if (mode === 'unsent') {
            count = notGenerated + notSent;
            msg = 'Send tokens to ' + count + ' erf(s) that have not yet been sent a token?';
        } else {
            count = notGenerated + notSent + sentUnused;
            msg = 'Resend tokens to ' + count + ' erf(s) that have not yet voted?\n(Already-used/device-locked tokens are never resent.)';
        }
        if (count === 0) { alert('No erven to send to for the selected mode.'); return false; }
        return confirm(msg);
    }
    </script>
    <?php pageFooter(); exit;
}

// ============================================================
// CREATE
// ============================================================
if ($action === 'meetings_create') {

    // Only Administrators may create meetings.
    commsRequireRole('administrator');

    $errors = [];

    // Only active estates may be selected for a new meeting.
    $estateStmt = db()->query(
        "SELECT id, official_name, short_name
         FROM comms_estates
         WHERE status = 'active'
         ORDER BY official_name ASC"
    );

    $estates = $estateStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $title = trim($_POST['title']        ?? '');
        $date  = trim($_POST['meeting_date'] ?? '');

$estateIdRaw = $_POST['estate_id'] ?? '';

$estateId = filter_var(
    $estateIdRaw,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if ($estateId === false) {
    $errors[] = 'Please select an estate.';
} else {
    $estateCheck = db()->prepare(
        "SELECT COUNT(*)
         FROM comms_estates
         WHERE id = ?
           AND status = 'active'"
    );

    $estateCheck->execute([$estateId]);

    if ((int)$estateCheck->fetchColumn() !== 1) {
        $errors[] = 'The selected estate is not valid or is inactive.';
    }
}

        if ($title === '') $errors[] = 'Meeting title is required.';

        if (empty($errors)) {
            
            db()->prepare(
    "INSERT INTO meetings
        (estate_id, title, meeting_date, status, is_test, created_by)
     VALUES (?, ?, ?, 'draft', ?, ?)"
)->execute([
    $estateId,
    $title,
    $date ?: null,
    isset($_POST['is_test']) ? 1 : 0,
    $adminId
]);
            

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
    <label for="estate_id">
        Estate <span class="required">*</span>
    </label>

    <select
        id="estate_id"
        name="estate_id"
        required
    >
        <option value="">-- Select Estate --</option>

        <?php foreach ($estates as $estate): ?>
            <option
                value="<?= (int)$estate['id'] ?>"
                <?= (string)($_POST['estate_id'] ?? '') === (string)$estate['id']
                    ? 'selected'
                    : '' ?>
            >
                <?= htmlspecialchars(
                    $estate['official_name'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

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
          <div class="form-group">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
              <input type="checkbox" name="is_test" value="1" <?= !empty($_POST['is_test']) ? 'checked' : '' ?> style="width:18px;height:18px;">
              <span>Mark as <strong>Test Meeting</strong> <span class="muted-note">(pre-meeting trial run — can be fully deleted including all votes and tokens)</span></span>
            </label>
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


    // Only Administrators may edit meetings.
    commsRequireRole('administrator');

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
// Requires a populated Vote Register before opening.
// ============================================================
if ($action === 'meetings_open' && $meetingId > 0) {

    // Only Administrators may open meetings.
    commsRequireRole('administrator');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $pdo = db();

        try {
            $pdo->beginTransaction();

            // Lock the meeting to prevent simultaneous status changes.
            $stmt = $pdo->prepare(
                "SELECT id, title, status
                 FROM meetings
                 WHERE id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$meetingId]);
            $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$meeting) {
                throw new RuntimeException('Meeting not found.');
            }

            // Only draft meetings can be opened.
            if ($meeting['status'] !== 'draft') {
                throw new RuntimeException(
                    'Only draft meetings can be opened.'
                );
            }

                        // A meeting must belong to an active estate before voting opens.
            $estateCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM meetings m
                 INNER JOIN comms_estates e
                     ON e.id = m.estate_id
                 WHERE m.id = ?
                   AND e.status = 'active'"
            );

            $estateCheck->execute([$meetingId]);

            if ((int)$estateCheck->fetchColumn() !== 1) {
                throw new RuntimeException(
                    'This meeting cannot be opened for voting because ' .
                    'it is not assigned to an active estate.'
                );
            }

            // A Vote Register must exist before opening.
            $registerStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM vote_eligible_owners
                 WHERE meeting_id = ?"
            );
            $registerStmt->execute([$meetingId]);

            $registeredErven = (int)$registerStmt->fetchColumn();

            if ($registeredErven === 0) {
                throw new RuntimeException(
                    'Cannot open this meeting. Upload the Vote Register first.'
                );
            }

            // Open the meeting.
            $updateStmt = $pdo->prepare(
                "UPDATE meetings
                 SET status = 'open',
                     updated_at = NOW()
                 WHERE id = ?
                   AND status = 'draft'"
            );
            $updateStmt->execute([$meetingId]);

            if ($updateStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The meeting could not be opened. Please try again.'
                );
            }

            $pdo->commit();

            // Record the successful status change.
            try {
                voteAdminLog(
                    'meeting_opened',
                    'meeting',
                    $meetingId,
                    $meeting['title']
                );
            } catch (Throwable $logEx) {
                error_log(
                    'Meeting opening audit log failed: ' .
                    $logEx->getMessage()
                );
            }

            setFlash(
                'success',
                'Meeting opened successfully. ' .
                $registeredErven . ' eligible erf(s) are registered. ' .
                'You can now open individual motions for voting.'
            );

        } catch (RuntimeException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            setFlash('error', $e->getMessage());

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Meeting opening failed for meeting ' .
                $meetingId . ': ' . $e->getMessage()
            );

            setFlash(
                'error',
                'An unexpected error occurred while opening the meeting.'
            );
        }
    }

    header('Location: comms_voting.php?action=meetings_list');
    exit;
}

// ============================================================
// CLOSE — open → closed
// ============================================================
if ($action === 'meetings_close' && $meetingId > 0) {

    // Only Administrators may close meetings.
    commsRequireRole('administrator');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $pdo = db();
        $meeting = null;
        $closedSuccessfully = false;

        try {
            $pdo->beginTransaction();

            // Lock the meeting before checking its status.
            $stmt = $pdo->prepare(
                "SELECT id, title, status
                 FROM meetings
                 WHERE id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$meetingId]);
            $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$meeting) {
                throw new RuntimeException('Meeting not found.');
            }

            if ($meeting['status'] !== 'open') {
                throw new RuntimeException(
                    'Only open meetings can be closed.'
                );
            }

            // All motions must be closed before the meeting closes.
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM motions
                 WHERE meeting_id = ?
                   AND status IN ('draft', 'open')"
            );
            $stmt->execute([$meetingId]);

            if ((int)$stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'All motions must be closed before closing the meeting.'
                );
            }
// Do not close while token delivery claims remain outstanding.
// This includes active sends and uncertain delivery attempts.
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM vote_tokens
     WHERE meeting_id = ?
       AND sending_at IS NOT NULL"
);
$stmt->execute([$meetingId]);

if ((int)$stmt->fetchColumn() > 0) {
    throw new RuntimeException(
        'This meeting has outstanding voting-token delivery attempts. ' .
        'Investigate and resolve them before closing the meeting.'
    );
}
            // Close only if the meeting is still open.
            $stmt = $pdo->prepare(
                "UPDATE meetings
                 SET status = 'closed',
                     updated_at = NOW()
                 WHERE id = ?
                   AND status = 'open'"
            );
            $stmt->execute([$meetingId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The meeting could not be closed. Please try again.'
                );
            }

            $pdo->commit();
            $closedSuccessfully = true;

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            setFlash('error', $e->getMessage());

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Meeting closure failed for meeting ' .
                $meetingId . ': ' . $e->getMessage()
            );

            setFlash(
                'error',
                'An unexpected error occurred while closing the meeting.'
            );
        }

        // Only perform follow-up work after a successful commit.
        if ($closedSuccessfully) {
            setFlash('success', 'Meeting closed.');

            try {
                voteAdminLog(
                    'meeting_closed',
                    'meeting',
                    $meetingId,
                    $meeting['title']
                );
            } catch (Throwable $logEx) {
                error_log(
                    'Meeting closure audit log failed: ' .
                    $logEx->getMessage()
                );
            }

            // Meeting closure notification to all contacts.
            // Do not hold a database transaction open during SMTP.
            try {
                $contacts = commsRecipients('comms_contacts');

                if (!empty($contacts)) {
                    $meetingTitle = $meeting['title'];
                    $subject = 'Voting Closed — ' . $meetingTitle;

                    $broadcastId = commsBroadcastCreate(
                        'vote',
                        'closure_notice',
                        $meetingTitle,
                        null,
                        null,
                        null
                    );

                    $sent = 0;
                    $skip = 0;

                    foreach ($contacts as $contact) {
                        if (!$contact['email']) {
                            $skip++;
                            continue;
                        }

                        $bodyHtml = commsBuildNotifyEmail(
                            $contact['name'],
                            'Voting has closed',
                            "Voting for {$meetingTitle} has now closed.\n\n" .
                            "Thank you to all members who participated. " .
                            "Results will be reviewed by the Board and " .
                            "published in due course.\n\n" .
                            "If you have any queries regarding the voting " .
                            "outcome, please contact the estate office."
                        );

                        $ok = commsSendAndLog(
                            $broadcastId,
                            'vote',
                            $contact['email'],
                            $contact['name'],
                            $subject,
                            $bodyHtml
                        );

                        $ok ? $sent++ : $skip++;
                    }

                    commsBroadcastUpdateCounts(
                        $broadcastId,
                        $sent,
                        $skip
                    );
                }

            } catch (Throwable $notifyEx) {
                error_log(
                    'Meeting closure notification failed: ' .
                    $notifyEx->getMessage()
                );
            }
        }
    }

    header('Location: comms_voting.php?action=meetings_list');
    exit;
}
// ============================================================
// DELETE — any status, cascades all related data
// ============================================================
if ($action === 'meetings_delete' && $meetingId > 0) {

    // Only Administrators may delete meetings.
    commsRequireRole('administrator');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        
        
               $pdo = db();

        try {
            $pdo->beginTransaction();

            // Lock the meeting before checking whether deletion is allowed.
            $meetingLock = $pdo->prepare(
                "SELECT id, title, status, auditor_export_at
                 FROM meetings
                 WHERE id = ?
                 FOR UPDATE"
            );
            $meetingLock->execute([$meetingId]);
            $meeting = $meetingLock->fetch(PDO::FETCH_ASSOC);

            if (!$meeting) {
                throw new RuntimeException('Meeting not found.');
            }

            // Never delete a meeting while voting is open.
            if ($meeting['status'] === 'open') {
                throw new RuntimeException(
                    'This meeting is open and cannot be deleted. Close the meeting first.'
                );
            }

            // Closed meetings require an auditor export before deletion.
            if ($meeting['status'] === 'closed' &&
                empty($meeting['auditor_export_at'])) {
                throw new RuntimeException(
                    'The Auditor Export must be downloaded before this meeting can be deleted.'
                );
            }

            // Only draft or properly exported closed meetings may be deleted.
            if (!in_array($meeting['status'], ['draft', 'closed'], true)) {
                throw new RuntimeException(
                    'This meeting cannot be deleted in its current status.'
                );
            }
            
            
            
            $motionIds = $pdo->prepare("SELECT id FROM motions WHERE meeting_id=?");
            $motionIds->execute([$meetingId]);
            $mids = $motionIds->fetchAll(PDO::FETCH_COLUMN);
            if ($mids) {
                $in = implode(',', array_fill(0, count($mids), '?'));
                $castIds = $pdo->prepare("SELECT id FROM vote_cast WHERE motion_id IN ({$in})");
                $castIds->execute($mids);
                $cids = $castIds->fetchAll(PDO::FETCH_COLUMN);
                if ($cids) {
                    $cin = implode(',', array_fill(0, count($cids), '?'));
                    $pdo->prepare("DELETE FROM vote_tally WHERE cast_id IN ({$cin})")->execute($cids);
                    $pdo->prepare("DELETE FROM device_response_locks WHERE type='vote' AND response_id IN ({$cin})")->execute($cids);
                }
                $pdo->prepare("DELETE FROM vote_cast    WHERE motion_id IN ({$in})")->execute($mids);
                $pdo->prepare("DELETE FROM vote_result  WHERE motion_id IN ({$in})")->execute($mids);
                $pdo->prepare("DELETE FROM vote_proxy   WHERE motion_id IN ({$in})")->execute($mids);
                $pdo->prepare("DELETE FROM motions      WHERE meeting_id=?")->execute([$meetingId]);
            }
            $pdo->prepare("DELETE FROM vote_tokens          WHERE meeting_id=?")->execute([$meetingId]);
            $pdo->prepare("DELETE FROM vote_eligible_owners WHERE meeting_id=?")->execute([$meetingId]);
            $pdo->prepare("DELETE FROM vote_register_log    WHERE meeting_id=?")->execute([$meetingId]);
            $pdo->prepare("DELETE FROM meetings             WHERE id=?")->execute([$meetingId]);
            $pdo->commit();
            voteAdminLog('meeting_deleted', 'meeting', $meetingId, $meeting['title']);
            setFlash('success', 'Meeting and all associated data permanently deleted.');
       
       
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
                setFlash('error', $e->getMessage());
            } else {
                error_log('Meeting deletion failed: ' . $e->getMessage());
                setFlash('error', 'Unable to delete the meeting. Please try again.');
            }
        }
   
   
    }
    header('Location: comms_voting.php?action=meetings_list'); exit;
}

// ============================================================
// VOTER_REGISTER_SAMPLE_CSV — download sample CSV
// ============================================================
if ($action === 'voter_register_sample_csv') {
    $filename = 'vote_register_sample.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
    fputcsv($fh, ['erf_owner', 'name_owner', 'email_owner', 'phone_owner', 'pq']);
    fputcsv($fh, ['E15227', 'John Smith',    'johnsmith@example.com',  '0821234567', '0.0025']);
    fputcsv($fh, ['E15228', 'Jane Doe',      'janedoe@example.com',    '0719876543', '0.0025']);
    fputcsv($fh, ['E15229', 'Robert Johnson','rjohnson@example.com',   '0831112222', '0.0026']);
    fputcsv($fh, ['E15230', 'Mary Williams', 'mwilliams@example.com',  '0763334444', '0.0024']);
    fclose($fh);
    exit;
}

// ============================================================
// VOTER_REGISTER_SAMPLE_XLSX — download sample XLSX
// ============================================================
if ($action === 'voter_register_sample_xlsx') {
    if (!class_exists('Shuchkin\SimpleXLSXGen')) {
        setFlash('error', 'Excel generation library (SimpleXLSXGen.php) is not installed on the server. Please download the CSV sample instead.');
        header('Location: comms_voting.php?action=voter_register&id=' . $meetingId); exit;
    }
    $rows = [
        ['erf_owner', 'name_owner',    'email_owner',            'phone_owner',  'pq'],
        ['E15227',    'John Smith',     'johnsmith@example.com',  '0821234567',   '0.0025'],
        ['E15228',    'Jane Doe',       'janedoe@example.com',    '0719876543',   '0.0025'],
        ['E15229',    'Robert Johnson', 'rjohnson@example.com',   '0831112222',   '0.0026'],
        ['E15230',    'Mary Williams',  'mwilliams@example.com',  '0763334444',   '0.0024'],
    ];
    $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($rows, 'Vote Register');
    $xlsx->downloadAs('vote_register_sample.xlsx');
    exit;
}

// ============================================================
// VOTE REGISTER — upload Vote Register (Erf, Owner Name, Email, Phone)
// Re-uploadable: replaces existing rows for this meeting.
// ============================================================
if ($action === 'voter_register' && $meetingId > 0) {

    // Administrator and Supervisor may view the Vote Register.
    commsRequireRole('administrator', 'supervisor');

    $meeting = loadMeeting($meetingId);

    $errors  = [];
    $preview = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Only Administrators may upload or replace the register.
        commsRequireRole('administrator');

        verifyCsrfToken();

        if (empty($_FILES['levy_csv']['tmp_name']) || $_FILES['levy_csv']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please choose a file to upload.';
        } else {
            $ext = gembUploadExtension($_FILES['levy_csv']);
            if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
                $errors[] = 'Please upload a .csv, .txt, or .xlsx file.';
            } else {
                $fileResult = gembReadImportFile($_FILES['levy_csv']['tmp_name'], $ext);
                if (!$fileResult['ok']) {
                    $errors[] = $fileResult['error'];
                } else {
                    $rows = [];
                    foreach ($fileResult['rows'] as $raw) {
                        // Normalise: work with both keyed (header row) and
                        // numeric (no header) column layouts.
                        // Re-index to simple numeric array for positional logic.
                        $line = array_values($raw);
                        $line = array_map('trim', $line);

                        // Skip completely empty rows
                        if (implode('', $line) === '') continue;

                        // ── ERF — always first column ──────────────────
                        // Try named key first (when header row present)
                        $erf = '';
                        foreach ($raw as $k => $v) {
                            if (in_array(strtolower((string)$k), ['erf_owner','erf','erf_number','erfno','erf number','erf no'], true)) {
                                $erf = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$v));
                                break;
                            }
                        }
                        // Fallback: first column
                        if ($erf === '') {
                            $erf = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $line[0] ?? ''));
                        }
                        if ($erf === '') continue;

                        // ── EMAIL — scan all columns for valid email ────
                        $email    = '';
                        $emailCol = null;
                        // Try named key first
                        foreach ($raw as $k => $v) {
                            if (in_array(strtolower((string)$k), ['email_owner','email','e-mail','e_mail','email address','emailaddress','mail'], true)) {
                                if (filter_var(trim((string)$v), FILTER_VALIDATE_EMAIL)) {
                                    $email    = trim((string)$v);
                                    $emailCol = array_search($k, array_keys($raw));
                                    break;
                                }
                            }
                        }
                        // Fallback: scan positionally
                        if ($email === '') {
                            for ($c = 1; $c < count($line); $c++) {
                                if (filter_var($line[$c], FILTER_VALIDATE_EMAIL)) {
                                    $email    = $line[$c];
                                    $emailCol = $c;
                                    break;
                                }
                            }
                        }
                        if ($email === '') continue; // no valid email — skip row

                        // ── OWNER NAME ────────────────────────────────
                        $name = '';
                        foreach ($raw as $k => $v) {
                            if (in_array(strtolower((string)$k), ['name_owner','name','owner','owner_name','ownername','full name','fullname','resident'], true)) {
                                $name = trim((string)$v);
                                break;
                            }
                        }
                        // Fallback: columns between erf (col 0) and email column
                        if ($name === '' && $emailCol !== null && $emailCol > 1) {
                            $nameParts = array_slice($line, 1, $emailCol - 1);
                            $nameParts = array_filter($nameParts, fn($v) => $v !== '');
                            $name = trim(implode(' ', $nameParts));
                        }
                        if ($name === '') continue;

                        // ── PHONE and PQ ──────────────────────────────
                        $phone = '';
                        $pq    = 0.0;
                        foreach ($raw as $k => $v) {
                            $kl = strtolower((string)$k);
                            if (in_array($kl, ['phone_owner','phone','cell','mobile','telephone','tel'], true) && $phone === '') {
                                $phone = trim((string)$v);
                            }
                            if (in_array($kl, ['pq','pq_value','participation quota','participation_quota'], true)) {
                                $pqv = str_replace(',', '.', (string)$v);
                                if (is_numeric($pqv) && (float)$pqv > 0 && (float)$pqv <= 1) {
                                    $pq = round((float)$pqv, 4);
                                }
                            }
                        }
                        // Fallback: positional scan after email column
                        if ($phone === '' && $emailCol !== null) {
                            for ($c = $emailCol + 1; $c < count($line); $c++) {
                                if ($line[$c] !== '') {
                                    if ($phone === '') {
                                        $phone = $line[$c];
                                    } else {
                                        $pqv = str_replace(',', '.', $line[$c]);
                                        if (is_numeric($pqv) && (float)$pqv > 0 && (float)$pqv <= 1) {
                                            $pq = round((float)$pqv, 4);
                                            break;
                                        }
                                    }
                                }
                            }
                        }

                        $rows[] = [$erf, $name, $email, $phone, $pq];
                    }

                    if (empty($rows) && empty($errors)) {
                        $errors[] = 'No valid rows found. Expected columns: Erf, Owner Name, Email, Phone (PQ optional).';
                    }

                    if (empty($errors)) {
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // Lock the meeting record while checking whether the
        // Vote Register can safely be replaced.
        $lockStmt = $pdo->prepare(
            "SELECT status FROM meetings WHERE id = ? FOR UPDATE"
        );
        $lockStmt->execute([$meetingId]);
        $meetingStatus = $lockStmt->fetchColumn();

        if ($meetingStatus === false) {
            throw new RuntimeException(
                'Meeting not found.'
            );
        }

        // Register replacement is permitted only while the
        // meeting is still in draft status.
        if ($meetingStatus !== 'draft') {
            throw new RuntimeException(
                'The Vote Register cannot be replaced after the meeting has been opened.'
            );
        }

        // Additional safeguard: reject replacement if any
        // ballots have already been recorded for this meeting.
        $voteCheck = $pdo->prepare(
            "SELECT COUNT(*)
             FROM vote_cast vc
             INNER JOIN motions m ON m.id = vc.motion_id
             WHERE m.meeting_id = ?"
        );
        $voteCheck->execute([$meetingId]);

        if ((int)$voteCheck->fetchColumn() > 0) {
            throw new RuntimeException(
                'The Vote Register cannot be replaced because votes have already been recorded.'
            );
        }

        // Replace existing register for this meeting
                            $pdo->prepare("DELETE FROM vote_eligible_owners WHERE meeting_id = ?")
                                ->execute([$meetingId]);

                            $ins = $pdo->prepare(
                                "INSERT INTO vote_eligible_owners
                                 (meeting_id, erf_number, owner_name, owner_email, owner_phone, pq_value)
                                 VALUES (?,?,?,?,?,?)
                                 ON DUPLICATE KEY UPDATE
                                   owner_name  = VALUES(owner_name),
                                   owner_email = VALUES(owner_email),
                                   owner_phone = VALUES(owner_phone),
                                   pq_value    = VALUES(pq_value)"
                            );
                            foreach ($rows as $r) {
                                $ins->execute([$meetingId, $r[0], $r[1], $r[2], $r[3], $r[4] ?? 0.0]);
                            }

                            // Clear previously-generated tokens — register changed
                            $pdo->prepare("DELETE FROM vote_tokens WHERE meeting_id = ?")
                                ->execute([$meetingId]);

                            $pdo->commit();
                            setFlash('success', count($rows) . ' erf(s) loaded into the Vote Register.');
                            voteAdminLog('register_uploaded', 'meeting', $meetingId, count($rows) . ' erven loaded');

                            // Register upload audit log
                            try {
                                db()->prepare(
                                    "INSERT INTO vote_register_log
                                     (meeting_id, admin_id, admin_email, row_count, file_name, file_hash, ip_address, note)
                                     VALUES (?,?,?,?,?,?,?,?)"
                                )->execute([
                                    $meetingId,
                                    $_SESSION['comms_user_id'] ?? null,
                                    $_SESSION['comms_user']    ?? null,
                                    count($rows),
                                    $_FILES['levy_csv']['name'] ?? null,
                                    hash_file('sha256', $_FILES['levy_csv']['tmp_name']),
                                    $_SERVER['REMOTE_ADDR'] ?? null,
                                    'Voter register uploaded (' . strtoupper($ext) . ') — previous register replaced',
                                ]);
                            } catch (\Throwable $logEx) {
                                error_log('vote_register_log failed: ' . $logEx->getMessage());
                            }
                            header('Location: comms_voting.php?action=voter_register&id=' . $meetingId); exit;
                        } catch (\Throwable $e) {
                            $pdo->rollBack();
                            $errors[] = 'Database error: ' . $e->getMessage();
                        }
                    }
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

    pageHeader('Vote Register', 'admin');
    renderHeader('📋 Vote Register — ' . htmlspecialchars($meeting['title']), 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">
        <h2 class="card-title">📋 Vote Register — <?= htmlspecialchars($meeting['title']) ?></h2>

        <?= getFlash() ?>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>
        
        <?php if ($isVotingAdministrator): ?>

        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:16px;">
            
            
            
          Upload the <strong>Vote Register</strong> as an <strong>Excel (.xlsx)</strong> or <strong>CSV</strong> file
          with columns: <strong>erf_owner, name_owner, email_owner, phone_owner, pq</strong>
          where <strong>pq</strong> is the Participation Quota for the erf — a decimal fraction
          between 0 and 1 (e.g. 0.0507), where all erven in the scheme sum to exactly 1.0000.
          PQ values are sourced from the sectional plan filed at the Deeds Office.
          If no PQ column is present, headcount-only voting is recorded.
          A header row is optional and will be detected automatically.
          Re-uploading <strong>replaces</strong> the entire Vote Register for this meeting and
          clears any tokens already generated — use this to correct mistakes
          <em>before</em> clicking &quot;Send Tokens&quot;.
        </div>

        <form method="POST" enctype="multipart/form-data" style="margin-bottom:24px;">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Vote Register — Excel or CSV</label>
            <input type="file" name="levy_csv" accept=".csv,.txt,.xlsx" required>
          </div>
          
         <div class="btn-group">
        <button type="submit" class="btn btn-primary">Upload / Replace Register</button>
        </div>
          
        </form>

        <?php endif; // End Administrator-only upload section ?>
        
        <div class="card" style="margin-bottom:24px;padding:16px 20px;">
          <div class="card-title">📄 Sample Files</div>
          
          
          <p style="font-size:.87rem;color:#555;margin:0 0 12px;">
            Download a blank sample to use as a template.
            Columns: <code>erf_owner</code>, <code>name_owner</code>,
            <code>email_owner</code>, <code>phone_owner</code>, <code>pq</code>
          </p>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="comms_voting.php?action=voter_register_sample_xlsx&id=<?= $meetingId ?>"
               class="btn btn-secondary btn-sm">⬇ Sample XLSX</a>
            <a href="comms_voting.php?action=voter_register_sample_csv&id=<?= $meetingId ?>"
               class="btn btn-secondary btn-sm">⬇ Sample CSV</a>
          </div>
        </div>

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
          <p class="muted-note">No Vote Register uploaded yet for this meeting.</p>
        <?php endif; ?>
                <div class="btn-group" style="margin-top:20px;">
          <a href="comms_voting.php?action=meetings_list" class="btn btn-navy">← Back to Meetings</a>
        </div>

      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// SEND TOKENS — generate (if needed) and email tokens to
// owner_email from the Vote Register
// ============================================================
if ($action === 'send_tokens' && $meetingId > 0) {

    // Only Administrators may generate and send voting tokens.
    commsRequireRole('administrator');

    $meeting = loadMeeting($meetingId);
    
    // Voting tokens may only be sent while the meeting is open.
    if ($meeting['status'] !== 'open') {
        setFlash(
            'error',
            'Voting tokens can only be sent while the meeting is open.'
        );
        header('Location: comms_voting.php?action=meetings_list');
        exit;
    }
    $stmt = db()->prepare("SELECT * FROM vote_eligible_owners WHERE meeting_id = ? ORDER BY erf_number");
    $stmt->execute([$meetingId]);
    $owners = $stmt->fetchAll();

    if (empty($owners)) {
        setFlash('error', 'No Vote Register found for this meeting. Upload the Vote Register first.');
        header('Location: comms_voting.php?action=voter_register&id=' . $meetingId); exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        $mode = $_POST['send_mode'] ?? 'unsent';

// Accept only the two supported sending modes.
if (!is_string($mode) || !in_array($mode, ['unsent', 'all'], true)) {
    setFlash('error', 'Invalid token sending mode.');
    header('Location: comms_voting.php?action=send_tokens&id=' . $meetingId);
    exit;
}

        $sent  = 0;
        $skip  = 0;
        $sendErrors = [];
        $voteLoginUrl = SITE_URL . '/vote_login.php';

        $broadcastId = commsBroadcastCreate('vote', 'vote_tokens', $meeting['title'], null, null, null);

                foreach ($owners as $o) {

            // Prepare and claim this owner's token while holding
            // the meeting lock. SMTP delivery happens AFTER commit.
            $erf = $o['erf_number'];
            $sendingClaim = null;
            $token = null;
            $tokenReady = false;
            $stopSending = false;

            $pdo = db();

            try {
                $pdo->beginTransaction();

                // Lock the meeting first, matching the closure handler.
                $meetingStmt = $pdo->prepare(
                    "SELECT status
                     FROM meetings
                     WHERE id = ?
                     FOR UPDATE"
                );
                $meetingStmt->execute([$meetingId]);
                $currentStatus = $meetingStmt->fetchColumn();

                if ($currentStatus !== 'open') {
                    $stopSending = true;
                } else {

                    // Find an existing token for this erf.
                    $tstmt = $pdo->prepare(
                        "SELECT token, sent_at, used_at, sending_at
                         FROM vote_tokens
                         WHERE meeting_id = ?
                           AND erf_number = ?"
                    );
                    $tstmt->execute([$meetingId, $erf]);
                    $vt = $tstmt->fetch(PDO::FETCH_ASSOC);

                    if (
                        $vt &&
                        (
                            $vt['used_at'] !== null ||
                            ($mode === 'unsent' && $vt['sent_at'] !== null) ||
                            $vt['sending_at'] !== null
                        )
                    ) {
                        // Already used, already sent in unsent mode,
                        // or another attempt owns the sending claim.
                        $tokenReady = false;

                    } else {

                        if ($vt) {
                            $token = $vt['token'];

                        } else {
                            // Generate a six-digit token.
                            // The database unique indexes protect
                            // both meeting/erf and meeting/token.
                            for ($attempt = 0; $attempt < 10; $attempt++) {
                                $candidate = (string) random_int(100000, 999999);

                                try {
                                    $insertStmt = $pdo->prepare(
                                        "INSERT INTO vote_tokens
                                            (meeting_id, erf_number, token)
                                         VALUES (?, ?, ?)"
                                    );
                                    $insertStmt->execute([
                                        $meetingId,
                                        $erf,
                                        $candidate
                                    ]);

                                    $token = $candidate;
                                    break;

                                } catch (PDOException $e) {
                                    // Retry only duplicate-key collisions.
                                    if (
                                        $e->getCode() !== '23000' ||
                                        (int) ($e->errorInfo[1] ?? 0) !== 1062
                                    ) {
                                        throw $e;
                                    }

                                    // Check whether this erf already
                                    // acquired a token.
                                    $existingStmt = $pdo->prepare(
                                        "SELECT token, sent_at, used_at,
                                                sending_at
                                         FROM vote_tokens
                                         WHERE meeting_id = ?
                                           AND erf_number = ?"
                                    );
                                    $existingStmt->execute([
                                        $meetingId,
                                        $erf
                                    ]);

                                    $existing = $existingStmt->fetch(
                                        PDO::FETCH_ASSOC
                                    );

                                    if ($existing) {
                                        if (
                                            $existing['used_at'] !== null ||
                                            ($mode === 'unsent' &&
                                             $existing['sent_at'] !== null) ||
                                            $existing['sending_at'] !== null
                                        ) {
                                            break;
                                        }

                                        $token = $existing['token'];
                                        break;
                                    }

                                    // Otherwise the generated six-digit
                                    // token collided. Try another.
                                }
                            }
                        }

                        if ($token !== null) {
                            $sendingClaim = bin2hex(random_bytes(32));

                            // Claim the token before releasing
                            // the meeting lock.
                            $claimStmt = $pdo->prepare(
                                "UPDATE vote_tokens
                                 SET sending_at = NOW(),
                                     sending_claim = ?
                                 WHERE meeting_id = ?
                                   AND erf_number = ?
                                   AND used_at IS NULL
                                   AND (? = 'all' OR sent_at IS NULL)
                                   AND sending_at IS NULL"
                            );

                            $claimStmt->execute([
                                $sendingClaim,
                                $meetingId,
                                $erf,
                                $mode
                            ]);

                            $tokenReady = (
                                $claimStmt->rowCount() === 1
                            );
                        }
                    }
                }

                // Release the meeting lock before SMTP delivery.
                $pdo->commit();

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Voting token preparation failed for meeting ' .
                    $meetingId . ', erf ' . $erf . ': ' .
                    $e->getMessage()
                );

                $sendErrors[] =
                    $erf . ': token preparation failed. No email attempted.';

                $skip++;
                continue;
            }

            if ($stopSending) {
                $sendErrors[] =
                    'Token sending stopped because the meeting is no longer open.';
                break;
            }

            if (!$tokenReady) {
                $skip++;
                continue;
            }

// ── Email the registered owner (Vote Register email — NOT resident portal email) ──
            $subject = 'Your Voting Token - ' . $meeting['title'];
            $message = "You are eligible to vote in: {$meeting['title']}\n\n"
                     . "Erf Number: {$erf}\n"
                     . "Voting Token: {$token}\n\n"
                     . "Enter your erf number and the 6-digit token above on the voting login page. "
                     . "This token can only be used once, from one device.";

            $html = commsBuildNotifyEmail($o['owner_name'], 'Your Voting Token', $message, $voteLoginUrl, 'Go to Voting Login');

           try {
    $ok = commsSendAndLog(
        $broadcastId,
        'vote',
        $o['owner_email'],
        $o['owner_name'],
        $subject,
        $html,
        null,
        null,
        true
    );
} catch (VotingTokenDeliveryUncertain $e) {
    // SMTP may have accepted the email.
    // Keep the claim to prevent automatic resending.
    error_log(
        'Voting token delivery uncertain for meeting ' .
        $meetingId . ', erf ' . $erf . ': ' . $e->getMessage()
    );

    $sendErrors[] = $erf .
        ': SMTP delivery uncertain. Token blocked from resending; administrator investigation required.';

    $skip++;
    continue;

} catch (Throwable $e) {
    // An unexpected error does not prove that delivery failed.
    // Keep the claim until an administrator investigates.
    error_log(
        'Unexpected voting token delivery error for meeting ' .
        $meetingId . ', erf ' . $erf . ': ' . $e->getMessage()
    );

    $sendErrors[] = $erf .
        ': unexpected delivery error. Token blocked from resending; administrator investigation required.';

    $skip++;
    continue;
}

                                 try {
                if ($ok) {
                    // SMTP accepted the email.
                    // Record success only if this request owns the claim.
                    $finishStmt = db()->prepare(
                        "UPDATE vote_tokens
                         SET sent_at = NOW(),
                             sending_at = NULL,
                             sending_claim = NULL
                         WHERE meeting_id = ?
                           AND erf_number = ?
                           AND sending_claim = ?"
                    );

                    $finishStmt->execute([
                        $meetingId,
                        $erf,
                        $sendingClaim
                    ]);

                    if ($finishStmt->rowCount() === 1) {
                        $sent++;
                    } else {
                        $sendErrors[] = $erf .
                            ': email accepted, but the sending claim was not updated. Administrator investigation required.';
                        $skip++;
                    }

                } else {
                    // Email function reported definite failure.
                    // Release only this request's claim.
                    $releaseStmt = db()->prepare(
                        "UPDATE vote_tokens
                         SET sending_at = NULL,
                             sending_claim = NULL
                         WHERE meeting_id = ?
                           AND erf_number = ?
                           AND sending_claim = ?"
                    );

                    $releaseStmt->execute([
                        $meetingId,
                        $erf,
                        $sendingClaim
                    ]);

                    if ($releaseStmt->rowCount() !== 1) {
                        $sendErrors[] = $erf .
                            ': email failed, but the sending claim could not be released. Administrator investigation required.';
                    } else {
                        $sendErrors[] = $erf . ': email sending failed';
                    }

                    $skip++;
                }

            } catch (Throwable $e) {
                // The database update failed.
                // Do not attempt to resend or forcibly clear the claim.
                error_log(
                    'Voting token database update failed for meeting ' .
                    $meetingId . ', erf ' . $erf . ': ' .
                    $e->getMessage()
                );

                $sendErrors[] = $erf .
                    ': token delivery record could not be updated. Administrator investigation required.';

                $skip++;
                continue;
            }
            
            
        }

        commsBroadcastUpdateCounts($broadcastId, $sent, $skip);
        voteAdminLog('tokens_sent', 'meeting', $meetingId, "Sent: {$sent}, Skipped: {$skip}");

       $msg = "Tokens sent: {$sent}. Not sent or skipped: {$skip}.";

if (!empty($sendErrors)) {
    $msg .= ' Attention required: '
          . implode(' | ', array_slice($sendErrors, 0, 5));

    setFlash('error', $msg);
} else {
    setFlash('success', $msg);
}
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
          Tokens are emailed to the <strong>Owner email from the Vote Register</strong>
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

        <form method="POST" onsubmit="return confirmSend()">
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
           SUM(vc.capture_method IN ('manual', 'owner')) AS manual,
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

// ── Helper: compute PQ-weighted vote counts (PQ is a fraction 0-1, all erven sum to 1) ──
function pqCounts(int $motionId, int $meetingId): array {
    $stmt = db()->prepare(
        "SELECT
           SUM(CASE WHEN vt.vote_option = 'for'     THEN veo.pq_value ELSE 0 END) AS pq_for,
           SUM(CASE WHEN vt.vote_option = 'against' THEN veo.pq_value ELSE 0 END) AS pq_against,
           SUM(CASE WHEN vt.vote_option = 'abstain' THEN veo.pq_value ELSE 0 END) AS pq_abstain,
           SUM(CASE WHEN vt.vote_option = 'noshow'  THEN veo.pq_value ELSE 0 END) AS pq_noshow,
           SUM(veo.pq_value) AS pq_total
         FROM vote_cast vc
         JOIN vote_tally vt ON vt.cast_id = vc.id
         JOIN vote_eligible_owners veo
              ON veo.meeting_id = ? AND veo.erf_number = vc.erf_number
         WHERE vc.motion_id = ?"
    );
    $stmt->execute([$meetingId, $motionId]);
    $r        = $stmt->fetch();
    $pqFor    = round((float)($r['pq_for']     ?? 0), 4);
    $pqAgainst= round((float)($r['pq_against'] ?? 0), 4);
    $pqAbstain= round((float)($r['pq_abstain'] ?? 0), 4);
    $pqNoshow = round((float)($r['pq_noshow']  ?? 0), 4);
    $pqTotal  = round((float)($r['pq_total']   ?? 0), 4);
    $pqFA     = round($pqFor + $pqAgainst, 4);
    $pqResultPct = $pqFA > 0 ? round($pqFor / $pqFA * 100, 4) : 0;
    return compact('pqFor','pqAgainst','pqAbstain','pqNoshow','pqTotal','pqResultPct');
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
          
         <?php if ($isVotingAdministrator && $meeting['status'] !== 'closed'): ?>
          
            <a href="comms_voting.php?action=create&meeting_id=<?= $meetingId ?>" class="btn btn-primary">＋ New Motion</a>
          <?php endif; ?>
        </div>

        <?= getFlash() ?>

        <?php if (empty($motions)): ?>
          <p class="muted-note">No motions yet for this meeting.
          
             <?php if ($isVotingAdministrator && $meeting['status'] !== 'closed'): ?>Click <strong>New Motion</strong> to add agenda items requiring a vote.<?php endif; ?>
             
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
             
                </tr>
              <tr>
                <td colspan="7" style="padding:6px 10px 12px;background:#f8fafc;">
                  <div class="btn-group-sm">
                      
            <?php if ($isVotingAdministrator && $m['status'] === 'draft'): ?>
                    
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
                      
                    <?php if ($isVotingAdministrator): ?>
                    <a href="comms_vote_capture.php?meeting_id=<?= $mt['id'] ?>"
                    class="btn btn-sm btn-blue">✏️ Batch Capture</a>
                    <?php else: ?>
                    
                    <a href="comms_vote_capture.php?meeting_id=<?= $mt['id'] ?>"
                    class="btn btn-sm btn-blue">🔍 Verify Ballots</a>
                    <?php endif; ?>


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
                      <a href="comms_voting.php?action=xlsx&id=<?= $m['id'] ?>&meeting_id=<?= $meetingId ?>" class="btn btn-sm btn-green">⬇ XLSX</a>
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

    // Only Administrators may create motions.
    commsRequireRole('administrator');

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

    // Only Administrators may edit motions.
    commsRequireRole('administrator');

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
// Requires parent meeting to be open.
// ============================================================
if ($action === 'open' && $motionId > 0) {

    // Only Administrators may open motions for voting.
    commsRequireRole('administrator');

    $motion = loadMotion($motionId);
    
    $meetingId = (int)$motion['meeting_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        verifyCsrfToken();

        $pdo = db();

        try {
            $pdo->beginTransaction();

            // Lock the parent meeting first.
            // This prevents the meeting from changing status
            // while the motion is being opened.
            $meetingStmt = $pdo->prepare(
                "SELECT id, status
                 FROM meetings
                 WHERE id = ?
                 FOR UPDATE"
            );

            $meetingStmt->execute([$meetingId]);
            $meeting = $meetingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$meeting) {
                throw new RuntimeException(
                    'The parent meeting could not be found.'
                );
            }

            // Only open meetings may have motions opened.
            if ($meeting['status'] !== 'open') {
                throw new RuntimeException(
                    'This motion cannot be opened because its parent meeting is not open.'
                );
            }

            // Lock the motion and check its current status.
            $motionStmt = $pdo->prepare(
                "SELECT id, title, status, meeting_id
                 FROM motions
                 WHERE id = ?
                   AND meeting_id = ?
                 FOR UPDATE"
            );

            $motionStmt->execute([$motionId, $meetingId]);
            $lockedMotion = $motionStmt->fetch(PDO::FETCH_ASSOC);

            if (!$lockedMotion) {
                throw new RuntimeException(
                    'The motion could not be found.'
                );
            }

            if ($lockedMotion['status'] !== 'draft') {
                throw new RuntimeException(
                    'Only draft motions can be opened.'
                );
            }

            // Open the motion.
            $updateStmt = $pdo->prepare(
                "UPDATE motions
                 SET status = 'open',
                     updated_at = NOW()
                 WHERE id = ?
                   AND meeting_id = ?
                   AND status = 'draft'"
            );

            $updateStmt->execute([$motionId, $meetingId]);

            if ($updateStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The motion could not be opened. Please try again.'
                );
            }

            $pdo->commit();

            // Record the successful action in the audit log.
            try {
                voteAdminLog(
                    'motion_opened',
                    'motion',
                    $motionId,
                    $lockedMotion['title']
                );
            } catch (Throwable $logEx) {
                error_log(
                    'Motion opening audit log failed: ' .
                    $logEx->getMessage()
                );
            }

            setFlash(
                'success',
                'Motion is now open for voting.'
            );

        } catch (RuntimeException $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            setFlash('error', $e->getMessage());

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Motion opening failed for motion ' .
                $motionId . ': ' . $e->getMessage()
            );

            setFlash(
                'error',
                'An unexpected error occurred while opening the motion.'
            );
        }
    }

    header(
        "Location: comms_voting.php?action=list&meeting_id={$meetingId}"
    );
    exit;
}

// ============================================================
// DELETE — draft only
// ============================================================
if ($action === 'delete' && $motionId > 0) {

    // Only Administrators may delete motions.
    commsRequireRole('administrator');

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        if ($motion['status'] !== 'draft') {
            setFlash('error', 'Only draft motions can be deleted.');
        } else {
            db()->prepare("DELETE FROM motions WHERE id=? AND status='draft'")->execute([$motionId]);
            setFlash('success', 'Motion deleted.');
            voteAdminLog('motion_deleted', 'motion', $motionId);
        }
    }
    header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
}

// ============================================================
// CLOSE — open → closed + compute result
// ============================================================
if ($action === 'close' && $motionId > 0) {

    // Only Administrators may close motions and compute final results.
    commsRequireRole('administrator');

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();
        if ($motion['status'] !== 'open') {
            setFlash('error', 'Only open motions can be closed.');
            header("Location: comms_voting.php?action=list&meeting_id={$meetingId}"); exit;
        }

                $pdo = db();

        try {
            $pdo->beginTransaction();

            // ============================================================
            // LOCK MEETING FIRST — same order as all vote-capture handlers
            // ============================================================
            $meetingLock = $pdo->prepare(
                "SELECT id, title, status
                 FROM meetings
                 WHERE id = ?
                 FOR UPDATE"
            );

            $meetingLock->execute([$meetingId]);
            $meeting = $meetingLock->fetch(PDO::FETCH_ASSOC);

            if (!$meeting || $meeting['status'] !== 'open') {
                throw new RuntimeException(
                    'The meeting is no longer open. This motion cannot be closed.'
                );
            }

            // ============================================================
            // LOCK MOTION — prevent simultaneous closing or ballot capture
            // ============================================================
            $motionLock = $pdo->prepare(
                "SELECT *
                 FROM motions
                 WHERE id = ?
                   AND meeting_id = ?
                 FOR UPDATE"
            );

            $motionLock->execute([$motionId, $meetingId]);
            $lockedMotion = $motionLock->fetch(PDO::FETCH_ASSOC);

            if (!$lockedMotion || $lockedMotion['status'] !== 'open') {
                throw new RuntimeException(
                    'This motion has already been closed or is no longer open.'
                );
            }

            // Use the locked motion record for all final calculations.
            $motion = $lockedMotion;

            // ============================================================
            // CALCULATE FINAL RESULTS WHILE THE MOTION IS LOCKED
            // ============================================================
            $counts    = voteCounts($motionId);
            $pqData    = pqCounts($motionId, $meetingId);
            $quorum    = checkQuorum($counts, $motion);
            $rt        = RESOLUTION_TYPES[$motion['resolution_type']];
            $threshold = $rt['threshold'];

            $passed = $quorum['met']
                && $counts['resultPct'] >= $threshold;

            $pqPassed = $quorum['met']
                && $pqData['pqResultPct'] >= $threshold;

            // Write result snapshot
            
            
            
            $pdo->prepare(
                "INSERT INTO vote_result
                 (motion_id, total_erven, count_online, count_manual, count_proxy,
                  count_for, count_against, count_abstain, count_noshow, count_blank,
                  physical_present, total_voted,
                  pq_total, pq_for, pq_against, pq_abstain, pq_noshow, pq_result_pct, pq_passed,
                  quorum_met, threshold_pct, result_pct,
                  passed, closed_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
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
                $pqData['pqTotal'],
                $pqData['pqFor'],
                $pqData['pqAgainst'],
                $pqData['pqAbstain'],
                $pqData['pqNoshow'],
                $pqData['pqResultPct'],
                $pqPassed ? 1 : 0,
                $quorum['met'] ? 1 : 0,
                $threshold,
                $counts['resultPct'],
                $passed ? 1 : 0,
                $adminId,
            ]);

                        // Close the motion in the same transaction as its result.
            $closeStmt = $pdo->prepare(
                "UPDATE motions
                 SET status = 'closed',
                     updated_at = NOW()
                 WHERE id = ?
                   AND meeting_id = ?
                   AND status = 'open'"
            );

            $closeStmt->execute([$motionId, $meetingId]);

            if ($closeStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Motion closing failed because its status changed.'
                );
            }

            $pdo->commit();
            $outcome = $passed ? 'PASSED ✅' : 'FAILED ❌';
            setFlash('success', "Voting closed. Motion {$outcome} — see Results for full detail.");
                        
            // Record the closing action without interrupting the certificate.
            try {
                voteAdminLog(
                    'motion_closed',
                    'motion',
                    $motionId,
                    $motion['title']
                );
            } catch (\Throwable $logEx) {
                error_log(
                    'Motion closing audit log failed: ' .
                    $logEx->getMessage()
                );
            }

            // ── Signed result certificate email to primary administrator ──
            try {
                $primaryAdmin = db()->query("SELECT email, full_name FROM comms_users ORDER BY id ASC LIMIT 1")->fetch();
                if ($primaryAdmin && filter_var($primaryAdmin['email'], FILTER_VALIDATE_EMAIL)) {
                    $meetingTitle  = $meeting['title'] ?? 'Meeting';
                    $motionTitle   = $motion['title'] ?? '';
                    $motionNum     = (int)$motion['motion_number'];
                    $closedBy      = commsCurrentUser();
                    $closedAt      = date('d M Y H:i:s');
                    $outcomeLabel  = $passed ? 'PASSED' : 'FAILED';
                    $outcomeColor  = $passed ? '#2e7d32' : '#c62828';
                    $pqOutcome     = $pqPassed ? 'PASSED' : 'FAILED';
                    $subject = "Result Certificate — {$meetingTitle} — Motion {$motionNum}: {$outcomeLabel}";
                    $bodyHtml = commsEmailShell('Result Certificate — ' . $meetingTitle,
                        '<p style="color:#333;margin:0 0 12px;">Dear ' . htmlspecialchars($primaryAdmin['full_name']) . ',</p>'
                        . '<p style="color:#333;margin:0 0 16px;">Voting has closed for the following motion. This certificate serves as the official result record.</p>'
                        . '<div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:20px;margin:16px 0;">'
                        . '<table style="width:100%;border-collapse:collapse;font-size:.9rem;">'
                        . '<tr><td style="padding:6px 0;color:#555;width:40%;">Meeting</td><td style="padding:6px 0;font-weight:700;">' . htmlspecialchars($meetingTitle) . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Motion</td><td style="padding:6px 0;font-weight:700;">' . $motionNum . ': ' . htmlspecialchars($motionTitle) . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Resolution type</td><td style="padding:6px 0;">' . htmlspecialchars($rt['label']) . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Threshold required</td><td style="padding:6px 0;">' . $threshold . '%</td></tr>'
                        . '<tr><td colspan="2" style="padding:8px 0;"><hr style="border:none;border-top:1px solid #dee2e6;"></td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Votes For</td><td style="padding:6px 0;">' . $counts['for'] . ' (' . number_format($counts['resultPct'], 2) . '%)</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Votes Against</td><td style="padding:6px 0;">' . $counts['against'] . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Abstain</td><td style="padding:6px 0;">' . $counts['abstain'] . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Online / Manual / Proxy</td><td style="padding:6px 0;">' . $counts['online'] . ' / ' . $counts['manual'] . ' / ' . $counts['proxy'] . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Physical present</td><td style="padding:6px 0;">' . $counts['physical'] . ' (min ' . $motion['quorum_physical'] . ')</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Total voted</td><td style="padding:6px 0;">' . $counts['total'] . ' (min ' . $motion['quorum_total'] . ')</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">Quorum met</td><td style="padding:6px 0;">' . ($quorum['met'] ? 'Yes' : 'No') . '</td></tr>'
                        . '<tr><td colspan="2" style="padding:8px 0;"><hr style="border:none;border-top:1px solid #dee2e6;"></td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">PQ For</td><td style="padding:6px 0;">' . number_format($pqData['pqFor'], 4) . ' (' . number_format($pqData['pqResultPct'], 2) . '%)</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">PQ Against</td><td style="padding:6px 0;">' . number_format($pqData['pqAgainst'], 4) . '</td></tr>'
                        . '<tr><td style="padding:6px 0;color:#555;">PQ Result</td><td style="padding:6px 0;font-weight:700;color:' . $outcomeColor . ';">' . $pqOutcome . '</td></tr>'
                        . '</table></div>'
                        . '<div style="text-align:center;padding:20px;border-radius:8px;background:' . $outcomeColor . ';margin:16px 0;">'
                        . '<div style="font-size:1.8rem;font-weight:900;color:#fff;">MOTION ' . $outcomeLabel . '</div>'
                        . '<div style="color:rgba(255,255,255,.85);font-size:.9rem;margin-top:4px;">' . number_format($counts['resultPct'], 2) . '% For (' . $threshold . '% required)</div>'
                        . '</div>'
                        . '<p style="font-size:.82rem;color:#888;margin-top:16px;">Closed by: ' . htmlspecialchars($closedBy) . ' &mdash; ' . $closedAt . '<br>'
                        . 'This certificate was generated automatically by GEMB at the moment voting closed. It constitutes the official result record for this motion.</p>'
                    );
                    commsSendEmail($primaryAdmin['email'], $subject, $bodyHtml);
                }
            } catch (\Throwable $certEx) {
                error_log('Result certificate email failed: ' . $certEx->getMessage());
            }
        }        catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Motion closing failed for motion ' .
                $motionId . ': ' . $e->getMessage()
            );

            if ($e instanceof RuntimeException) {
                setFlash('error', $e->getMessage());
            } else {
                setFlash(
                    'error',
                    'Error computing the final result. Please try again.'
                );
            }
        }
        
    }
    header("Location: comms_voting.php?action=results&id={$motionId}&meeting_id={$meetingId}"); exit;
}

// ============================================================
// PROXIES_SAMPLE_CSV — download sample proxy CSV
// ============================================================
if ($action === 'proxies_sample_csv' && $motionId > 0) {
    $filename = 'proxy_register_sample.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($fh, ['erf_owner', 'name_proxy', 'erf_proxy', 'form_reference', 'notes']);
    fputcsv($fh, ['E15227', 'Jane Smith',    'E15228', 'PROXY-2026-001', 'Submitted 20 Jun']);
    fputcsv($fh, ['E15229', 'Robert Johnson','',       'PROXY-2026-002', '']);
    fputcsv($fh, ['E15230', 'Mary Williams', 'E15231', 'PROXY-2026-003', 'Email received']);
    fclose($fh);
    exit;
}

// ============================================================
// PROXIES_SAMPLE_XLSX — download sample proxy XLSX
// ============================================================
if ($action === 'proxies_sample_xlsx' && $motionId > 0) {
    if (!class_exists('Shuchkin\SimpleXLSXGen')) {
        setFlash('error', 'Excel generation library (SimpleXLSXGen.php) is not installed. Please use the CSV sample instead.');
        header("Location: comms_voting.php?action=proxies&id={$motionId}&meeting_id={$meetingId}"); exit;
    }
    $rows = [
        ['erf_owner', 'name_proxy',     'erf_proxy', 'form_reference',  'notes'],
        ['E15227',    'Jane Smith',      'E15228',    'PROXY-2026-001',  'Submitted 20 Jun'],
        ['E15229',    'Robert Johnson',  '',          'PROXY-2026-002',  ''],
        ['E15230',    'Mary Williams',   'E15231',    'PROXY-2026-003',  'Email received'],
    ];
    $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($rows, 'Proxy Register');
    $xlsx->downloadAs('proxy_register_sample.xlsx');
    exit;
}

// ============================================================
// PROXIES — register and view proxy votes
// ============================================================
if ($action === 'proxies' && $motionId > 0) {

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        commsRequireRole('administrator');
        verifyCsrfToken();

        // ── Delete a single proxy ─────────────────────────
        if (isset($_POST['delete_proxy_id'])) {
            $deleteId = (int)$_POST['delete_proxy_id'];
            if ($deleteId) {
                $row = db()->prepare("SELECT erf_number FROM vote_proxy WHERE id=? AND motion_id=? LIMIT 1");
                $row->execute([$deleteId, $motionId]);
                $drow = $row->fetch();
                if ($drow) {
                    db()->prepare("DELETE FROM vote_proxy WHERE id=? AND motion_id=?")->execute([$deleteId, $motionId]);
                    voteAdminLog('proxy_deleted', 'motion', $motionId, 'Proxy deleted for Erf ' . $drow['erf_number']);
                    setFlash('success', 'Proxy for Erf ' . htmlspecialchars($drow['erf_number']) . ' removed.');
                } else {
                    setFlash('error', 'Proxy record not found.');
                }
            }
            header("Location: comms_voting.php?action=proxies&id={$motionId}&meeting_id={$meetingId}"); exit;
        }

        // ── Register a new proxy ──────────────────────────
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
          
         <?php if ($isVotingAdministrator && $motion['status'] === 'open'): ?>
         
          <a href="comms_voting.php?action=proxies_import&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
             class="btn btn-sm btn-amber">📥 Import Proxies</a>
          <?php endif; ?>
          <?php if (!empty($proxies)): ?>
          <a href="comms_voting.php?action=proxies_export&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
             class="btn btn-sm btn-green">⬇ Export Proxies</a>
          <?php endif; ?>
          <a href="comms_voting.php?action=proxies_sample_xlsx&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
             class="btn btn-sm btn-secondary">⬇ Sample XLSX</a>
          <a href="comms_voting.php?action=proxies_sample_csv&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
             class="btn btn-sm btn-secondary">⬇ Sample CSV</a>
        </div>
      </div>

      <!-- Existing proxies -->
      <?php if (!empty($proxies)): ?>
      <div class="card card-wide" style="margin-bottom:16px;">
        <h3 class="section-title">Registered Proxies</h3>
        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr><th>Owner Erf</th><th>Proxy Name</th><th>Proxy Erf</th><th>Form Ref</th><th>Captured</th><th>Notes</th><th></th></tr>
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
                <td>
                    
                 <?php if ($isVotingAdministrator && $motion['status'] === 'open'): ?>
                 
                  <form method="POST" style="display:inline;"
                        onsubmit="return confirm('Remove proxy for Erf <?= htmlspecialchars($p['erf_number']) ?>?')">
                    <?= csrfField() ?>
                    
                    <input type="hidden" name="delete_proxy_id" value="<?= (int)$p['id'] ?>">
                    
                    <button type="submit" class="btn btn-sm btn-red">Remove</button>
                  </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

     <!-- Register new proxy -->
<?php if ($isVotingAdministrator && $motion['status'] === 'open'): ?>

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
              <label>Owner Erf <span class="required">*</span>
                <span class="muted-note">(erf granting the proxy)</span>
              </label>
              <input type="text" name="erf_number" style="text-transform:uppercase;"
                     value="<?= htmlspecialchars(strtoupper($ppost['erf_number'] ?? '')) ?>"
                     placeholder="e.g. E15227" maxlength="20">
            </div>
            <div class="form-group">
              <label>Proxy Name <span class="required">*</span></label>
              <input type="text" name="proxy_holder" maxlength="100"
                     value="<?= htmlspecialchars($ppost['proxy_holder'] ?? '') ?>"
                     placeholder="Full name of person holding proxy">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Proxy Erf <span class="muted-note">(if proxy holder is also an owner)</span></label>
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
// PROXIES_IMPORT — batch import proxy registrations from file
// ============================================================
if ($action === 'proxies_import' && $motionId > 0) {
    
    // Only Administrators may access proxy imports at this stage.
    // Meeting-assigned Assistants will be authorised separately.
    commsRequireRole('administrator');

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];

    if ($motion['status'] !== 'open') {
        setFlash('error', 'Proxies can only be imported for open motions.');
        header("Location: comms_voting.php?action=proxies&id={$motionId}&meeting_id={$meetingId}"); exit;
    }

    $importErrors  = [];
    $importResults = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        if (empty($_FILES['proxy_file']['tmp_name']) || $_FILES['proxy_file']['error'] !== UPLOAD_ERR_OK) {
            $importErrors[] = 'No file uploaded.';
        } else {
            $ext = gembUploadExtension($_FILES['proxy_file']);
            if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
                $importErrors[] = 'Please upload a .csv, .txt, or .xlsx file.';
            } else {
                $fileResult = gembReadImportFile($_FILES['proxy_file']['tmp_name'], $ext);
                if (!$fileResult['ok']) {
                    $importErrors[] = $fileResult['error'];
                } else {
                    $added      = 0;
                    $skipped    = 0;  // already registered
                    $alreadyVoted = 0; // erf has cast a vote
                    $invalid    = 0;  // missing required fields
                    $rowErrors  = [];

                    foreach ($fileResult['rows'] as $rowNum => $raw) {
                        // ── ERF (required) ────────────────────────────
                        $erf = '';
                        foreach ($raw as $k => $v) {
                            if (in_array(strtolower((string)$k), ['erf_owner','erf','erf_number','erfno','erf number','erf no'], true)) {
                                $erf = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$v));
                                break;
                            }
                        }
                        // Fallback: first column
                        if ($erf === '') {
                            $first = array_values($raw)[0] ?? '';
                            $erf   = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$first));
                        }

                        // ── PROXY HOLDER NAME (required) ──────────────
                        $holder = '';
                        foreach ($raw as $k => $v) {
                            if (in_array(strtolower((string)$k), ['name_proxy','proxy_holder','proxy holder','holder','holder name','name','proxy_name','proxyname'], true)) {
                                $holder = trim((string)$v);
                                break;
                            }
                        }
                        // Fallback: second column
                        if ($holder === '') {
                            $cols   = array_values($raw);
                            $holder = trim((string)($cols[1] ?? ''));
                        }

                        if ($erf === '' || $holder === '') {
                            $invalid++;
                            $rowErrors[] = 'Row ' . ($rowNum + 1) . ': missing ERF or proxy holder name — skipped.';
                            continue;
                        }

                        // ── OPTIONAL FIELDS ───────────────────────────
                        $holderErf = '';
                        $formRef   = '';
                        $notes     = '';

                        foreach ($raw as $k => $v) {
                            $kl = strtolower((string)$k);
                            if (in_array($kl, ['erf_proxy','proxy_holder_erf','holder_erf','holder erf','holdererf'], true))
                                $holderErf = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$v));
                            if (in_array($kl, ['form_reference','form reference','formref','form ref','reference','ref'], true))
                                $formRef = trim((string)$v);
                            if ($kl === 'notes')
                                $notes = trim((string)$v);
                        }
                        // Fallback: positional cols 3, 4, 5
                        if ($holderErf === '' && $formRef === '') {
                            $cols = array_values($raw);
                            if (isset($cols[2])) $holderErf = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$cols[2]));
                            if (isset($cols[3])) $formRef   = trim((string)$cols[3]);
                            if (isset($cols[4])) $notes     = trim((string)$cols[4]);
                        }

                        // ── GUARD: erf must be in eligible owners ─────
                        $eligible = db()->prepare(
                            "SELECT id FROM vote_eligible_owners WHERE meeting_id=? AND erf_number=? LIMIT 1"
                        );
                        $eligible->execute([$meetingId, $erf]);
                        if (!$eligible->fetch()) {
                            $invalid++;
                            $rowErrors[] = "Row " . ($rowNum + 1) . ": Erf {$erf} is not in the Vote Register — skipped.";
                            continue;
                        }

                        // ── GUARD: erf must not have already voted ────
                        $voted = db()->prepare(
                            "SELECT id FROM vote_cast WHERE motion_id=? AND erf_number=? LIMIT 1"
                        );
                        $voted->execute([$motionId, $erf]);
                        if ($voted->fetch()) {
                            $alreadyVoted++;
                            $rowErrors[] = "Row " . ($rowNum + 1) . ": Erf {$erf} has already cast a vote — proxy cannot be registered.";
                            continue;
                        }

                        // ── INSERT (skip duplicate silently) ──────────
                        try {
                            db()->prepare(
                                "INSERT INTO vote_proxy
                                 (motion_id, erf_number, proxy_holder, proxy_holder_erf,
                                  form_reference, captured_by, notes)
                                 VALUES (?,?,?,?,?,?,?)"
                            )->execute([
                                $motionId,
                                $erf,
                                $holder,
                                $holderErf ?: null,
                                $formRef   ?: null,
                                $adminId,
                                ($notes ?: 'Batch import'),
                            ]);
                            $added++;
                        } catch (\Throwable $e) {
                            // Unique key violation — already registered
                            $skipped++;
                        }
                    }

                    // ── Audit log ─────────────────────────────────────
                    if ($added > 0) {
                        voteAdminLog(
                            'proxies_imported',
                            'motion',
                            $motionId,
                            "{$added} proxies imported from file for motion {$motionId}"
                        );
                    }

                    $importResults = compact('added', 'skipped', 'alreadyVoted', 'invalid', 'rowErrors');
                }
            }
        }
    }

    pageHeader('Import Proxies', 'admin');
    renderHeader('📥 Import Proxies', 'comms.php');
    ?>

    <main class="pc-main">
      <div class="card card-wide">

        <h2 class="card-title">
          📥 Import Proxies — Motion <?= (int)$motion['motion_number'] ?>:
          <?= htmlspecialchars($motion['title']) ?>
        </h2>

        <?php if ($importResults !== null): ?>
          <?php
          $parts = [];
          if ($importResults['added'])       $parts[] = '<strong>' . $importResults['added']       . '</strong> registered';
          if ($importResults['skipped'])     $parts[] = '<strong>' . $importResults['skipped']     . '</strong> already registered (skipped)';
          if ($importResults['alreadyVoted'])$parts[] = '<strong>' . $importResults['alreadyVoted'] . '</strong> erf(s) already voted (skipped)';
          if ($importResults['invalid'])     $parts[] = '<strong>' . $importResults['invalid']     . '</strong> invalid/missing data (skipped)';
          $alertClass = $importResults['added'] > 0 ? 'alert-success' : 'alert-danger';
          ?>
          <div class="alert <?= $alertClass ?>" style="margin-bottom:16px;">
            Import complete: <?= implode(' &nbsp;·&nbsp; ', $parts) ?>.
          </div>
          <?php if (!empty($importResults['rowErrors'])): ?>
          <div class="alert alert-info" style="font-size:.83rem;margin-bottom:16px;">
            <strong>Row detail:</strong><br>
            <?php foreach ($importResults['rowErrors'] as $re): ?>
              <?= htmlspecialchars($re) ?><br>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($importResults['added'] > 0): ?>
          <div class="btn-group" style="margin-bottom:20px;">
            <a href="comms_voting.php?action=proxies&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
               class="btn btn-primary">← View Proxy Register</a>
          </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($importErrors)): ?>
          <div class="alert alert-danger" style="margin-bottom:16px;">
            <?php foreach ($importErrors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="alert alert-info" style="font-size:.88rem;margin-bottom:20px;">
          <strong>Accepted formats: .xlsx (Excel) or .csv</strong><br><br>
          <strong>Columns — header names are flexible:</strong><br>
          <code>erf_owner</code> <strong>(required)</strong> — erf of the owner granting the proxy<br>
          <code>name_proxy</code> <strong>(required)</strong> — full name of the person holding the proxy<br>
          <code>erf_proxy</code> (optional) — erf of the proxy holder if also an owner<br>
          <code>form_reference</code> (optional) — paper form number for audit trail<br>
          <code>notes</code> (optional)<br><br>
          If no header row is present, columns are read positionally in the order above.<br>
          Each erf must be in the Vote Register for this meeting.
          Erven that have already voted or already have a proxy registered are skipped.
        </div>

        <form method="POST" enctype="multipart/form-data">
          <?= csrfField() ?>
          <div class="form-group">
            <label>Proxy Register File (Excel or CSV)</label>
            <input type="file" name="proxy_file" accept=".csv,.txt,.xlsx" required>
          </div>
          <div class="btn-group">
            <button type="submit" class="btn btn-primary">📥 Import Proxies</button>
            <a href="comms_voting.php?action=proxies_sample_xlsx&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
               class="btn btn-secondary">⬇ Sample XLSX</a>
            <a href="comms_voting.php?action=proxies_sample_csv&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
               class="btn btn-secondary">⬇ Sample CSV</a>
            <a href="comms_voting.php?action=proxies&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>"
               class="btn btn-navy">← Back to Proxy Register</a>
          </div>
        </form>

      </div>
    </main>

    <?php pageFooter(); exit;
}

// ============================================================
// PROXIES_EXPORT — download proxy register as CSV
// ============================================================
if ($action === 'proxies_export' && $motionId > 0) {

    // Restrict proxy register exports to authorised oversight roles.
    commsRequireRole('administrator', 'supervisor');

    $motion    = loadMotion($motionId);
    
    $meetingId = (int)$motion['meeting_id'];
    $meeting   = loadMeeting($meetingId);

    $rows = db()->prepare(
        "SELECT
           vp.erf_number        AS erf_owner,
           veo.owner_name       AS name_owner,
           vp.proxy_holder      AS name_proxy,
           vp.proxy_holder_erf  AS erf_proxy,
           vp.form_reference,
           vp.notes,
           vp.captured_at
         FROM vote_proxy vp
         LEFT JOIN vote_eligible_owners veo
               ON veo.meeting_id = ? AND veo.erf_number = vp.erf_number
         WHERE vp.motion_id = ?
         ORDER BY vp.erf_number"
    );
    $rows->execute([$meetingId, $motionId]);
    $proxies = $rows->fetchAll();

    $safeTitle  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $meeting['title']);
    $filename   = 'proxies_motion_' . (int)$motion['motion_number'] . '_' . $safeTitle . '_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');

    $fh = fopen('php://output', 'w');
    fprintf($fh, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

    fputcsv($fh, [
        'erf_owner', 'name_owner', 'name_proxy', 'erf_proxy',
        'form_reference', 'notes', 'captured_at'
    ]);

    foreach ($proxies as $p) {
        fputcsv($fh, [
            $p['erf_owner'],
            $p['name_owner']      ?? '',
            $p['name_proxy']      ?? '',
            $p['erf_proxy']       ?? '',
            $p['form_reference']  ?? '',
            $p['notes']           ?? '',
            $p['captured_at']     ?? '',
        ]);
    }

    fclose($fh);
    voteAdminLog('proxies_exported', 'motion', $motionId,
        count($proxies) . ' proxies exported for motion ' . $motion['motion_number']);
    exit;
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
        commsRequireRole('administrator');
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
        
        

        // Proxy registration is verified inside the transaction,
        // after the meeting and motion have been locked.
            $proxyId = null;
        
        

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

// Lock the parent meeting first.
// This matches the lock order used by comms_vote_capture.php.
$meetingLock = $pdo->prepare(
    "SELECT status
     FROM meetings
     WHERE id = ?
     FOR UPDATE"
);

$meetingLock->execute([$meetingId]);

if ($meetingLock->fetchColumn() !== 'open') {
    throw new RuntimeException(
        'Voting is blocked because the meeting is no longer open.'
    );
}

// Lock the motion before accepting the ballot.
$motionLock = $pdo->prepare(
    "SELECT status
     FROM motions
     WHERE id = ?
       AND meeting_id = ?
     FOR UPDATE"
);

$motionLock->execute([$motionId, $meetingId]);

if ($motionLock->fetchColumn() !== 'open') {
    throw new RuntimeException(
        'Voting is blocked because this motion is no longer open.'
    );
}

// Verify the erf belongs to the current Vote Register.
$registerCheck = $pdo->prepare(
    "SELECT id
     FROM vote_eligible_owners
     WHERE meeting_id = ?
       AND erf_number = ?
     LIMIT 1
     FOR UPDATE"
);

$registerCheck->execute([$meetingId, $erf]);

if (!$registerCheck->fetchColumn()) {
    throw new RuntimeException(
        'This erf is not in the current Vote Register.'
    );
}

// Recheck for an existing ballot while holding the locks.
$existingCheck = $pdo->prepare(
    "SELECT id
     FROM vote_cast
     WHERE motion_id = ?
       AND erf_number = ?
     LIMIT 1
     FOR UPDATE"
);

$existingCheck->execute([$motionId, $erf]);

if ($existingCheck->fetchColumn()) {
    throw new RuntimeException(
        'A ballot has already been recorded for this erf.'
    );
}

// Verify the proxy appointment inside the transaction.
// This prevents a proxy registration from being changed
// between validation and ballot capture.
$proxyId = null;

if ($method === 'proxy') {

    $proxyCheck = $pdo->prepare(
        "SELECT id
         FROM vote_proxy
         WHERE motion_id = ?
           AND erf_number = ?
         LIMIT 1
         FOR UPDATE"
    );

    $proxyCheck->execute([$motionId, $erf]);

    $proxyId = $proxyCheck->fetchColumn();

    if (!$proxyId) {
        throw new RuntimeException(
            'No proxy is registered for Erf ' . $erf .
            '. Register the proxy before capturing its vote.'
        );
    }

    $proxyId = (int)$proxyId;
}

// Insert the ballot only after all safeguards pass.
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
                    voteAdminLog('vote_captured', 'motion', $motionId, "Erf {$erf} — " . strtoupper($vote) . " (" . ucfirst($method) . ")");
                    header("Location: comms_voting.php?action=capture&id={$motionId}&meeting_id={$meetingId}"); exit;


                } catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Display specific validation errors.
    if ($e instanceof RuntimeException) {
        setFlash('error', $e->getMessage());
        header(
            "Location: comms_voting.php?action=capture&id={$motionId}&meeting_id={$meetingId}"
        );
        exit;
    }

    // Handle duplicate ballots.
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
          <a href="comms_voting.php?action=xlsx&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>" class="btn btn-green">⬇ Download XLSX</a>
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

      <!-- PQ Weighted Result Panel -->
      <?php
        if ($motion['status'] === 'closed' && $result) {
            $pqDisplay = ['pqFor'=>(float)$result['pq_for'],'pqAgainst'=>(float)$result['pq_against'],
                'pqAbstain'=>(float)$result['pq_abstain'],'pqNoshow'=>(float)$result['pq_noshow'],
                'pqTotal'=>(float)$result['pq_total'],'pqResultPct'=>(float)$result['pq_result_pct']];
        } else {
            $pqDisplay = pqCounts($motionId, $meetingId);
        }
        $pqPanelPassed = $quorum['met'] && $pqDisplay['pqResultPct'] >= $rt['threshold'];
      ?>
      <?php if ($pqDisplay['pqTotal'] > 0): ?>
      <div class="card card-wide" style="margin-bottom:16px;">
        <h3 class="section-title">&#x1F4CA; PQ Weighted Result</h3>
        <p style="font-size:.84rem;color:#666;margin-bottom:12px;">
          Participation Quota weighting reflects the proportional ownership share of each erf.
          Both headcount and PQ results are recorded for auditor verification.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;margin-bottom:14px;">
          <?php
            $pqTiles=[['PQ For',number_format($pqDisplay['pqFor'],4),'#2e7d32'],
                       ['PQ Against',number_format($pqDisplay['pqAgainst'],4),'#c62828'],
                       ['PQ Abstain',number_format($pqDisplay['pqAbstain'],4),'#757575'],
                       ['PQ No Show',number_format($pqDisplay['pqNoshow'],4),'#9e9e9e'],
                       ['PQ Total',number_format($pqDisplay['pqTotal'],4),'#1a237e']];
            foreach($pqTiles as [$lbl,$val,$col]):
          ?>
          <div style="background:#fff;border:2px solid <?=$col?>;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:1.2rem;font-weight:800;color:<?=$col?>;"><?=$val?></div>
            <div style="font-size:.74rem;color:#666;"><?=$lbl?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($pqDisplay['pqFor'] + $pqDisplay['pqAgainst'] > 0): ?>
        <div style="margin-bottom:8px;">
          <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:4px;">
            <span style="color:#2e7d32;font-weight:700;">PQ For: <?=number_format($pqDisplay['pqResultPct'],2)?>%</span>
            <span style="color:#c62828;font-weight:700;">PQ Against: <?=number_format(100-$pqDisplay['pqResultPct'],2)?>%</span>
          </div>
          <div style="background:#f8d7da;border-radius:20px;height:20px;overflow:hidden;">
            <div style="background:#2e7d32;width:<?=min(100,$pqDisplay['pqResultPct'])?>%;height:100%;border-radius:20px;"></div>
          </div>
        </div>
        <div style="padding:10px 14px;border-radius:6px;margin-top:8px;background:<?=$pqPanelPassed?'#e8f5e9':'#ffebee'?>;border:1px solid <?=$pqPanelPassed?'#a5d6a7':'#f44336'?>;font-size:.9rem;">
          <strong>PQ Result:</strong> <?=$pqPanelPassed?'&#x2705; PASSED':'&#x274C; FAILED'?>
          &mdash; <?=number_format($pqDisplay['pqResultPct'],2)?>% For (<?=$rt['threshold']?>% required)
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="card card-wide">
        <div class="btn-group">
          <?php if ($motion['status'] === 'closed'): ?>
            <a href="comms_voting.php?action=xlsx&id=<?= $motionId ?>&meeting_id=<?= $meetingId ?>" class="btn btn-green">⬇ Download XLSX</a>
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

    fwrite($out, csvVoteRow(['GEMB HOA — Vote Result Certificate']));
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
    fwrite($out, csvVoteRow([]));
    fwrite($out, csvVoteRow(['PQ WEIGHTED RESULT', '', '']));
    fwrite($out, csvVoteRow(['PQ For',         number_format((float)$result['pq_for'],4),        '']));
    fwrite($out, csvVoteRow(['PQ Against',     number_format((float)$result['pq_against'],4),    '']));
    fwrite($out, csvVoteRow(['PQ Abstain',     number_format((float)$result['pq_abstain'],4),    '(excluded)']));
    fwrite($out, csvVoteRow(['PQ No Show',     number_format((float)$result['pq_noshow'],4),     '(excluded)']));
    fwrite($out, csvVoteRow(['PQ Total Voted', number_format((float)$result['pq_total'],4),      '']));
    fwrite($out, csvVoteRow(['PQ Result %',    number_format((float)$result['pq_result_pct'],4).'%', 'PQ For / (PQ For + PQ Against)']));
    fwrite($out, csvVoteRow(['PQ Threshold',   $result['threshold_pct'].'%',                     '']));
    fwrite($out, csvVoteRow(['PQ MOTION',      $result['pq_passed'] ? 'PASSED' : 'FAILED',       '']));

    fclose($out);
    exit;
}

// ============================================================
// XLSX — download results as Excel
// ============================================================
if ($action === 'xlsx' && $motionId > 0) {

    $motion    = loadMotion($motionId);
    $meetingId = (int)$motion['meeting_id'];
    $rt        = RESOLUTION_TYPES[$motion['resolution_type']];

    if (!class_exists('Shuchkin\SimpleXLSXGen')) {
        setFlash('error', 'Excel generation library (SimpleXLSXGen.php) is not installed. Please use the CSV download instead.');
        header("Location: comms_voting.php?action=results&id={$motionId}&meeting_id={$meetingId}"); exit;
    }

    $res = db()->prepare("SELECT * FROM vote_result WHERE motion_id=?");
    $res->execute([$motionId]);
    $result = $res->fetch();

    if (!$result) {
        setFlash('error', 'No result record found. The motion may not be closed yet.');
        header("Location: comms_voting.php?action=results&id={$motionId}&meeting_id={$meetingId}"); exit;
    }

    $forAgainst = $result['count_for'] + $result['count_against'];

    $rows = [
        ['GEMB HOA — Vote Result Certificate', '', ''],
        ['', '', ''],
        ['Motion',          $motion['title'],                                   ''],
        ['Resolution Type', $rt['label'],                                       ''],
        ['Threshold',       $rt['threshold'] . '%',                             ''],
        ['Status',          'Closed',                                           ''],
        ['Closed At',       date('d M Y H:i', strtotime($result['closed_at'])), ''],
        ['Generated',       date('d M Y H:i'),                                  ''],
        ['', '', ''],
        ['QUORUM', '', ''],
        ['Physical present (online + manual)', $result['physical_present'],  'Required: ' . $motion['quorum_physical']],
        ['Total voted (physical + proxy)',     $result['total_voted'],       'Required: ' . $motion['quorum_total']],
        ['Quorum met',                         $result['quorum_met'] ? 'YES' : 'NO', ''],
        ['', '', ''],
        ['VOTE BREAKDOWN', '', ''],
        ['Online votes',  $result['count_online'],  ''],
        ['Manual votes',  $result['count_manual'],  ''],
        ['Proxy votes',   $result['count_proxy'],   ''],
        ['Total cast',    $result['total_voted'],   ''],
        ['', '', ''],
        ['For',           $result['count_for'],     ''],
        ['Against',       $result['count_against'], ''],
        ['Abstain',       $result['count_abstain'], '(excluded from threshold)'],
        ['No Show',       $result['count_noshow'],  '(excluded from threshold)'],
        ['Blank ballots', $result['count_blank'],   '(recorded as abstain)'],
        ['', '', ''],
        ['RESULT', '', ''],
        ['For + Against base', $forAgainst,                              ''],
        ['Result percentage',  $result['result_pct'] . '%',             'For / (For+Against)'],
        ['Required threshold', $result['threshold_pct'] . '%',          ''],
        ['MOTION',             $result['passed'] ? 'PASSED' : 'FAILED', ''],
        ['', '', ''],
        ['PQ WEIGHTED RESULT', '', ''],
        ['PQ For',         number_format((float)$result['pq_for'],4),         ''],
        ['PQ Against',     number_format((float)$result['pq_against'],4),     ''],
        ['PQ Abstain',     number_format((float)$result['pq_abstain'],4),     '(excluded)'],
        ['PQ No Show',     number_format((float)$result['pq_noshow'],4),      '(excluded)'],
        ['PQ Total Voted', number_format((float)$result['pq_total'],4),       ''],
        ['PQ Result %',    number_format((float)$result['pq_result_pct'],4) . '%', 'PQ For / (PQ For + PQ Against)'],
        ['PQ Threshold',   $result['threshold_pct'] . '%',                    ''],
        ['PQ MOTION',      $result['pq_passed'] ? 'PASSED' : 'FAILED',        ''],
    ];

    $filename = 'vote_result_' . $motionId . '_' . date('Ymd') . '.xlsx';
    $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($rows, 'Result Certificate');
    $xlsx->downloadAs($filename);
    exit;
}

// ============================================================
// MEETINGS_DELETE_TEST — purge all data for a test meeting
// ============================================================
if ($action === 'meetings_delete_test' && $meetingId > 0) {

    // Only Administrators may delete test meetings.
    commsRequireRole('administrator');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $pdo = db();

        try {
            $pdo->beginTransaction();

            // Lock the meeting before checking whether it can be purged.
            $meetingLock = $pdo->prepare(
                "SELECT id, title, status, is_test
                 FROM meetings
                 WHERE id = ?
                 FOR UPDATE"
            );
            $meetingLock->execute([$meetingId]);
            $meeting = $meetingLock->fetch(PDO::FETCH_ASSOC);

            if (!$meeting) {
                throw new RuntimeException('Meeting not found.');
            }

            // Only test meetings may use this deletion route.
            if (!(int)$meeting['is_test']) {
                throw new RuntimeException(
                    'This is not a test meeting. Use the standard delete function.'
                );
            }

            // Never purge a test meeting while voting is open.
            if ($meeting['status'] === 'open') {
                throw new RuntimeException(
                    'This test meeting is open and cannot be deleted. Close the meeting first.'
                );
            }

            // Allow draft or closed test meetings only.
            if (!in_array($meeting['status'], ['draft', 'closed'], true)) {
                throw new RuntimeException(
                    'This test meeting cannot be deleted in its current status.'
                );
            }

            // Get all motion IDs for this meeting.
            $motionIds = $pdo->prepare(
                "SELECT id FROM motions WHERE meeting_id = ?"
            );
            $motionIds->execute([$meetingId]);
            $mids = $motionIds->fetchAll(PDO::FETCH_COLUMN);

            if ($mids) {
                $in = implode(',', array_fill(0, count($mids), '?'));

                // Delete vote tallies and device response locks.
                $castIds = $pdo->prepare(
                    "SELECT id FROM vote_cast WHERE motion_id IN ({$in})"
                );
                $castIds->execute($mids);
                $cids = $castIds->fetchAll(PDO::FETCH_COLUMN);

                if ($cids) {
                    $cin = implode(',', array_fill(0, count($cids), '?'));

                    $pdo->prepare(
                        "DELETE FROM vote_tally WHERE cast_id IN ({$cin})"
                    )->execute($cids);

                    $pdo->prepare(
                        "DELETE FROM device_response_locks
                         WHERE type = 'vote' AND response_id IN ({$cin})"
                    )->execute($cids);
                }

                $pdo->prepare(
                    "DELETE FROM vote_cast WHERE motion_id IN ({$in})"
                )->execute($mids);

                $pdo->prepare(
                    "DELETE FROM vote_result WHERE motion_id IN ({$in})"
                )->execute($mids);

                $pdo->prepare(
                    "DELETE FROM vote_proxy WHERE motion_id IN ({$in})"
                )->execute($mids);

                $pdo->prepare(
                    "DELETE FROM motions WHERE meeting_id = ?"
                )->execute([$meetingId]);
            }

            $pdo->prepare(
                "DELETE FROM vote_tokens WHERE meeting_id = ?"
            )->execute([$meetingId]);

            $pdo->prepare(
                "DELETE FROM vote_eligible_owners WHERE meeting_id = ?"
            )->execute([$meetingId]);

            $pdo->prepare(
                "DELETE FROM vote_register_log WHERE meeting_id = ?"
            )->execute([$meetingId]);

            $deleteMeeting = $pdo->prepare(
                "DELETE FROM meetings WHERE id = ? AND is_test = 1"
            );
            $deleteMeeting->execute([$meetingId]);

            if ($deleteMeeting->rowCount() !== 1) {
                throw new RuntimeException(
                    'The test meeting could not be deleted.'
                );
            }

            $pdo->commit();

            setFlash(
                'success',
                'Test meeting and all associated data permanently deleted.'
            );

            // Audit logging must not turn a successful deletion
            // into a reported failure.
            try {
                voteAdminLog(
                    'test_meeting_purged',
                    'meeting',
                    $meetingId,
                    $meeting['title']
                );
            } catch (\Throwable $logEx) {
                error_log(
                    'Test meeting deletion audit log failed: ' .
                    $logEx->getMessage()
                );
            }

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof RuntimeException &&
                !($e instanceof PDOException)) {
                setFlash('error', $e->getMessage());
            } else {
                error_log(
                    'Test meeting deletion failed: ' . $e->getMessage()
                );
                setFlash(
                    'error',
                    'Unable to delete the test meeting. Please try again.'
                );
            }
        }
    }

    header('Location: comms_voting.php?action=meetings_list');
    exit;
}

// ============================================================
// MEETINGS_EXPORT — auditor ZIP package for a closed meeting
// ============================================================
if ($action === 'meetings_export' && $meetingId > 0) {

    // Only Administrators and Supervisors may download audit records.
    commsRequireRole('administrator', 'supervisor');

        $meeting = loadMeeting($meetingId);

    // Auditor Exports are permitted only for closed meetings.
    if ($meeting['status'] !== 'closed') {
        setFlash('error', 'Auditor Export is available only for closed meetings.');
        header('Location: comms_voting.php?action=meetings_list');
        exit;
    }

    $safeTitle = preg_replace('/[^A-Za-z0-9_\-]/', '_', $meeting['title']);
    
    $zipName = 'audit_' . $safeTitle . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.zip';
$zipPath = sys_get_temp_dir() . '/' . $zipName;

    function auditCsv(array $rows, array $headers): string {
        $out = implode(',', array_map(fn($h) => '"' . str_replace('"','""',$h) . '"', $headers)) . "\r\n";
        foreach ($rows as $row) {
            $out .= implode(',', array_map(fn($v) => '"' . str_replace('"','""',(string)($v??'')) . '"', $row)) . "\r\n";
        }
        return "\xEF\xBB\xBF" . $out;
    }

    $zip = new ZipArchive();

$zipResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

if ($zipResult !== true) {
    error_log('Auditor Export ZIP creation failed. Code: ' . $zipResult);
    setFlash('error', 'Unable to create the Auditor Export. Please try again.');
    header('Location: comms_voting.php?action=meetings_list');
    exit;
}

    // 1. Voter register
    $rows = db()->prepare("SELECT erf_number, owner_name, owner_email, owner_phone, pq_value FROM vote_eligible_owners WHERE meeting_id=? ORDER BY erf_number");
    $rows->execute([$meetingId]);
    $zip->addFromString('voter_register.csv', auditCsv($rows->fetchAll(PDO::FETCH_NUM), ['erf_owner','name_owner','email_owner','phone_owner','pq']));

    // 2. Token log (no token values — just send/use timestamps)
    $rows = db()->prepare("SELECT vt.erf_number, veo.owner_name, vt.sent_at, vt.used_at, CASE WHEN vt.device_token IS NOT NULL THEN 'Yes' ELSE 'No' END AS device_bound FROM vote_tokens vt LEFT JOIN vote_eligible_owners veo ON veo.meeting_id=vt.meeting_id AND veo.erf_number=vt.erf_number WHERE vt.meeting_id=? ORDER BY vt.erf_number");
    $rows->execute([$meetingId]);
    $zip->addFromString('token_log.csv', auditCsv($rows->fetchAll(PDO::FETCH_NUM), ['Erf','Owner Name','Sent At','Used At','Device Bound']));

    // 3. Participation (no vote choices)
    $rows = db()->prepare("SELECT vc.erf_number, m.motion_number, m.title, vc.capture_method, vc.cast_at, vc.ip_address FROM vote_cast vc JOIN motions m ON m.id=vc.motion_id WHERE m.meeting_id=? ORDER BY m.motion_number, vc.erf_number");
    $rows->execute([$meetingId]);
    $zip->addFromString('participation.csv', auditCsv($rows->fetchAll(PDO::FETCH_NUM), ['Erf','Motion #','Motion Title','Method','Cast At','IP Address']));

    // 4. Results per motion
    $rows = db()->prepare("SELECT m.motion_number, m.title, m.resolution_type, vr.count_for, vr.count_against, vr.count_abstain, vr.count_noshow, vr.physical_present, vr.total_voted, vr.quorum_met, vr.threshold_pct, vr.result_pct, vr.passed, vr.pq_for, vr.pq_against, vr.pq_total, vr.pq_result_pct, vr.pq_passed, vr.closed_at FROM vote_result vr JOIN motions m ON m.id=vr.motion_id WHERE m.meeting_id=? ORDER BY m.motion_number");
    $rows->execute([$meetingId]);
    $zip->addFromString('results.csv', auditCsv($rows->fetchAll(PDO::FETCH_NUM), ['Motion #','Title','Type','For','Against','Abstain','No Show','Physical','Total','Quorum Met','Threshold %','Result %','Passed','PQ For','PQ Against','PQ Total','PQ Result %','PQ Passed','Closed At']));

    // 5. Admin action log
   $rows = db()->prepare(
    "SELECT al.logged_at, al.admin_email, al.action,
            al.entity, al.entity_id, al.detail, al.ip_address
     FROM vote_admin_log AS al
     WHERE (al.entity = 'meeting' AND al.entity_id = ?)
        OR (al.entity = 'motion' AND al.entity_id IN (
            SELECT id FROM motions WHERE meeting_id = ?
        ))
     ORDER BY al.logged_at, al.id"
);
    $rows->execute([$meetingId, $meetingId]);
    $zip->addFromString('admin_log.csv', auditCsv($rows->fetchAll(PDO::FETCH_NUM), ['Logged At','Admin Email','Action','Entity','Entity ID','Detail','IP Address']));

    // 6. Register upload log
    $rows = db()->prepare("SELECT logged_at, admin_email, row_count, file_name, file_hash, ip_address, note FROM vote_register_log WHERE meeting_id=? ORDER BY logged_at");
    $rows->execute([$meetingId]);
    $zip->addFromString('register_upload_log.csv', auditCsv($rows->fetchAll(PDO::FETCH_NUM), ['Logged At','Admin Email','Row Count','File Name','SHA-256 Hash','IP Address','Note']));

    // 7. Cover note
    $cover = "GEMB HOA — Auditor Export Package\r\n";
    $cover .= "Meeting: " . $meeting['title'] . "\r\n";
    $cover .= "Generated: " . date('d M Y H:i:s') . "\r\n";
    $cover .= "Generated by: " . commsCurrentUser() . "\r\n\r\n";
    $cover .= "Files included:\r\n";
    $cover .= "  voter_register.csv       — eligible voters (erf, name, email, PQ)\r\n";
    $cover .= "  token_log.csv            — token send/use timestamps (no token values)\r\n";
    $cover .= "  participation.csv        — who voted on which motion (no vote choices)\r\n";
    $cover .= "  results.csv              — certified result per motion including PQ\r\n";
    $cover .= "  admin_log.csv            — all admin actions for this meeting\r\n";
    $cover .= "  register_upload_log.csv  — register upload history with file hashes\r\n\r\n";
    $cover .= "Note: Individual vote choices are not included. Secret ballot is preserved.\r\n";
    $cover .= "Vote choices are stored separately and reported in aggregate only.\r\n";
    $zip->addFromString('README.txt', $cover);

    if ($zip->close() !== true) {
    error_log('Auditor Export ZIP completion failed for meeting ID: ' . $meetingId);
    setFlash('error', 'Unable to complete the Auditor Export. Please try again.');
    header('Location: comms_voting.php?action=meetings_list');
    exit;
}

// Verify the completed ZIP before recording the export.
clearstatcache(true, $zipPath);

if (!is_file($zipPath) || !is_readable($zipPath) || filesize($zipPath) === 0) {
    error_log('Auditor Export ZIP verification failed for meeting ID: ' . $meetingId);
    setFlash('error', 'The Auditor Export file could not be verified. Please try again.');
    header('Location: comms_voting.php?action=meetings_list');
    exit;
}

voteAdminLog('auditor_export', 'meeting', $meetingId, $meeting['title']);

    // Record that the auditor export has been downloaded — required before delete is permitted
    try {
        db()->prepare(
            "UPDATE meetings SET auditor_export_at = NOW() WHERE id = ? AND auditor_export_at IS NULL"
        )->execute([$meetingId]);
    } catch (\Throwable $e) {
        error_log('auditor_export_at stamp failed: ' . $e->getMessage());
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipPath));
    header('Pragma: no-cache');
    
   if (readfile($zipPath) === false) {
    error_log('Auditor Export ZIP read failed for meeting ID: ' . $meetingId);
}

if (!unlink($zipPath)) {
    error_log('Auditor Export temporary ZIP cleanup failed: ' . $zipPath);
}

exit;
}

header('Location: comms_voting.php?action=meetings_list');
exit;