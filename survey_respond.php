<?php
// ============================================================
// survey_respond.php — Public survey (no login required)
// MBGE Access Control System
//
// Actions:
//   take  — display and submit a survey (?action=take&id=N)
//   done  — confirmation page after successful submission
//
// Anyone with the link can respond — no login required.
// After successful submission, a confirmation page is shown
// and the browser auto-redirects to https://www.google.com
// after 10 seconds.
//
// Duplicate-submission guard:
//   A random device ID is generated on first visit and stored
//   in the mbge_did cookie (5-year expiry). On submission the
//   (device_id, survey_id) pair is saved to survey_device_locks.
//   Every subsequent visit checks that table — the DB is the
//   source of truth, not the cookie value.
//
// DB note: public respondents are stored with user_id = NULL
// and user_role = 'resident' (closest valid ENUM value).
// Requires: survey_device_locks table (survey_device_locks_schema.sql)
// ============================================================

require_once 'layout.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Device ID — generated once, lives in cookie for 5 years ──
// The cookie carries only an opaque random ID; the actual lock
// record lives in survey_device_locks so clearing the cookie
// does not automatically allow a second submission (the ID is
// gone, but a new ID won't match the old DB record).
if (empty($_COOKIE['mbge_did'])) {
    $deviceId = bin2hex(random_bytes(32));   // 64-char hex
    setcookie('mbge_did', $deviceId, [
        'expires'  => time() + (5 * 365 * 24 * 3600),
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['mbge_did'] = $deviceId;
}
$deviceId = $_COOKIE['mbge_did'];

$action   = $_GET['action'] ?? 'take';
$surveyId = (int)($_GET['id'] ?? 0);

// ============================================================
// TAKE — display and process the survey form
// ============================================================
if ($action === 'take' && $surveyId > 0) {

    // ── Device lock check — source of truth is the DB ────────
    $lock = db()->prepare(
        "SELECT response_id FROM survey_device_locks
         WHERE survey_id = ? AND device_id = ?"
    );
    $lock->execute([$surveyId, $deviceId]);
    if ($lock->fetch()) {
        pageHeader('Already Submitted', 'public');
        ?>
        <div class="container" style="max-width:520px;text-align:center;padding-top:60px;">
          <div class="card" style="padding:40px 28px;">
            <div style="font-size:3.5rem;margin-bottom:16px;">✅</div>
            <h2 style="margin-bottom:10px;">Already Submitted</h2>
            <p style="color:#555;margin-bottom:20px;">
              You have already submitted a response to this survey from this device.
              Only one response per device is allowed.
            </p>
            <a href="https://www.google.com" class="btn btn-primary">Close</a>
          </div>
        </div>
        <?php
        pageFooter(); exit;
    }

    // Session guard — catches double-clicks within the same session
    if (!empty($_SESSION['survey_submitted_' . $surveyId])) {
        header("Location: survey_respond.php?action=done&id={$surveyId}"); exit;
    }

    $now = date('Y-m-d H:i:s');

    $stmt = db()->prepare(
        "SELECT * FROM surveys
         WHERE id = ? AND status = 'active'
         AND (starts_at IS NULL OR starts_at <= ?)
         AND (ends_at   IS NULL OR ends_at   >= ?)"
    );
    $stmt->execute([$surveyId, $now, $now]);
    $survey = $stmt->fetch();

    if (!$survey) {
        pageHeader('Survey Not Available', 'public');
        ?>
        <div class="container" style="max-width:480px;text-align:center;padding-top:60px;">
          <div class="card" style="padding:40px 24px;">
            <div style="font-size:3rem;margin-bottom:12px;">⚠️</div>
            <h2 style="margin-bottom:10px;">Survey Not Available</h2>
            <p style="color:#666;">This survey is currently closed or does not exist.</p>
          </div>
        </div>
        <?php
        pageFooter(); exit;
    }

    $qstmt = db()->prepare(
        "SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC"
    );
    $qstmt->execute([$surveyId]);
    $questions = $qstmt->fetchAll();

    if (empty($questions)) {
        pageHeader('Survey Not Available', 'public');
        ?>
        <div class="container" style="max-width:480px;text-align:center;padding-top:60px;">
          <div class="card" style="padding:40px 24px;">
            <div style="font-size:3rem;margin-bottom:12px;">⚠️</div>
            <h2>Survey Not Available</h2>
            <p style="color:#666;">This survey has no questions yet.</p>
          </div>
        </div>
        <?php
        pageFooter(); exit;
    }

    // ── POST: process submission ───────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrfToken();

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

                // user_id = NULL (public/anonymous), user_role = 'resident' (valid ENUM)
                $ins = $pdo->prepare(
                    "INSERT INTO survey_responses
                     (survey_id, user_id, user_role, erf_number, ip_address)
                     VALUES (?, NULL, 'resident', NULL, ?)"
                );
                $ins->execute([$surveyId, $_SERVER['REMOTE_ADDR'] ?? null]);
                $responseId = (int)$pdo->lastInsertId();

                $ansIns = $pdo->prepare(
                    "INSERT INTO survey_answers
                     (response_id, question_id, answer_text, answer_option, answer_json, answer_rating)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                foreach ($questions as $q) {
                    $key = 'q_' . $q['id'];
                    $val = $_POST[$key] ?? '';

                    $ansText = $ansOption = $ansJson = $ansRating = null;

                    switch ($q['question_type']) {
                        case 'text':
                            $ansText = trim($val);
                            break;
                        case 'yes_no':
                        case 'single_choice':
                            $ansOption = trim($val);
                            break;
                        case 'multi_choice':
                            $selected = is_array($val) ? array_filter(array_map('trim', $val)) : [];
                            $ansJson  = !empty($selected) ? json_encode(array_values($selected)) : null;
                            break;
                        case 'rating':
                            $r = (int)$val;
                            $ansRating = ($r >= 1 && $r <= 5) ? $r : null;
                            break;
                    }

                    $ansIns->execute([
                        $responseId, $q['id'],
                        $ansText, $ansOption, $ansJson, $ansRating,
                    ]);
                }

                // Save device lock to DB before committing
                $pdo->prepare(
                    "INSERT IGNORE INTO survey_device_locks
                     (survey_id, device_id, response_id)
                     VALUES (?, ?, ?)"
                )->execute([$surveyId, $deviceId, $responseId]);

                $pdo->commit();

                $_SESSION['survey_submitted_' . $surveyId] = $responseId;
                header("Location: survey_respond.php?action=done&id={$surveyId}"); exit;

            } catch (Exception $e) {
                if (db()->inTransaction()) db()->rollBack();
                setFlash('error', 'An error occurred saving your response. Please try again.');
            }
        }
        // Fall through to re-render form with errors
    }

    // ── Render survey form ─────────────────────────────────
    $questionTypeLabel = [
        'yes_no'        => 'Yes / No',
        'single_choice' => 'Choose one',
        'multi_choice'  => 'Choose all that apply',
        'rating'        => 'Rate 1–5',
        'text'          => 'Your answer',
    ];

    pageHeader(htmlspecialchars($survey['title']), 'public');
    ?>

    <div style="background:var(--accent);color:#fff;padding:14px 20px;">
      <span style="font-size:1.05rem;font-weight:600;">📋 <?= htmlspecialchars($survey['title']) ?></span>
    </div>

    <div class="container" style="max-width:640px;">

      <?= getFlash() ?>

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

        <?php foreach ($questions as $q):
          $key     = 'q_' . $q['id'];
          $options = $q['options_json'] ? json_decode($q['options_json'], true) : [];
          $prevVal = $_POST[$key] ?? null;
        ?>

        <div class="card" style="margin-bottom:14px;">

          <div style="display:flex;align-items:baseline;gap:8px;margin-bottom:10px;">
            <span style="font-size:.75rem;font-weight:700;color:var(--muted);min-width:22px;">
              <?= $q['sort_order'] ?>.
            </span>
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

          <?php if ($q['question_type'] === 'yes_no'): ?>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
              <?php foreach (['Yes', 'No'] as $opt): ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;
                               padding:10px 18px;border:2px solid #dee2e6;border-radius:8px;
                               font-weight:500;font-size:.95rem;
                               <?= ($prevVal === $opt) ? 'border-color:var(--accent);background:#f0f7ff;' : '' ?>">
                  <input type="radio" name="<?= $key ?>" value="<?= $opt ?>"
                         <?= ($prevVal === $opt) ? 'checked' : '' ?> required
                         style="accent-color:var(--accent);">
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
                               <?= ($prevVal === $opt) ? 'border-color:var(--accent);background:#f0f7ff;' : '' ?>">
                  <input type="radio" name="<?= $key ?>"
                         value="<?= htmlspecialchars($opt) ?>"
                         <?= ($prevVal === $opt) ? 'checked' : '' ?>
                         <?= $q['is_required'] ? 'required' : '' ?>
                         style="accent-color:var(--accent);">
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
                               <?= in_array($opt, $prevArr) ? 'border-color:var(--accent);background:#f0f7ff;' : '' ?>">
                  <input type="checkbox" name="<?= $key ?>[]"
                         value="<?= htmlspecialchars($opt) ?>"
                         <?= in_array($opt, $prevArr) ? 'checked' : '' ?>
                         style="accent-color:var(--accent);width:16px;height:16px;">
                  <?= htmlspecialchars($opt) ?>
                </label>
              <?php endforeach; ?>
            </div>

          <?php elseif ($q['question_type'] === 'rating'): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
              <?php for ($r = 1; $r <= 5; $r++): ?>
                <label style="display:flex;flex-direction:column;align-items:center;
                               gap:4px;cursor:pointer;min-width:44px;">
                  <input type="radio" name="<?= $key ?>" value="<?= $r ?>"
                         <?= ((string)$prevVal === (string)$r) ? 'checked' : '' ?>
                         <?= $q['is_required'] ? 'required' : '' ?>
                         style="accent-color:var(--accent);width:20px;height:20px;">
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
            <textarea name="<?= $key ?>" rows="3" maxlength="2000"
                      placeholder="Type your answer here…"
                      style="width:100%;padding:10px 12px;border:2px solid #dee2e6;
                             border-radius:8px;font-size:.92rem;resize:vertical;
                             font-family:inherit;"
                      <?= $q['is_required'] ? 'required' : '' ?>><?= htmlspecialchars($prevVal ?? '') ?></textarea>
            <div style="font-size:.75rem;color:#aaa;text-align:right;margin-top:2px;">
              Max 2000 characters
            </div>
          <?php endif; ?>

        </div>
        <?php endforeach; ?>

        <div class="card" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
          <button type="submit" class="btn btn-primary"
                  onclick="return confirm('Submit your survey response? You cannot change it after submission.')">
            Submit Response ✔
          </button>
          <span style="font-size:.8rem;color:#999;margin-left:auto;">* Required</span>
        </div>

      </form>

      <div class="popia-notice" style="margin-top:4px;">
        Response data is processed under POPIA §11 for estate management purposes.
      </div>
    </div>

    <?php pageFooter(); exit;
}

// ============================================================
// DONE — confirmation after successful submission
// ============================================================
if ($action === 'done' && $surveyId > 0) {

    // Accept session (just submitted) or DB device lock (returning to done URL later)
    $responseId = $_SESSION['survey_submitted_' . $surveyId] ?? null;
    if (!$responseId) {
        $dl = db()->prepare(
            "SELECT response_id FROM survey_device_locks
             WHERE survey_id = ? AND device_id = ?"
        );
        $dl->execute([$surveyId, $deviceId]);
        $row = $dl->fetch();
        $responseId = $row ? (int)$row['response_id'] : null;
    }

    if (!$responseId) {
        header('Location: https://www.google.com'); exit;
    }

    // Verify the response actually exists in the database
    $chk = db()->prepare(
        "SELECT id FROM survey_responses WHERE id = ? AND survey_id = ?"
    );
    $chk->execute([$responseId, $surveyId]);
    $confirmed = (bool)$chk->fetch();

    // Fetch survey title for the message
    $s = db()->prepare("SELECT title FROM surveys WHERE id = ?");
    $s->execute([$surveyId]);
    $survey = $s->fetch();

    pageHeader('Thank You', 'public');
    ?>

    <div style="background:var(--accent);color:#fff;padding:14px 20px;">
      <span style="font-size:1.05rem;font-weight:600;">📋 <?= htmlspecialchars($survey['title'] ?? 'Survey') ?></span>
    </div>

    <div class="container" style="max-width:520px;text-align:center;padding-top:50px;">
      <div class="card" style="padding:40px 28px;">

        <?php if ($confirmed): ?>
          <div style="font-size:3.5rem;margin-bottom:16px;">✅</div>
          <h2 style="color:#28a745;margin-bottom:10px;">Thank You!</h2>
          <p style="color:#444;font-size:1rem;margin-bottom:6px;">
            Your response to <strong><?= htmlspecialchars($survey['title'] ?? 'the survey') ?></strong>
            has been successfully saved.
          </p>
          <p style="color:#888;font-size:.88rem;margin-bottom:28px;">
            We appreciate you taking the time to complete this survey.
          </p>
          <p style="color:#aaa;font-size:.82rem;">
            You will be redirected in <strong id="countdown">10</strong> seconds…
          </p>
          <div style="margin-top:20px;">
            <a href="https://www.google.com" class="btn btn-primary">
              Continue now →
            </a>
          </div>
        <?php else: ?>
          <div style="font-size:3.5rem;margin-bottom:16px;">⚠️</div>
          <h2 style="color:#dc3545;margin-bottom:10px;">Something Went Wrong</h2>
          <p style="color:#666;margin-bottom:20px;">
            Your response could not be confirmed. Please try again or contact the estate office.
          </p>
          <a href="survey_respond.php?action=take&id=<?= $surveyId ?>" class="btn btn-primary">
            Try Again
          </a>
        <?php endif; ?>

        <div class="popia-notice" style="margin-top:24px;">
          Response data is processed under POPIA §11 for estate management purposes.
        </div>
      </div>
    </div>

    <?php if ($confirmed): ?>
    <script>
      var secs = 10;
      var el   = document.getElementById('countdown');
      var timer = setInterval(function() {
        secs--;
        if (el) el.textContent = secs;
        if (secs <= 0) {
          clearInterval(timer);
          window.location.href = 'https://www.google.com';
        }
      }, 1000);
    </script>
    <?php endif; ?>

    <?php
    pageFooter(); exit;
}

// Fallback — no valid action or survey id
header('Location: https://www.google.com');
exit;
