<?php
// ============================================================
// survey_respond.php — Survey Module (All registered users)
// MBGE Access Control System
// Version 1.0  |  2026-06-13
//
// Actions:
//   list    — show all active surveys (default)
//   take    — display and submit a survey (?id=N)
//   done    — thank-you confirmation after submission
//
// Roles supported (any logged-in user):
//   resident, site_manager (security), guard, admin
//
// Security:
//   requireAnyRole() enforces a valid session at chokepoint
//   CSRF token on submission form
//   One response per user per survey (DB unique key enforced)
//   Survey must be 'active' to accept responses
//   Date window (starts_at / ends_at) enforced server-side
//   All output htmlspecialchars() escaped
// ============================================================

require_once 'layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Role detection — accept any logged-in role ─────────────
function detectRole(): array {
    if (!empty($_SESSION['admin_id']))
        return ['role' => 'admin',
                'id'   => (int)$_SESSION['admin_id'],
                'name' => $_SESSION['admin_name'] ?? 'Admin',
                'erf'  => 'Admin'];

    if (!empty($_SESSION['resident_id']))
        return ['role' => 'resident',
                'id'   => (int)$_SESSION['resident_id'],
                'name' => $_SESSION['resident_name'] ?? 'Resident',
                'erf'  => $_SESSION['resident_erf']  ?? ''];

    if (!empty($_SESSION['security_id']))
        return ['role' => 'site_manager',
                'id'   => (int)$_SESSION['security_id'],
                'name' => $_SESSION['security_name'] ?? 'Site Manager',
                'erf'  => 'Site Manager'];

    if (!empty($_SESSION['guard_id']))
        return ['role' => 'guard',
                'id'   => (int)$_SESSION['guard_id'],
                'name' => $_SESSION['guard_name'] ?? 'Guard',
                'erf'  => 'Guard'];

    return [];
}

$user = detectRole();

if (empty($user)) {
    // Not logged in — redirect to main menu
    header('Location: index.php'); exit;
}

$action   = $_GET['action'] ?? 'list';
$surveyId = (int)($_GET['id'] ?? 0);

// Back URL per role
$backUrl = match($user['role']) {
    'admin'        => 'admin.php?action=menu',
    'site_manager' => 'security.php?action=menu',
    'guard'        => 'guard.php?action=menu',
    default        => 'resident.php?action=menu',
};

// ============================================================
// LIST — active surveys available to this user
// ============================================================
if ($action === 'list') {

    $now = date('Y-m-d H:i:s');

    // Fetch active surveys within date window
    $stmt = db()->prepare(
        "SELECT s.*,
                (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id = s.id) AS q_count,
                (SELECT COUNT(*) FROM survey_responses r
                 WHERE r.survey_id = s.id
                 AND r.user_id = ? AND r.user_role = ?) AS already_responded
         FROM surveys s
         WHERE s.status = 'active'
         AND (s.starts_at IS NULL OR s.starts_at <= ?)
         AND (s.ends_at   IS NULL OR s.ends_at   >= ?)
         ORDER BY s.created_at DESC"
    );
    $stmt->execute([$user['id'], $user['role'], $now, $now]);
    $surveys = $stmt->fetchAll();

    pageHeader('Surveys', $user['role'] === 'admin' ? 'admin' : 'resident');
    renderHeader('📋 Surveys', $backUrl);
    ?>

    <div class="container">
      <?= getFlash() ?>

      <?php if (empty($surveys)): ?>
        <div class="card">
          <p style="color:#666;text-align:center;padding:20px 0;">
            📋 No surveys are currently open.<br>
            <span style="font-size:.85rem;">Check back later.</span>
          </p>
        </div>

      <?php else: ?>
        <?php foreach ($surveys as $s): ?>
          <div class="card" style="margin-bottom:14px;">
            <div style="display:flex;justify-content:space-between;
                        align-items:flex-start;flex-wrap:wrap;gap:8px;">
              <div style="flex:1;">
                <div style="font-size:1.05rem;font-weight:700;margin-bottom:4px;">
                  <?= htmlspecialchars($s['title']) ?>
                </div>
                <?php if ($s['description']): ?>
                  <div style="font-size:.87rem;color:#666;margin-bottom:8px;">
                    <?= htmlspecialchars($s['description']) ?>
                  </div>
                <?php endif; ?>
                <div style="font-size:.8rem;color:#999;">
                  <?= (int)$s['q_count'] ?> question<?= $s['q_count'] != 1 ? 's' : '' ?>
                  <?php if ($s['ends_at']): ?>
                    &nbsp;·&nbsp; Closes <?= date('d M Y', strtotime($s['ends_at'])) ?>
                  <?php endif; ?>
                </div>
              </div>
              <div>
                <?php if ((int)$s['already_responded'] > 0): ?>
                  <span class="badge badge-green" style="padding:8px 14px;font-size:.85rem;">
                    ✔ Submitted
                  </span>
                <?php else: ?>
                  <a href="survey_respond.php?action=take&id=<?= $s['id'] ?>"
                     class="btn btn-primary">
                    Take Survey →
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div style="margin-top:8px;">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-navy">← Back to Menu</a>
      </div>
    </div>

    <?php pageFooter(); exit;
}

// ============================================================
// TAKE — display survey form
// ============================================================
if ($action === 'take' && $surveyId > 0) {

    $now = date('Y-m-d H:i:s');

    // Load survey — must be active and within date window
    $stmt = db()->prepare(
        "SELECT * FROM surveys
         WHERE id = ? AND status = 'active'
         AND (starts_at IS NULL OR starts_at <= ?)
         AND (ends_at   IS NULL OR ends_at   >= ?)"
    );
    $stmt->execute([$surveyId, $now, $now]);
    $survey = $stmt->fetch();

    if (!$survey) {
        setFlash('error', 'This survey is not available.');
        header('Location: survey_respond.php?action=list'); exit;
    }

    // Check already responded
    $chk = db()->prepare(
        "SELECT id FROM survey_responses
         WHERE survey_id = ? AND user_id = ? AND user_role = ?"
    );
    $chk->execute([$surveyId, $user['id'], $user['role']]);
    if ($chk->fetch()) {
        setFlash('error', 'You have already submitted a response to this survey.');
        header('Location: survey_respond.php?action=list'); exit;
    }

    // Load questions
    $qstmt = db()->prepare(
        "SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC"
    );
    $qstmt->execute([$surveyId]);
    $questions = $qstmt->fetchAll();

    if (empty($questions)) {
        setFlash('error', 'This survey has no questions yet.');
        header('Location: survey_respond.php?action=list'); exit;
    }

    // ── POST: process submission ───────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

        // Validate required questions
        $errors = [];
        foreach ($questions as $q) {
            if (!$q['is_required']) continue;
            $key = 'q_' . $q['id'];
            $val = $_POST[$key] ?? '';
            if (is_array($val)) {
                if (empty($val)) $errors[] = 'Question ' . $q['sort_order'] . ' is required.';
            } else {
                if (trim($val) === '') $errors[] = 'Question ' . $q['sort_order'] . ' is required.';
            }
        }

        if (empty($errors)) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                // Insert response (WHO)
                $ins = $pdo->prepare(
                    "INSERT INTO survey_responses
                     (survey_id, user_id, user_role, erf_number, ip_address)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $ins->execute([
                    $surveyId,
                    $user['id'],
                    $user['role'],
                    $user['erf'] ?: null,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                $responseId = (int)$pdo->lastInsertId();

                // Insert answers (HOW) — one row per question
                $ansIns = $pdo->prepare(
                    "INSERT INTO survey_answers
                     (response_id, question_id, answer_text, answer_option, answer_json, answer_rating)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                foreach ($questions as $q) {
                    $key  = 'q_' . $q['id'];
                    $val  = $_POST[$key] ?? '';

                    $ansText   = null;
                    $ansOption = null;
                    $ansJson   = null;
                    $ansRating = null;

                    switch ($q['question_type']) {
                        case 'text':
                            $ansText = trim($val);
                            break;
                        case 'yes_no':
                        case 'single_choice':
                            $ansOption = trim($val);
                            break;
                        case 'multi_choice':
                            $selected = is_array($val) ? $val : [];
                            // Sanitise each option
                            $selected  = array_map('trim', $selected);
                            $selected  = array_filter($selected);
                            $ansJson   = !empty($selected)
                                ? json_encode(array_values($selected))
                                : null;
                            break;
                        case 'rating':
                            $r = (int)$val;
                            $ansRating = ($r >= 1 && $r <= 5) ? $r : null;
                            break;
                    }

                    $ansIns->execute([
                        $responseId,
                        $q['id'],
                        $ansText,
                        $ansOption,
                        $ansJson,
                        $ansRating,
                    ]);
                }

                $pdo->commit();
                header("Location: survey_respond.php?action=done&id={$surveyId}"); exit;

            } catch (Exception $e) {
                if (db()->inTransaction()) db()->rollBack();
                // Unique key violation = duplicate submission
                if (strpos($e->getMessage(), 'Duplicate') !== false ||
                    strpos($e->getMessage(), '1062') !== false) {
                    setFlash('error', 'You have already submitted a response to this survey.');
                    header('Location: survey_respond.php?action=list'); exit;
                }
                setFlash('error', 'An error occurred. Please try again.');
                header("Location: survey_respond.php?action=take&id={$surveyId}"); exit;
            }
        }
        // Fall through to re-display form with errors
    }

    $questionTypeLabel = [
        'yes_no'        => 'Yes / No',
        'single_choice' => 'Choose one',
        'multi_choice'  => 'Choose all that apply',
        'rating'        => 'Rate 1–5',
        'text'          => 'Your answer',
    ];

    pageHeader(htmlspecialchars($survey['title']), $user['role'] === 'admin' ? 'admin' : 'resident');
    renderHeader('📋 ' . htmlspecialchars($survey['title']), 'survey_respond.php?action=list');
    ?>

    <div class="container">

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" style="margin-bottom:16px;">
          <strong>Please answer all required questions:</strong>
          <ul style="margin:8px 0 0 16px;">
            <?php foreach ($errors as $e): ?>
              <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($survey['description']): ?>
        <div class="card" style="margin-bottom:14px;font-size:.9rem;color:#555;">
          <?= htmlspecialchars($survey['description']) ?>
        </div>
      <?php endif; ?>

      <form method="POST" id="surveyForm">
        <?= csrfField() ?>

        <?php foreach ($questions as $i => $q):
          $key     = 'q_' . $q['id'];
          $options = $q['options_json'] ? json_decode($q['options_json'], true) : [];
          $prevVal = $_POST[$key] ?? null;
        ?>

        <div class="card" style="margin-bottom:14px;">

          <!-- Question header -->
          <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:10px;">
            <span style="font-size:.75rem;font-weight:700;color:var(--muted);
                         min-width:22px;"><?= $q['sort_order'] ?>.</span>
            <div>
              <span style="font-weight:600;font-size:.97rem;">
                <?= htmlspecialchars($q['question_text']) ?>
              </span>
              <?php if ($q['is_required']): ?>
                <span style="color:#dc3545;margin-left:3px;">*</span>
              <?php else: ?>
                <span style="color:#999;font-size:.78rem;margin-left:6px;">(optional)</span>
              <?php endif; ?>
              <div style="font-size:.75rem;color:#aaa;margin-top:2px;">
                <?= $questionTypeLabel[$q['question_type']] ?? '' ?>
              </div>
            </div>
          </div>

          <!-- Answer input by type -->
          <?php if ($q['question_type'] === 'yes_no'): ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
              <?php foreach (['Yes', 'No'] as $opt): ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
                               padding:10px 18px;border:2px solid #dee2e6;border-radius:8px;
                               font-weight:500;font-size:.95rem;
                               <?= ($prevVal === $opt) ? 'border-color:var(--primary);background:#f0f7ff;' : '' ?>">
                  <input type="radio" name="<?= $key ?>"
                         value="<?= $opt ?>"
                         <?= ($prevVal === $opt) ? 'checked' : '' ?>
                         required
                         style="accent-color:var(--primary);">
                  <?= $opt ?>
                </label>
              <?php endforeach; ?>
            </div>

          <?php elseif ($q['question_type'] === 'single_choice'): ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($options as $opt): ?>
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                               padding:10px 14px;border:2px solid #dee2e6;border-radius:8px;
                               font-size:.92rem;
                               <?= ($prevVal === $opt) ? 'border-color:var(--primary);background:#f0f7ff;' : '' ?>">
                  <input type="radio" name="<?= $key ?>"
                         value="<?= htmlspecialchars($opt) ?>"
                         <?= ($prevVal === $opt) ? 'checked' : '' ?>
                         <?= $q['is_required'] ? 'required' : '' ?>
                         style="accent-color:var(--primary);">
                  <?= htmlspecialchars($opt) ?>
                </label>
              <?php endforeach; ?>
            </div>

          <?php elseif ($q['question_type'] === 'multi_choice'): ?>
            <?php $prevArr = is_array($prevVal) ? $prevVal : []; ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($options as $opt): ?>
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;
                               padding:10px 14px;border:2px solid #dee2e6;border-radius:8px;
                               font-size:.92rem;
                               <?= in_array($opt, $prevArr) ? 'border-color:var(--primary);background:#f0f7ff;' : '' ?>">
                  <input type="checkbox"
                         name="<?= $key ?>[]"
                         value="<?= htmlspecialchars($opt) ?>"
                         <?= in_array($opt, $prevArr) ? 'checked' : '' ?>
                         style="accent-color:var(--primary);width:16px;height:16px;">
                  <?= htmlspecialchars($opt) ?>
                </label>
              <?php endforeach; ?>
            </div>

          <?php elseif ($q['question_type'] === 'rating'): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
              <?php for ($r = 1; $r <= 5; $r++): ?>
                <label style="display:flex;flex-direction:column;align-items:center;
                               gap:4px;cursor:pointer;min-width:44px;">
                  <input type="radio" name="<?= $key ?>"
                         value="<?= $r ?>"
                         <?= ((string)$prevVal === (string)$r) ? 'checked' : '' ?>
                         <?= $q['is_required'] ? 'required' : '' ?>
                         style="accent-color:var(--primary);width:20px;height:20px;">
                  <span style="font-size:1.3rem;">
                    <?= $r <= 2 ? '😕' : ($r === 3 ? '😐' : ($r === 4 ? '🙂' : '😃')) ?>
                  </span>
                  <span style="font-size:.75rem;color:#666;"><?= $r ?></span>
                </label>
              <?php endfor; ?>
            </div>
            <div style="font-size:.75rem;color:#aaa;margin-top:6px;">
              1 = Poor &nbsp;·&nbsp; 5 = Excellent
            </div>

          <?php elseif ($q['question_type'] === 'text'): ?>
            <textarea name="<?= $key ?>"
                      rows="3"
                      maxlength="2000"
                      placeholder="Type your answer here…"
                      style="width:100%;padding:10px 12px;border:2px solid #dee2e6;
                             border-radius:8px;font-size:.92rem;resize:vertical;
                             font-family:inherit;"
                      <?= $q['is_required'] ? 'required' : '' ?>><?= htmlspecialchars($prevVal ?? '') ?></textarea>
            <div style="font-size:.75rem;color:#aaa;text-align:right;margin-top:2px;">
              Max 2000 characters
            </div>
          <?php endif; ?>

        </div><!-- question card -->
        <?php endforeach; ?>

        <!-- Submit -->
        <div class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
          <button type="submit" class="btn btn-primary"
                  onclick="return confirm('Submit your survey response? You cannot change it after submission.')">
            Submit Response ✔
          </button>
          <a href="survey_respond.php?action=list" class="btn btn-navy">Cancel</a>
          <span style="font-size:.8rem;color:#999;margin-left:auto;">
            * Required question
          </span>
        </div>

      </form>
    </div>

    <?php pageFooter(); exit;
}

// ============================================================
// DONE — thank-you page after successful submission
// ============================================================
if ($action === 'done' && $surveyId > 0) {

    $stmt = db()->prepare("SELECT title FROM surveys WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch();

    pageHeader('Thank You', $user['role'] === 'admin' ? 'admin' : 'resident');
    renderHeader('📋 Survey Complete', $backUrl);
    ?>

    <div class="container" style="max-width:480px;">
      <div class="card" style="text-align:center;padding:32px 24px;">
        <div style="font-size:3rem;margin-bottom:12px;">✅</div>
        <h2 style="margin-bottom:8px;">Thank You!</h2>
        <p style="color:#555;margin-bottom:6px;">
          Your response to <strong><?= htmlspecialchars($survey['title'] ?? 'the survey') ?></strong>
          has been recorded.
        </p>
        <p style="font-size:.85rem;color:#999;margin-bottom:24px;">
          Your response is anonymous in the results. Individual answers are
          never disclosed to other residents.
        </p>
        <a href="survey_respond.php?action=list" class="btn btn-primary" style="margin-right:8px;">
          View Other Surveys
        </a>
        <a href="<?= htmlspecialchars($backUrl) ?>" class="btn btn-navy">
          ← Back to Menu
        </a>
        <div class="popia-notice" style="margin-top:20px;">
          Response data is retained for the duration of the survey and processed
          under POPIA §11 for estate management purposes.
        </div>
      </div>
    </div>

    <?php pageFooter(); exit;
}

// Fallback
header('Location: survey_respond.php?action=list');
exit;