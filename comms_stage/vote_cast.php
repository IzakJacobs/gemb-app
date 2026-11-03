<?php
// ============================================================
// vote_cast.php — Voting Engine (Token-based access)
// GEMB Access Control System
// Version 2.0  |  2026-06-14
//
// Access: via vote_login.php token entry ONLY. No resident
// portal login required. Session keys:
//   $_SESSION['vote_meeting_id'] — the open meeting's id
//   $_SESSION['vote_erf']        — the erf number voting
//
// Actions:
//   list  — show all open motions for the open meeting (default)
//   vote  — display motion and cast vote (?id=N)
//   done  — confirmation after successful vote
//
// Rules enforced:
//   - Valid vote session required (requireVoteSession)
//   - One vote per erf per motion (DB unique key)
//   - Motion must be 'open' and within date window
//   - Motion must belong to the meeting in the vote session
//   - If a proxy is registered for this erf, voting is blocked
//   - Blank submission auto-recorded as abstain
//   - All output htmlspecialchars() escaped
//   - CSRF token on submission form
//   - Retry-with-backoff on transient DB errors
// ============================================================

// Configure the voting session before layout.php starts it.
ini_set('session.gc_maxlifetime', '10800');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 10800,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// layout.php loads config.php and starts the session.
require_once __DIR__ . '/layout.php';

requireVoteSession();
$_SESSION['vote_last_activity'] = time(); // keep session alive during meeting

// ── Device ID — shared with survey_respond.php via gemb_did cookie ──
if (empty($_COOKIE['gemb_did'])) {
    $deviceId = bin2hex(random_bytes(32));
    setcookie('gemb_did', $deviceId, [
        'expires'  => time() + (5 * 365 * 24 * 3600),
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['gemb_did'] = $deviceId;
}
$deviceId = $_COOKIE['gemb_did'];

$meetingId = (int)$_SESSION['vote_meeting_id'];
$rerf      = strtoupper(trim($_SESSION['vote_erf'] ?? ''));
$action    = $_GET['action'] ?? 'list';
$motionId  = (int)($_GET['id'] ?? 0);

const VOTE_RESOLUTION_TYPES = [
    'ordinary' => ['label' => 'Ordinary Resolution', 'threshold' => 50.01],
    'material' => ['label' => 'Material Resolution',  'threshold' => 60.00],
    'moi'      => ['label' => 'MOI / Condonation',    'threshold' => 75.00],
];

// ── Helper: confirm the session's meeting is still open ────
function loadOpenMeeting(int $meetingId): array {
    $stmt = db()->prepare("SELECT * FROM meetings WHERE id = ? AND status = 'open' LIMIT 1");
    $stmt->execute([$meetingId]);
    $m = $stmt->fetch();
    if (!$m) {
        session_unset(); session_destroy();
        setFlash('error', 'This voting session has ended. The meeting is no longer open.');
        header('Location: vote_login.php?action=login'); exit;
    }
    return $m;
}

$meeting = loadOpenMeeting($meetingId);

// ============================================================
// LIST — open motions for this meeting available to this erf
// ============================================================
if ($action === 'list') {

    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare(
        "SELECT m.*,
                (SELECT COUNT(*) FROM vote_cast vc
                 WHERE vc.motion_id = m.id AND vc.erf_number = ?) AS already_voted,
                (SELECT COUNT(*) FROM vote_proxy vp
                 WHERE vp.motion_id = m.id AND vp.erf_number = ?) AS has_proxy
         FROM motions m
         WHERE m.meeting_id = ?
           AND m.status = 'open'
           AND (m.opens_at  IS NULL OR m.opens_at  <= ?)
           AND (m.closes_at IS NULL OR m.closes_at >= ?)
         ORDER BY m.motion_number ASC"
    );
    $stmt->execute([$rerf, $rerf, $meetingId, $now, $now]);
    $motions = $stmt->fetchAll();

    pageHeader('Voting', 'resident');
    renderHeader('🗳️ ' . htmlspecialchars($meeting['title']) . ' — Erf ' . htmlspecialchars($rerf), 'logout_vote.php');
    ?>

    <div class="container">
      <?= getFlash() ?>

      <?php if (empty($motions)): ?>
        <div class="card">
          <p style="text-align:center;color:#666;padding:20px 0;">
            🗳️ No motions are currently open for voting in this meeting.<br>
            <span style="font-size:.85rem;">Check back later, or contact the HOA Board.</span>
          </p>
        </div>

      <?php else: ?>
        <?php
        // Progress counters
        $totalMotions = count($motions);
        $votedCount   = 0;
        foreach ($motions as $_m) {
            if ((int)$_m['already_voted'] > 0 || (int)$_m['has_proxy'] > 0) $votedCount++;
        }
        $pct = $totalMotions > 0 ? round(($votedCount / $totalMotions) * 100) : 0;
        ?>

        <?php if ($totalMotions > 1): ?>
        <div style="margin-bottom:16px;">
          <div style="font-size:.82rem;color:#64748B;margin-bottom:5px;">
            <?= $votedCount ?> of <?= $totalMotions ?> motion<?= $totalMotions !== 1 ? 's' : '' ?> completed
          </div>
          <div style="background:#DEE2E6;border-radius:4px;height:6px;">
            <div style="background:#0D47A1;width:<?= $pct ?>%;height:6px;border-radius:4px;transition:width .3s;"></div>
          </div>
        </div>
        <?php endif; ?>

        <?php foreach ($motions as $m):
          $rt       = VOTE_RESOLUTION_TYPES[$m['resolution_type']] ?? ['label'=>$m['resolution_type'],'threshold'=>0];
          $voted    = (int)$m['already_voted'] > 0;
          $hasProxy = (int)$m['has_proxy']     > 0;
        ?>

        <?php if ($voted): ?>
        <div style="background:#f0faf0;border:1.5px solid #a5d6a7;border-radius:10px;
                    padding:11px 14px;margin-bottom:10px;
                    display:flex;align-items:center;gap:10px;">
          <div style="width:28px;height:28px;background:#2e7d32;border-radius:50%;
                      display:flex;align-items:center;justify-content:center;
                      font-size:.9rem;color:white;flex-shrink:0;">&#10004;</div>
          <div>
            <div style="font-size:.82rem;font-weight:700;color:#155724;">Vote recorded</div>
            <div style="font-size:.78rem;color:#2e7d32;">Motion <?= (int)$m['motion_number'] ?> &middot; <?= htmlspecialchars($m['title']) ?></div>
          </div>
        </div>

        <?php elseif ($hasProxy): ?>
        <div style="background:#fff8e1;border:1.5px solid #ffe082;border-radius:10px;
                    padding:11px 14px;margin-bottom:10px;
                    display:flex;align-items:center;gap:10px;">
          <div style="width:28px;height:28px;background:#e65100;border-radius:50%;
                      display:flex;align-items:center;justify-content:center;
                      font-size:.9rem;color:white;flex-shrink:0;">&#128203;</div>
          <div>
            <div style="font-size:.82rem;font-weight:700;color:#7d4e00;">Proxy registered</div>
            <div style="font-size:.78rem;color:#e65100;">Motion <?= (int)$m['motion_number'] ?> &middot; <?= htmlspecialchars($m['title']) ?></div>
          </div>
        </div>

        <?php else: ?>
        <div class="card" style="margin-bottom:12px;border:1.5px solid #DEE2E6;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <span style="background:#E3F0FF;color:#0D47A1;font-size:.75rem;font-weight:700;
                         padding:3px 10px;border-radius:12px;">Motion <?= (int)$m['motion_number'] ?></span>
            <?php if ($m['closes_at']): ?>
              <span style="font-size:.75rem;color:#e65100;">&#9200; Closes <?= date('H:i', strtotime($m['closes_at'])) ?></span>
            <?php endif; ?>
          </div>
          <div style="font-size:1rem;font-weight:700;color:#1A1A2E;margin-bottom:3px;line-height:1.35;">
            <?= htmlspecialchars($m['title']) ?>
          </div>
          <div style="font-size:.8rem;color:#64748B;margin-bottom:<?= $m['description'] ? '8px' : '14px' ?>;">
            <?= htmlspecialchars($rt['label']) ?> &nbsp;&middot;&nbsp; <?= $rt['threshold'] ?>% required to pass
          </div>
          <?php if ($m['description']): ?>
            <div style="font-size:.85rem;color:#555;background:#f8f9fa;border-radius:6px;
                        padding:10px 12px;margin-bottom:14px;line-height:1.5;">
              <?= htmlspecialchars($m['description']) ?>
            </div>
          <?php endif; ?>
          <form method="POST" action="vote_cast.php?action=vote&id=<?= $m['id'] ?>">
            <?= csrfField() ?>
            <input type="hidden" name="vote_option" id="vo_<?= $m['id'] ?>" value="">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
              <button type="submit" onclick="document.getElementById('vo_<?= $m['id'] ?>').value='for'"
                style="background:#2e7d32;color:#fff;border:none;border-radius:8px;
                       padding:14px 4px;font-size:.92rem;font-weight:700;cursor:pointer;
                       display:flex;align-items:center;justify-content:center;gap:4px;">
                &#10004; FOR
              </button>
              <button type="submit" onclick="document.getElementById('vo_<?= $m['id'] ?>').value='against'"
                style="background:#c62828;color:#fff;border:none;border-radius:8px;
                       padding:14px 4px;font-size:.92rem;font-weight:700;cursor:pointer;
                       display:flex;align-items:center;justify-content:center;gap:4px;">
                &#10008; AGAINST
              </button>
              <button type="submit" onclick="document.getElementById('vo_<?= $m['id'] ?>').value='abstain'"
                style="background:#546e7a;color:#fff;border:none;border-radius:8px;
                       padding:14px 4px;font-size:.92rem;font-weight:700;cursor:pointer;
                       display:flex;align-items:center;justify-content:center;gap:4px;">
                &mdash; ABSTAIN
              </button>
            </div>
          </form>
        </div>
        <?php endif; ?>

        <?php endforeach; ?>
      <?php endif; ?>
              <?php if ($m['closes_at']): ?>
                <div style="font-size:.78rem;color:#e65100;">
                  ⏰ Voting closes <?= date('d M Y H:i', strtotime($m['closes_at'])) ?>
                </div>
              <?php endif; ?>
            </div>
            <div style="text-align:right;">
              <?php if ($voted): ?>
                <span class="badge badge-success" style="padding:8px 14px;font-size:.85rem;">
                  ✔ Vote Cast
                </span>
              <?php elseif ($hasProxy): ?>
                <span class="badge badge-warning" style="padding:8px 14px;font-size:.85rem;">
                  📋 Proxy Registered
                </span>
                <div style="font-size:.75rem;color:#666;margin-top:4px;">
                  A proxy has been registered for Erf <?= htmlspecialchars($rerf) ?>.
                </div>
              <?php else: ?>
                <a href="vote_cast.php?action=vote&id=<?= $m['id'] ?>"
                   class="btn btn-primary">
                  Cast Vote →
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>

      <div style="margin-top:8px;">
        <a href="logout_vote.php" class="btn btn-navy">Done — Logout</a>
      </div>
    </div>

    <?php pageFooter(); exit;
}

// ============================================================
// VOTE — display motion and cast vote
// ============================================================
if ($action === 'vote' && $motionId > 0) {

    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare(
        "SELECT * FROM motions
         WHERE id = ? AND meeting_id = ? AND status = 'open'
         AND (opens_at  IS NULL OR opens_at  <= ?)
         AND (closes_at IS NULL OR closes_at >= ?)"
    );
    $stmt->execute([$motionId, $meetingId, $now, $now]);
    $motion = $stmt->fetch();

    if (!$motion) {
        setFlash('error', 'This motion is not currently open for voting.');
        header('Location: vote_cast.php?action=list'); exit;
    }

    $pcheck = db()->prepare("SELECT id FROM vote_proxy WHERE motion_id=? AND erf_number=?");
    $pcheck->execute([$motionId, $rerf]);
    if ($pcheck->fetch()) {
        setFlash('error', 'A proxy has been registered for your erf. You cannot vote electronically.');
        header('Location: vote_cast.php?action=list'); exit;
    }

    $vcheck = db()->prepare("SELECT id FROM vote_cast WHERE motion_id=? AND erf_number=?");
    $vcheck->execute([$motionId, $rerf]);
    if ($vcheck->fetch()) {
        setFlash('error', 'Your erf has already cast a vote for this motion.');
        header('Location: vote_cast.php?action=list'); exit;
    }

    $dcheck = db()->prepare(
        "SELECT response_id FROM device_response_locks
         WHERE type = 'vote' AND target_id = ? AND device_id = ?"
    );
    $dcheck->execute([$motionId, $deviceId]);
    if ($dcheck->fetch()) {
        setFlash('error', 'A vote for this motion has already been submitted from this device.');
        header('Location: vote_cast.php?action=list'); exit;
    }

    $rt = VOTE_RESOLUTION_TYPES[$motion['resolution_type']] ?? ['label'=>$motion['resolution_type'],'threshold'=>0];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        $vote  = trim($_POST['vote_option'] ?? '');
        $blank = 0;

        if ($vote === '' || !in_array($vote, ['for','against','abstain','noshow'])) {
            setFlash('error', 'Please select For, Against, or Abstain before submitting. If you wish to abstain, tap the Abstain button.');
            header("Location: vote_cast.php?action=vote&id={$motionId}"); exit;
        }

        $maxAttempts = 3;
        $attempt     = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                $pdo = db();
$pdo->beginTransaction();

// ============================================================
// LOCK MEETING — must remain open throughout vote submission
// ============================================================
$meetingLock = $pdo->prepare(
    "SELECT status
     FROM meetings
     WHERE id = ?
     FOR UPDATE"
);

$meetingLock->execute([$meetingId]);

if ($meetingLock->fetchColumn() !== 'open') {
    throw new RuntimeException(
        'Voting is closed because the meeting is no longer open.'
    );
}

// ============================================================
// LOCK MOTION — must remain open throughout vote submission
// ============================================================
$motionLock = $pdo->prepare(
    "SELECT status, opens_at, closes_at
     FROM motions
     WHERE id = ?
       AND meeting_id = ?
     FOR UPDATE"
);

$motionLock->execute([$motionId, $meetingId]);
$lockedMotion = $motionLock->fetch(PDO::FETCH_ASSOC);

if (!$lockedMotion || $lockedMotion['status'] !== 'open') {
    throw new RuntimeException(
        'Voting is closed for this motion.'
    );
}

// Recheck the scheduled voting window.
$now = date('Y-m-d H:i:s');

if (
    (!empty($lockedMotion['opens_at']) &&
     $lockedMotion['opens_at'] > $now) ||
    (!empty($lockedMotion['closes_at']) &&
     $lockedMotion['closes_at'] < $now)
) {
    throw new RuntimeException(
        'This motion is outside its scheduled voting period.'
    );
}

// ============================================================
// VERIFY VOTE REGISTER — erf must be eligible
// ============================================================
$registerCheck = $pdo->prepare(
    "SELECT id
     FROM vote_eligible_owners
     WHERE meeting_id = ?
       AND erf_number = ?
     LIMIT 1
     FOR UPDATE"
);

$registerCheck->execute([$meetingId, $rerf]);

if (!$registerCheck->fetchColumn()) {
    throw new RuntimeException(
        'Your erf is not listed in the current Vote Register.'
    );
}

// ============================================================
// VERIFY PROXY — electronically voting is blocked if registered
// ============================================================
$proxyCheck = $pdo->prepare(
    "SELECT id
     FROM vote_proxy
     WHERE motion_id = ?
       AND erf_number = ?
     LIMIT 1
     FOR UPDATE"
);

$proxyCheck->execute([$motionId, $rerf]);

if ($proxyCheck->fetchColumn()) {
    throw new RuntimeException(
        'A proxy is registered for your erf. Online voting is blocked.'
    );
}

// ============================================================
// PREVENT DUPLICATE BALLOTS
// ============================================================
$existingCheck = $pdo->prepare(
    "SELECT id
     FROM vote_cast
     WHERE motion_id = ?
       AND erf_number = ?
     LIMIT 1
     FOR UPDATE"
);

$existingCheck->execute([$motionId, $rerf]);

if ($existingCheck->fetchColumn()) {
    throw new RuntimeException(
        'Your erf has already voted on this motion.'
    );
}

// ============================================================
// PREVENT DUPLICATE DEVICE SUBMISSIONS
// ============================================================
$deviceCheck = $pdo->prepare(
    "SELECT response_id
     FROM device_response_locks
     WHERE type = 'vote'
       AND target_id = ?
       AND device_id = ?
     LIMIT 1
     FOR UPDATE"
);

$deviceCheck->execute([$motionId, $deviceId]);

if ($deviceCheck->fetchColumn()) {
    throw new RuntimeException(
        'A vote for this motion has already been submitted from this device.'
    );
}

// ============================================================
// INSERT BALLOT — only after all safeguards pass
// ============================================================
$ins = $pdo->prepare(
                    "INSERT INTO vote_cast
                     (motion_id, erf_number, capture_method, cast_by_role, cast_by_id, ip_address)
                     VALUES (?,?,'online','resident',0,?)"
                );
                $ins->execute([$motionId, $rerf, $_SERVER['REMOTE_ADDR'] ?? null]);
                $castId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    "INSERT INTO vote_tally (cast_id, vote_option, is_blank) VALUES (?,?,?)"
                )->execute([$castId, $vote, $blank]);

                $deviceInsert = $pdo->prepare(
    "INSERT INTO device_response_locks
     (type, target_id, device_id, response_id)
     VALUES ('vote', ?, ?, ?)"
);

$deviceInsert->execute([
    $motionId,
    $deviceId,
    $castId
]);

if ($deviceInsert->rowCount() !== 1) {
    throw new RuntimeException(
        'The device voting record could not be saved.'
    );
}

                $pdo->commit();

                // ── Voter receipt email (participation only — not vote choice) ──
                try {
                    $veo = db()->prepare(
                        "SELECT owner_name, owner_email FROM vote_eligible_owners
                         WHERE meeting_id = ? AND erf_number = ? LIMIT 1"
                    );
                    $veo->execute([$meetingId, $rerf]);
                    $owner = $veo->fetch();
                    if ($owner && filter_var($owner['owner_email'], FILTER_VALIDATE_EMAIL)) {
                        $motionTitle  = $motion['title'] ?? '';
                        $motionNumber = (int)($motion['motion_number'] ?? 0);
                        $meetingTitle = $meeting['title'] ?? 'Meeting';
                        $castTime     = date('d M Y H:i');
                        $subject = 'Vote Recorded — ' . $meetingTitle;
                        $bodyHtml = commsEmailShell('Vote Receipt — ' . $meetingTitle,
                            '<p style="color:#333;margin:0 0 12px;">Dear ' . htmlspecialchars($owner['owner_name']) . ',</p>'
                            . '<p style="color:#333;margin:0 0 16px;">This is confirmation that your vote has been recorded.</p>'
                            . '<div style="background:#f0f8f0;border:2px solid #2e7d32;border-radius:8px;padding:16px;margin:16px 0;">'
                            . '<div style="font-size:.85rem;color:#555;margin-bottom:4px;">Meeting</div>'
                            . '<div style="font-weight:700;color:#333;margin-bottom:12px;">' . htmlspecialchars($meetingTitle) . '</div>'
                            . '<div style="font-size:.85rem;color:#555;margin-bottom:4px;">Motion ' . $motionNumber . '</div>'
                            . '<div style="font-weight:700;color:#333;margin-bottom:12px;">' . htmlspecialchars($motionTitle) . '</div>'
                            . '<div style="font-size:.85rem;color:#555;margin-bottom:4px;">Erf</div>'
                            . '<div style="font-weight:700;color:#333;margin-bottom:12px;">' . htmlspecialchars($rerf) . '</div>'
                            . '<div style="font-size:.85rem;color:#555;margin-bottom:4px;">Recorded at</div>'
                            . '<div style="font-weight:700;color:#2e7d32;">' . $castTime . '</div>'
                            . '</div>'
                            . '<p style="font-size:.85rem;color:#666;margin:16px 0 0;">'
                            . '&#x1F512; Your vote choice is secret and has not been included in this receipt. '
                            . 'Only the fact that your erf participated has been recorded.'
                            . '</p>'
                        );
                        commsSendEmail($owner['owner_email'], $subject, $bodyHtml);
                    }
                } catch (\Throwable $mailEx) {
                    // Receipt email failure must never block vote confirmation
                    error_log('Vote receipt email failed: ' . $mailEx->getMessage());
                }
                header("Location: vote_cast.php?action=done&id={$motionId}&v=" . urlencode($vote)); exit;

            } catch (\Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Show a clear message for voting validation failures.
    if ($e instanceof RuntimeException) {
        setFlash('error', $e->getMessage());
        header('Location: vote_cast.php?action=list');
        exit;
    }

    if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                    
                    
                    setFlash('error', 'Your erf has already cast a vote for this motion.');
                    header('Location: vote_cast.php?action=list'); exit;
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

                setFlash('error', 'An error occurred while recording your vote. Please try again.');
                header("Location: vote_cast.php?action=vote&id={$motionId}"); exit;
            }
        }
    }

    pageHeader(htmlspecialchars($motion['title']), 'resident');
    renderHeader('🗳️ Cast Your Vote', 'vote_cast.php?action=list');
    ?>

    <div class="container" style="max-width:600px;">

      <div class="card" style="margin-bottom:14px;">
        <div style="font-size:.8rem;color:#999;margin-bottom:2px;">
          Motion <?= (int)$motion['motion_number'] ?> — <?= htmlspecialchars($meeting['title']) ?>
        </div>
        <div style="font-size:1.1rem;font-weight:700;margin-bottom:6px;">
          <?= htmlspecialchars($motion['title']) ?>
        </div>
        <div style="font-size:.82rem;color:#666;margin-bottom:10px;">
          <?= htmlspecialchars($rt['label']) ?>
          &nbsp;·&nbsp; Requires <strong><?= $rt['threshold'] ?>%</strong> For votes to pass
        </div>
        <?php if ($motion['description']): ?>
          <div style="font-size:.9rem;color:#444;padding:12px;background:#f8f9fa;
                      border-radius:6px;border-left:3px solid var(--accent);">
            <?= nl2br(htmlspecialchars($motion['description'])) ?>
          </div>
        <?php endif; ?>
        <?php if ($motion['closes_at']): ?>
          <div style="font-size:.78rem;color:#e65100;margin-top:10px;">
            ⏰ Voting closes <?= date('d M Y H:i', strtotime($motion['closes_at'])) ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3 style="font-size:1rem;font-weight:600;margin-bottom:4px;">
          Erf <?= htmlspecialchars($rerf) ?> — Cast Your Vote
        </h3>
        <p style="font-size:.82rem;color:#666;margin-bottom:16px;">
          Your vote is secret. Only the fact that your erf voted is recorded,
          not how you voted.
        </p>

        <form method="POST" id="voteForm">
          <?= csrfField() ?>

          <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:20px;">

            <label style="display:flex;align-items:center;gap:14px;cursor:pointer;
                           padding:16px 18px;border:2px solid #2e7d32;border-radius:10px;
                           transition:all .15s;"
                   onclick="selectVote(this,'#2e7d32')">
              <input type="radio" name="vote_option" value="for" id="vFor"
                     style="width:20px;height:20px;accent-color:#2e7d32;">
              <div>
                <div style="font-size:1.1rem;font-weight:700;color:#2e7d32;">✔ For</div>
                <div style="font-size:.8rem;color:#555;">I support this motion</div>
              </div>
            </label>

            <label style="display:flex;align-items:center;gap:14px;cursor:pointer;
                           padding:16px 18px;border:2px solid #c62828;border-radius:10px;
                           transition:all .15s;"
                   onclick="selectVote(this,'#c62828')">
              <input type="radio" name="vote_option" value="against" id="vAgainst"
                     style="width:20px;height:20px;accent-color:#c62828;">
              <div>
                <div style="font-size:1.1rem;font-weight:700;color:#c62828;">✗ Against</div>
                <div style="font-size:.8rem;color:#555;">I oppose this motion</div>
              </div>
            </label>

            <label style="display:flex;align-items:center;gap:14px;cursor:pointer;
                           padding:16px 18px;border:2px solid #757575;border-radius:10px;
                           transition:all .15s;"
                   onclick="selectVote(this,'#757575')">
              <input type="radio" name="vote_option" value="abstain" id="vAbstain"
                     style="width:20px;height:20px;accent-color:#757575;">
              <div>
                <div style="font-size:1.1rem;font-weight:700;color:#757575;">— Abstain</div>
                <div style="font-size:.8rem;color:#555;">I choose not to vote for or against</div>
              </div>
            </label>

          </div>

          <div class="popia-notice" style="margin-bottom:16px;">
            🔒 Secret ballot — your identity is recorded separately from your vote choice.
            Your specific vote cannot be linked back to your erf after submission.
          </div>

          <button type="submit" class="btn btn-primary btn-block" id="submitBtn"
                  onclick="return confirmVote()">
            Submit Vote
          </button>
          <a href="vote_cast.php?action=list"
             style="display:block;text-align:center;margin-top:12px;
                    font-size:.85rem;color:var(--muted);">
            Cancel — return to motions list
          </a>
        </form>
      </div>
    </div>

    <script>
    function selectVote(label, color) {
        const radio = label.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;

        document.querySelectorAll('form label').forEach(function(l) {
            l.style.background = '';
            l.style.color = '';
            l.querySelectorAll('div').forEach(function(d) { d.style.color = ''; });
        });
        label.style.background = color;
        label.style.color = '#fff';
        label.querySelectorAll('div').forEach(function(d) { d.style.color = '#fff'; });
    }

    function confirmVote() {
        const selected = document.querySelector('input[name="vote_option"]:checked');
        if (!selected) {
            alert('Please select For, Against, or Abstain.\n\nIf you wish to abstain, tap the Abstain button.');
            return false;
        }
        const label = selected.value.toUpperCase();
        return confirm(
            'You are about to cast your vote as: ' + label + '\n\n' +
            'This cannot be changed after submission.\n\n' +
            'Confirm?'
        );
    }
    </script>

    <?php pageFooter(); exit;
}

// ============================================================
// DONE — confirmation page
// ============================================================
if ($action === 'done' && $motionId > 0) {

    $stmt = db()->prepare("SELECT title, motion_number FROM motions WHERE id = ? AND meeting_id = ?");
    $stmt->execute([$motionId, $meetingId]);
    $motion = $stmt->fetch();

    $vote = $_GET['v'] ?? 'abstain';
    $voteLabel = match($vote) {
        'for'     => ['label' => 'FOR',     'color' => '#2e7d32', 'icon' => '✔'],
        'against' => ['label' => 'AGAINST', 'color' => '#c62828', 'icon' => '✗'],
        'noshow'  => ['label' => 'NO SHOW', 'color' => '#9e9e9e', 'icon' => '—'],
        default   => ['label' => 'ABSTAIN', 'color' => '#757575', 'icon' => '—'],
    };

    $now = date('Y-m-d H:i:s');
    $remaining = db()->prepare(
        "SELECT COUNT(*) FROM motions m
         WHERE m.meeting_id = ? AND m.status = 'open'
           AND (m.opens_at  IS NULL OR m.opens_at  <= ?)
           AND (m.closes_at IS NULL OR m.closes_at >= ?)
           AND NOT EXISTS (
               SELECT 1 FROM vote_cast vc
               WHERE vc.motion_id = m.id AND vc.erf_number = ?
           )"
    );
    $remaining->execute([$meetingId, $now, $now, $rerf]);
    $remainingCount = (int)$remaining->fetchColumn();

    pageHeader('Vote Recorded', 'resident');
    renderHeader('🗳️ Vote Recorded', 'vote_cast.php?action=list');
    ?>

    <div class="container" style="max-width:480px;">
      <div class="card" style="text-align:center;padding:32px 24px;">
        <div style="font-size:3rem;margin-bottom:12px;">🗳️</div>
        <h2 style="margin-bottom:8px;">Vote Recorded</h2>
        <p style="color:#555;margin-bottom:16px;">
          Erf <strong><?= htmlspecialchars($rerf) ?></strong> has voted on:<br>
          <strong>Motion <?= (int)($motion['motion_number'] ?? 0) ?>: <?= htmlspecialchars($motion['title'] ?? '') ?></strong>
        </p>
        <div style="display:inline-block;padding:14px 28px;border-radius:10px;
                    background:<?= $voteLabel['color'] ?>;color:#fff;
                    font-size:1.3rem;font-weight:800;margin-bottom:20px;">
          <?= $voteLabel['icon'] ?> <?= $voteLabel['label'] ?>
        </div>
        <p style="font-size:.85rem;color:#2e7d32;font-weight:700;margin-bottom:8px;">
          ✅ Submission successful — your vote has been saved.
        </p>

        <?php if ($remainingCount > 0): ?>
        <p style="font-size:.85rem;color:#555;margin-bottom:20px;">
          You have <strong><?= $remainingCount ?></strong> more motion<?= $remainingCount === 1 ? '' : 's' ?>
          open for voting in this meeting.
        </p>
        <a href="vote_cast.php?action=list" class="btn btn-primary btn-block">
          Continue to Next Motion
        </a>
        <?php else: ?>
        <p style="font-size:.82rem;color:#555;margin-bottom:24px;">
          You have voted on all motions currently open so far.<br>
          <strong>Please keep this screen open</strong> — additional motions
          may be opened during the meeting. This page will continue to work
          for the full duration of the meeting.<br><br>
          When the meeting is complete, use the button below to log out.
        </p>
        <a href="vote_cast.php?action=list" class="btn btn-primary btn-block" style="margin-bottom:10px;">
          &#8617; Back to Motions List
        </a>
        <a href="logout_vote.php" class="btn btn-navy btn-block">
          Done &mdash; Logout
        </a>
        <?php endif; ?>

        <div class="popia-notice" style="margin-top:20px;">
          Vote data is processed under POPIA §11 for HOA governance purposes.
          Results are reported in aggregate only.
        </div>
      </div>
    </div>

    <?php pageFooter(); exit;
}

header('Location: vote_cast.php?action=list');
exit;

