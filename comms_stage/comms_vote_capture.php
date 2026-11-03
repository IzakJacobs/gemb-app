<?php
/**
 * comms_vote_capture.php
 *
 * Administrative capture of owner and proxy paper ballots.
 *
 * PHP 8.3 / MariaDB
 *
 * SECURITY:
 * - Meeting and selected motions must be open.
 * - Existing ballots cannot be overwritten.
 * - Captures are atomic and audited.
 * - Existing proxy appointments must match submitted details.
 * - Concurrent administrative captures are serialized through
 *   meeting-row locking.
 *
 * IMPORTANT:
 * A UNIQUE database index on vote_cast(motion_id, erf_number)
 * remains mandatory for system-wide duplicate protection.
 */

require_once __DIR__ . '/comms_core.php';
require_once __DIR__ . '/comms_meeting_permissions.php';
require_once __DIR__ . '/layout.php';

commsRequireAuth();

if (function_exists('commsSecurityHeaders')) {
    commsSecurityHeaders();
}

$db = db();

$admin_id = (int) (commsCurrentUserId() ?? 0);
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$isCaptureAdministrator = false;

/* ============================================================
   HELPERS
   ============================================================ */

function bvcEscape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function bvcRedirect(
    int $meetingId,
    string $open = '',
    string $filter = 'all',
    string $q = '',
    string $sort = 'erf'
): void {
    header(
        'Location: comms_vote_capture.php?' .
        http_build_query([
            'meeting_id' => $meetingId,
            'open' => $open,
            'filter' => $filter,
            'q' => $q,
            'sort' => $sort,
        ])
    );
    exit;
}

function bvcValidFilter($value): string
{
    return in_array(
        $value,
        ['all', 'proxy', 'owner', 'done', 'pending'],
        true
    ) ? $value : 'all';
}

function bvcValidSort($value): string
{
    return $value === 'name' ? 'name' : 'erf';
}

function bvcNormalizeName(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

    return function_exists('mb_strtolower')
        ? mb_strtolower($name, 'UTF-8')
        : strtolower($name);
}

function bvcNormalizeErf(string $erf): string
{
    return strtoupper(trim($erf));
}

/**
 * Compare existing proxy appointment with submitted details.
 *
 * A blank submitted optional field does not conflict with
 * an existing populated field. However, a submitted nonblank
 * value must match the recorded value.
 *
 * Existing appointments are never updated here.
 */
function bvcProxyMatches(
    array $existing,
    string $holderName,
    string $holderErf,
    string $formReference
): bool {
    if (
        bvcNormalizeName((string) $existing['proxy_holder'])
        !== bvcNormalizeName($holderName)
    ) {
        return false;
    }

    $recordedErf = bvcNormalizeErf(
        (string) ($existing['proxy_holder_erf'] ?? '')
    );

    if (
        $holderErf !== '' &&
        $recordedErf !== $holderErf
    ) {
        return false;
    }

    $recordedReference = trim(
        (string) ($existing['form_reference'] ?? '')
    );

    if (
        $formReference !== '' &&
        $recordedReference !== $formReference
    ) {
        return false;
    }

    return true;
}

/**
 * Find next owner with at least one OPEN motion
 * for which no ballot has been captured.
 *
 * If there are no open motions, there is no next
 * capturable owner.
 */
function bvcNextPendingErf(
    PDO $db,
    int $meetingId,
    array $openMotionIds,
    string $afterErf
): string {
    if (!$openMotionIds) {
        return '';
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($openMotionIds), '?')
    );

    $sql = "
        SELECT veo.erf_number
        FROM vote_eligible_owners veo
        WHERE veo.meeting_id = ?
          AND EXISTS (
              SELECT 1
              FROM motions m
              WHERE m.id IN ($placeholders)
                AND m.meeting_id = veo.meeting_id
                AND m.status = 'open'
                AND NOT EXISTS (
                    SELECT 1
                    FROM vote_cast vc
                    WHERE vc.motion_id = m.id
                      AND vc.erf_number = veo.erf_number
                )
          )
        ORDER BY veo.erf_number
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute(
        array_merge([$meetingId], $openMotionIds)
    );

    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $erf) {
        if (strcmp((string) $erf, $afterErf) > 0) {
            return (string) $erf;
        }
    }

    return isset($rows[0]) ? (string) $rows[0] : '';
}

/* ============================================================
   REQUEST PARAMETERS
   ============================================================ */

$meeting_id = (int) ($_GET['meeting_id'] ?? 0);
if ($meeting_id > 0) {
    commsRequireMeetingRole($meeting_id, 'administrator', 'supervisor', 'assistant');
    $isCaptureAdministrator = commsHasMeetingTask($meeting_id, $admin_id, 'paper_ballot_capture');
}


if ($meeting_id <= 0) {
    header('Location: comms_voting.php?action=meetings_list');
    exit;
}

$filter = bvcValidFilter($_GET['filter'] ?? 'all');
$q = trim((string) ($_GET['q'] ?? ''));
$sort = bvcValidSort($_GET['sort'] ?? 'erf');
$open_erf = bvcNormalizeErf(
    (string) ($_GET['open'] ?? '')
);

/* ============================================================
   LOAD MEETING
   ============================================================ */

$stmt = $db->prepare("
    SELECT *
    FROM meetings
    WHERE id = ?
      AND status IN ('draft', 'open', 'closed')
    LIMIT 1
");

$stmt->execute([$meeting_id]);

$meeting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$meeting) {
    setFlash('error', 'Meeting not found.');
    header('Location: comms_voting.php?action=meetings_list');
    exit;
}

$meeting_open = ($meeting['status'] === 'open');

/* ============================================================
   LOAD MOTIONS
   ============================================================ */

$stmt = $db->prepare("
    SELECT
        id,
        meeting_id,
        title,
        resolution_type,
        motion_number,
        status
    FROM motions
    WHERE meeting_id = ?
    ORDER BY motion_number ASC, id ASC
");

$stmt->execute([$meeting_id]);

$motions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$motions) {
    setFlash(
        'error',
        'No motions found. Add motions before capturing votes.'
    );

    header(
        'Location: comms_voting.php?action=list&meeting_id=' .
        $meeting_id
    );
    exit;
}

$motion_ids = [];
$open_motion_ids = [];
$motion_map = [];
$motion_labels = [];

$ordinary_sequence = 0;
$special_sequence = 0;

foreach ($motions as $motion) {
    $mid = (int) $motion['id'];

    $motion_ids[] = $mid;
    $motion_map[$mid] = $motion;

    if ($motion['status'] === 'open') {
        $open_motion_ids[] = $mid;
    }

    $type = strtolower(
        (string) ($motion['resolution_type'] ?? '')
    );

    if (in_array($type, ['special', 'material', 'moi'], true)) {
        $special_sequence++;
        $motion_labels[$mid] = 'SR ' . $special_sequence;
    } else {
        $ordinary_sequence++;
        $motion_labels[$mid] = 'OR ' . $ordinary_sequence;
    }
}

$motion_count = count($motion_ids);
$open_motion_count = count($open_motion_ids);

/* ============================================================
   REGISTER CHECK
   ============================================================ */

$stmt = $db->prepare("
    SELECT COUNT(*)
    FROM vote_eligible_owners
    WHERE meeting_id = ?
");

$stmt->execute([$meeting_id]);

$register_count = (int) $stmt->fetchColumn();

if ($register_count === 0) {
    setFlash(
        'error',
        'The Vote Register must be uploaded before capturing votes.'
    );

    header(
        'Location: comms_voting.php?action=voter_register&id=' .
        $meeting_id
    );
    exit;
}

/* ============================================================
   POST: SAVE NEW BALLOTS ONLY
   ============================================================ */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_votes'])
) {
    // Only Administrators may save ballots at this stage.
    // Meeting-assigned Assistants will be authorised separately.
    commsRequireMeetingTask($meeting_id, 'paper_ballot_capture');

    verifyCsrfToken();
    
    

    $erf = bvcNormalizeErf(
        (string) ($_POST['erf_number'] ?? '')
    );

    $capture_method = (string) (
        $_POST['capture_method'] ?? ''
    );

    $holder_erf = bvcNormalizeErf(
        (string) ($_POST['holder_erf'] ?? '')
    );

    $holder_name = trim(
        (string) ($_POST['holder_name'] ?? '')
    );

    $form_reference = trim(
        (string) ($_POST['form_reference'] ?? '')
    );

    $post_filter = bvcValidFilter(
        $_POST['filter'] ?? 'all'
    );

    $post_q = trim(
        (string) ($_POST['q'] ?? '')
    );

    $post_sort = bvcValidSort(
        $_POST['sort'] ?? 'erf'
    );

    $errors = [];
    $selected_votes = [];

    if ($erf === '') {
        $errors[] = 'No erf number was supplied.';
    }

    if (!in_array(
        $capture_method,
        ['proxy', 'owner'],
        true
    )) {
        $errors[] = 'Invalid capture method.';
    }

    foreach ($motions as $motion) {
        $mid = (int) $motion['id'];
        $field = 'vote_' . $mid;

        if (!array_key_exists($field, $_POST)) {
            continue;
        }

        $choice = $_POST[$field];

        if (
            !is_string($choice) ||
            !in_array(
                $choice,
                ['for', 'against', 'abstain'],
                true
            )
        ) {
            $errors[] = 'Invalid choice for motion ' . $mid;
            continue;
        }

        $selected_votes[$mid] = $choice;
    }

    foreach ($_POST as $field => $value) {
        if (
            preg_match(
                '/^vote_(\d+)$/',
                (string) $field,
                $matches
            )
        ) {
            $mid = (int) $matches[1];

            if (!isset($motion_map[$mid])) {
                $errors[] =
                    'Submission contains a motion outside this meeting.';
            }
        }
    }

    if (!$selected_votes) {
        $errors[] = 'Select at least one uncaptured motion.';
    }

    if (
        $capture_method === 'proxy' &&
        $holder_name === ''
    ) {
        $errors[] =
            'Enter the proxy holder name from the signed form.';
    }

    if ($errors) {
        setFlash('error', implode(' ', $errors));

        bvcRedirect(
            $meeting_id,
            $erf,
            $post_filter,
            $post_q,
            $post_sort
        );
    }

    try {
        $db->beginTransaction();

        /*
         * Meeting-row lock serializes administrative
         * captures using this handler.
         */
        $stmt = $db->prepare("
            SELECT status
            FROM meetings
            WHERE id = ?
            FOR UPDATE
        ");

        $stmt->execute([$meeting_id]);

        if ($stmt->fetchColumn() !== 'open') {
            throw new RuntimeException(
                'Voting capture is blocked. The meeting is not open.'
            );
        }

        $selected_motion_ids = array_keys($selected_votes);
        sort($selected_motion_ids, SORT_NUMERIC);

        $motion_lock = $db->prepare("
            SELECT id, status
            FROM motions
            WHERE id = ?
              AND meeting_id = ?
            FOR UPDATE
        ");

        foreach ($selected_motion_ids as $mid) {
            $motion_lock->execute([$mid, $meeting_id]);

            $current_motion = $motion_lock->fetch(
                PDO::FETCH_ASSOC
            );

            if (!$current_motion) {
                throw new RuntimeException(
                    'A selected motion no longer exists.'
                );
            }

            if ($current_motion['status'] !== 'open') {
                throw new RuntimeException(
                    'Motion ' . $mid .
                    ' is not open. No votes were saved.'
                );
            }
        }

        /*
         * Lock register entry and verify eligibility.
         */
        $stmt = $db->prepare("
            SELECT id
            FROM vote_eligible_owners
            WHERE meeting_id = ?
              AND erf_number = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([$meeting_id, $erf]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException(
                'The selected erf is not in the current Vote Register.'
            );
        }

        /*
         * Reject any existing ballot.
         */
        $existing_stmt = $db->prepare("
            SELECT id
            FROM vote_cast
            WHERE motion_id = ?
              AND erf_number = ?
            LIMIT 1
            FOR UPDATE
        ");

        foreach ($selected_motion_ids as $mid) {
            $existing_stmt->execute([$mid, $erf]);

            if ($existing_stmt->fetchColumn()) {
                throw new RuntimeException(
                    'A ballot already exists for motion ' .
                    $mid .
                    '. Existing ballots cannot be overwritten. ' .
                    'No votes were saved.'
                );
            }
        }

        /*
         * Proxy lookup now retrieves holder details.
         */
        $proxy_lookup = $db->prepare("
            SELECT
                id,
                proxy_holder,
                proxy_holder_erf,
                form_reference
            FROM vote_proxy
            WHERE motion_id = ?
              AND erf_number = ?
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");

        $proxy_insert = $db->prepare("
            INSERT INTO vote_proxy
            (
                motion_id,
                erf_number,
                proxy_holder,
                proxy_holder_erf,
                form_reference,
                captured_by,
                notes
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?,
                'Administrative paper capture'
            )
        ");

        $cast_insert = $db->prepare("
            INSERT INTO vote_cast
            (
                motion_id,
                erf_number,
                capture_method,
                cast_by_role,
                cast_by_id,
                proxy_id,
                ip_address,
                cast_at
            )
            VALUES
            (
                ?, ?, ?, 'admin', ?, ?, ?, NOW()
            )
        ");

        $tally_insert = $db->prepare("
            INSERT INTO vote_tally
            (
                cast_id,
                vote_option,
                is_blank
            )
            VALUES (?, ?, 0)
        ");

        $audit_insert = $db->prepare("
            INSERT INTO vote_admin_log
            (
                admin_id,
                action,
                entity,
                entity_id,
                detail,
                ip_address
            )
            VALUES
            (
                ?, ?, 'motion', ?, ?, ?
            )
        ");

        $saved = 0;

        foreach ($selected_motion_ids as $mid) {
            $choice = $selected_votes[$mid];
            $proxy_id = null;

            if ($capture_method === 'proxy') {
                $proxy_lookup->execute([$mid, $erf]);

                $existing_proxy = $proxy_lookup->fetch(
                    PDO::FETCH_ASSOC
                );

                if ($existing_proxy) {
                    if (!bvcProxyMatches(
                        $existing_proxy,
                        $holder_name,
                        $holder_erf,
                        $form_reference
                    )) {
                        throw new RuntimeException(
                            'Proxy appointment details conflict ' .
                            'with the existing record for motion ' .
                            $mid .
                            '. No votes were saved. ' .
                            'Please verify the signed proxy form.'
                        );
                    }

                    $proxy_id = (int) $existing_proxy['id'];
                } else {
                    $proxy_insert->execute([
                        $mid,
                        $erf,
                        $holder_name,
                        $holder_erf !== ''
                            ? $holder_erf
                            : null,
                        $form_reference !== ''
                            ? $form_reference
                            : null,
                        $admin_id,
                    ]);

                    $proxy_id = (int) $db->lastInsertId();
                }
            }

            /*
             * INSERT ONLY. Never overwrite.
             */
            $cast_insert->execute([
                $mid,
                $erf,
                $capture_method,
                $admin_id,
                $proxy_id,
                $ip,
            ]);

            $cast_id = (int) $db->lastInsertId();

            if ($cast_id <= 0) {
                throw new RuntimeException(
                    'Could not identify the new ballot record.'
                );
            }

            $tally_insert->execute([
                $cast_id,
                $choice,
            ]);

            $audit_action =
                $capture_method === 'proxy'
                ? 'proxy_capture'
                : 'owner_capture';

            $audit_detail =
                'New ballot captured; erf=' . $erf .
                '; cast_id=' . $cast_id .
                '; method=' . $capture_method;

            $audit_insert->execute([
                $admin_id,
                $audit_action,
                $mid,
                $audit_detail,
                $ip,
            ]);

            $saved++;
        }

        $db->commit();

        setFlash(
            'success',
            $saved . ' new ballot' .
            ($saved === 1 ? '' : 's') .
            ' captured successfully. Existing ballots were not changed.'
        );

        /*
         * Re-query currently open motions before choosing
         * the next owner.
         */
        $stmt = $db->prepare("
            SELECT id
            FROM motions
            WHERE meeting_id = ?
              AND status = 'open'
            ORDER BY id
        ");

        $stmt->execute([$meeting_id]);

        $current_open_ids = array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );

        $next_erf = bvcNextPendingErf(
            $db,
            $meeting_id,
            $current_open_ids,
            $erf
        );

        bvcRedirect(
            $meeting_id,
            $next_erf,
            $post_filter,
            $post_q,
            $post_sort
        );

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'comms_vote_capture failure: meeting=' .
            $meeting_id .
            '; ' .
            $e->getMessage()
        );

        $message = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'The capture could not be completed. ' .
              'No votes were saved. Please check the ' .
              'existing ballots and try again.';

        setFlash('error', $message);

        bvcRedirect(
            $meeting_id,
            $erf,
            $post_filter,
            $post_q,
            $post_sort
        );
    }
}

/* ============================================================
   LOAD REGISTER
   ============================================================ */

$sort_column =
    $sort === 'name'
    ? 'owner_name'
    : 'erf_number';

$sql = "
    SELECT
        id,
        erf_number,
        owner_name
    FROM vote_eligible_owners
    WHERE meeting_id = ?
";

$params = [$meeting_id];

if ($q !== '') {
    $sql .= "
        AND (
            erf_number LIKE ?
            OR owner_name LIKE ?
        )
    ";

    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$sql .= "
    ORDER BY $sort_column ASC, erf_number ASC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$all_owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ============================================================
   LOAD EXISTING BALLOTS
   ============================================================ */

$vote_map = [];
$method_map = [];

$placeholders = implode(
    ',',
    array_fill(0, count($motion_ids), '?')
);

$stmt = $db->prepare("
    SELECT
        vc.motion_id,
        vc.erf_number,
        vc.capture_method,
        vt.vote_option
    FROM vote_cast vc
    LEFT JOIN vote_tally vt
      ON vt.cast_id = vc.id
    WHERE vc.motion_id IN ($placeholders)
");

$stmt->execute($motion_ids);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $erf = (string) $row['erf_number'];
    $mid = (int) $row['motion_id'];

    $vote_map[$erf][$mid] =
        (string) ($row['vote_option'] ?? '');

    $method_map[$erf][$mid] =
        (string) $row['capture_method'];
}

/* ============================================================
   LOAD PROXY DETAILS
   ============================================================ */

$proxy_map = [];

$stmt = $db->prepare("
    SELECT
        vp.motion_id,
        vp.erf_number,
        vp.proxy_holder,
        vp.proxy_holder_erf,
        vp.form_reference
    FROM vote_proxy vp
    WHERE vp.motion_id IN ($placeholders)
    ORDER BY vp.id ASC
");

$stmt->execute($motion_ids);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $erf = (string) $row['erf_number'];
    $mid = (int) $row['motion_id'];

    if (!isset($proxy_map[$erf][$mid])) {
        $proxy_map[$erf][$mid] = $row;
    }
}

/* ============================================================
   ENRICH REGISTER
   ============================================================ */

foreach ($all_owners as &$owner) {
    $erf = (string) $owner['erf_number'];

    $owner['vote_map'] = $vote_map[$erf] ?? [];
    $owner['method_map'] = $method_map[$erf] ?? [];

    $captured = 0;
    $open_captured = 0;

    $has_proxy = false;
    $has_owner = false;

    foreach ($motion_ids as $mid) {
        if (isset($owner['method_map'][$mid])) {
            $captured++;

            if (in_array($mid, $open_motion_ids, true)) {
                $open_captured++;
            }

            if ($owner['method_map'][$mid] === 'proxy') {
                $has_proxy = true;
            }

            if ($owner['method_map'][$mid] === 'owner') {
                $has_owner = true;
            }
        }
    }

    $owner['votes_cast'] = $captured;

    $owner['open_votes_cast'] = $open_captured;

    /*
     * "Done" means no currently open motion
     * remains available for capture.
     */
    $owner['is_done'] =
        $open_motion_count === 0 ||
        $open_captured >= $open_motion_count;

    $owner['is_partial'] =
        !$owner['is_done'] &&
        $captured > 0;

    $owner['is_pending'] =
        !$owner['is_done'];

    $owner['display_type'] =
        $has_proxy
        ? 'proxy'
        : ($has_owner ? 'owner' : 'pending');

    $owner['holder_name'] = '';
    $owner['holder_erf'] = '';
    $owner['form_reference'] = '';

    if (isset($proxy_map[$erf])) {
        $first_proxy = reset($proxy_map[$erf]);

        $owner['holder_name'] =
            (string) ($first_proxy['proxy_holder'] ?? '');

        $owner['holder_erf'] =
            (string) ($first_proxy['proxy_holder_erf'] ?? '');

        $owner['form_reference'] =
            (string) ($first_proxy['form_reference'] ?? '');
    }
}

unset($owner);

/* ============================================================
   STATISTICS
   ============================================================ */

$total_proxy = 0;
$total_owner = 0;
$total_done = 0;
$total_pending = 0;

foreach ($all_owners as $owner) {
    if ($owner['display_type'] === 'proxy') {
        $total_proxy++;
    }

    if ($owner['display_type'] === 'owner') {
        $total_owner++;
    }

    if ($owner['is_done']) {
        $total_done++;
    } else {
        $total_pending++;
    }
}

/* ============================================================
   APPLY FILTER
   ============================================================ */

$owners = array_values(
    array_filter(
        $all_owners,
        static function ($owner) use ($filter): bool {
            switch ($filter) {
                case 'proxy':
                    return $owner['display_type'] === 'proxy';

                case 'owner':
                    return $owner['display_type'] === 'owner';

                case 'done':
                    return $owner['is_done'];

                case 'pending':
                    return !$owner['is_done'];

                default:
                    return true;
            }
        }
    )
);

/* ============================================================
   SELECT EXPANDED CARD
   ============================================================ */

if ($open_erf === '') {
    foreach ($owners as $owner) {
        if (!$owner['is_done']) {
            $open_erf = (string) $owner['erf_number'];
            break;
        }
    }
}

/* ============================================================
   URL HELPER
   ============================================================ */

function bvcBuildUrl(array $overrides = []): string
{
    global $meeting_id, $filter, $q, $sort, $open_erf;

    $params = array_merge(
        [
            'meeting_id' => $meeting_id,
            'filter' => $filter,
            'q' => $q,
            'sort' => $sort,
            'open' => $open_erf,
        ],
        $overrides
    );

    return 'comms_vote_capture.php?' .
        http_build_query($params);
}

/* ============================================================
   PAGE HEADER
   ============================================================ */

pageHeader('Bulk Vote Capture', 'admin');

renderHeader(
    'Bulk Vote Capture - ' .
    bvcEscape($meeting['title']),
    'comms_voting.php?action=meetings_list'
);

?>

<style>
.bvc,
.bvc * {
    box-sizing: border-box;
}

.bvc {
    max-width: 1000px;
    margin: 0 auto;
    padding: 1rem;
    font-size: 16px;
}

.bvc-header {
    background: var(--surface-card, #fff);
    border: 1px solid var(--border, #ddd);
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 15px;
}

.bvc-header h1 {
    margin: 0 0 8px;
    font-size: 24px;
}

.bvc-muted {
    color: #555;
}

.bvc-warning {
    background: #fff3cd;
    color: #664d03;
    border: 1px solid #ffda6a;
    border-radius: 7px;
    padding: 12px;
    margin-bottom: 15px;
}

.bvc-success {
    background: #d1e7dd;
    color: #0f5132;
    border-radius: 7px;
    padding: 12px;
}

.bvc-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 15px;
}

.bvc-stat {
    border: 1px solid #ddd;
    border-radius: 7px;
    padding: 10px 16px;
    background: #f8f9fa;
    text-align: center;
    min-width: 100px;
}

.bvc-stat strong {
    display: block;
    font-size: 24px;
}

.bvc-tabs {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin: 15px 0;
}

.bvc-tabs a {
    display: inline-block;
    padding: 8px 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    background: #fff;
    text-decoration: none;
    color: #333;
}

.bvc-tabs a.active {
    background: #0d4f87;
    color: #fff;
    border-color: #0d4f87;
}

.bvc-search {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 15px;
}

.bvc-search input[type="text"] {
    flex: 1;
    min-width: 180px;
    padding: 9px;
    font-size: 16px;
}

.bvc-search button,
.bvc-search a {
    padding: 9px 12px;
}

.bvc-card {
    background: #fff;
    border: 2px solid #ddd;
    border-radius: 9px;
    margin-bottom: 10px;
    overflow: hidden;
}

.bvc-card.done {
    border-color: #198754;
}

.bvc-card.partial {
    border-color: #fd7e14;
}

.bvc-card.active {
    border-color: #0d6efd;
}

.bvc-card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    padding: 13px;
    color: #222;
    text-decoration: none;
    background: #f8f9fa;
}

.bvc-card-header:hover {
    background: #eef4fa;
}

.bvc-erf {
    font-weight: 700;
    font-size: 18px;
}

.bvc-name {
    flex: 1;
    min-width: 120px;
}

.bvc-badge {
    display: inline-block;
    padding: 4px 9px;
    border-radius: 5px;
    font-size: 13px;
    font-weight: 700;
}

.bvc-badge.proxy {
    background: #fff3cd;
    color: #7a4000;
}

.bvc-badge.owner {
    background: #cfe2ff;
    color: #084298;
}

.bvc-badge.done {
    background: #d1e7dd;
    color: #0f5132;
}

.bvc-badge.partial {
    background: #ffe5d0;
    color: #8a3c00;
}

.bvc-body {
    border-top: 1px solid #ddd;
}

.bvc-section {
    padding: 12px;
    border-bottom: 1px solid #ddd;
}

.bvc-type-buttons {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.bvc-type-buttons button {
    padding: 9px 18px;
    border: 2px solid #aaa;
    border-radius: 6px;
    background: #fff;
    color: #222;
    font-weight: 700;
    cursor: pointer;
}

.bvc-type-buttons button.selected {
    background: #0d4f87;
    border-color: #0d4f87;
    color: #fff;
}

.bvc-proxy-fields {
    background: #fff9e6;
}

.bvc-field-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.bvc-field-grid label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.bvc-field-grid input {
    width: 100%;
    padding: 9px;
    font-size: 16px;
}

.bvc-quick {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.bvc-quick button {
    padding: 8px 12px;
    border: 1px solid #aaa;
    border-radius: 5px;
    background: #fff;
    cursor: pointer;
}

.bvc-motion {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-bottom: 1px solid #e5e5e5;
}

.bvc-motion-title {
    flex: 1;
    line-height: 1.4;
}

.bvc-motion-label {
    font-weight: 700;
    min-width: 50px;
}

.bvc-vote-buttons {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.bvc-vote-button {
    padding: 9px 13px;
    border: 2px solid #aaa;
    border-radius: 5px;
    background: #f4f4f4;
    color: #222;
    cursor: pointer;
    font-weight: 700;
}

.bvc-vote-button.selected-for {
    background: #1b5e20;
    color: #fff;
    border-color: #1b5e20;
}

.bvc-vote-button.selected-against {
    background: #b71c1c;
    color: #fff;
    border-color: #b71c1c;
}

.bvc-vote-button.selected-abstain {
    background: #424242;
    color: #fff;
    border-color: #424242;
}

.bvc-existing {
    background: #e9ecef;
    color: #333;
    border-radius: 5px;
    padding: 7px 10px;
    font-weight: 600;
}

.bvc-closed {
    background: #fff3cd;
    color: #664d03;
    padding: 7px 10px;
    border-radius: 5px;
}

.bvc-save {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    padding: 14px;
    background: #f8f9fa;
}

.bvc-save button {
    background: #0d6efd;
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 11px 20px;
    font-weight: 700;
    cursor: pointer;
}

.bvc-save button:disabled {
    background: #aaa;
    cursor: not-allowed;
}

.bvc-dots {
    display: flex;
    gap: 5px;
}

.bvc-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #bbb;
    background: #fff;
}

.bvc-dot.for {
    background: #1b5e20;
    border-color: #1b5e20;
}

.bvc-dot.against {
    background: #b71c1c;
    border-color: #b71c1c;
}

.bvc-dot.abstain {
    background: #424242;
    border-color: #424242;
}

.bvc-keyboard {
    background: #e8f4fd;
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 12px;
    color: #164b72;
}

.bvc-keyboard kbd {
    background: #fff;
    padding: 3px 7px;
    border: 1px solid #aaa;
    border-radius: 4px;
}

.bvc-motion.keyboard-focus {
    outline: 3px solid #0d6efd;
    outline-offset: -3px;
    background: #e8f0ff;
}

@media (max-width: 650px) {
    .bvc-field-grid {
        grid-template-columns: 1fr;
    }

    .bvc-motion {
        flex-wrap: wrap;
    }

    .bvc-motion-title {
        min-width: 100%;
    }
}
</style>

<main class="pc-main">
<div class="bvc">

    <div class="bvc-header">

        <h1>
            Bulk Vote Capture
            <span class="bvc-badge <?= bvcEscape($meeting['status']) ?>">
                <?= strtoupper(bvcEscape($meeting['status'])) ?>
            </span>
        </h1>

        <div class="bvc-muted">
            <?= bvcEscape($meeting['title']) ?>

            <?php if (!empty($meeting['meeting_date'])): ?>
                —
                <?= bvcEscape(
                    date(
                        'j F Y',
                        strtotime($meeting['meeting_date'])
                    )
                ) ?>
            <?php endif; ?>

            —
            <?= $motion_count ?>
            motion<?= $motion_count === 1 ? '' : 's' ?>
        </div>

        <div class="bvc-stats">

            <div class="bvc-stat">
                <strong><?= $total_proxy ?></strong>
                Proxy
            </div>

            <div class="bvc-stat">
                <strong><?= $total_owner ?></strong>
                Owner
            </div>

            <div class="bvc-stat">
                <strong><?= $total_done ?></strong>
                Done
            </div>

            <div class="bvc-stat">
                <strong><?= $total_pending ?></strong>
                Pending
            </div>

        </div>

    </div>

    <?= getFlash() ?>

    <?php if (!$isCaptureAdministrator): ?>

    <div class="bvc-warning">
        <strong>Read-only verification.</strong>
        You may inspect recorded ballots and proxy details,
        but you cannot capture or change votes.
    </div>

<?php elseif (!$meeting_open): ?>

        <div class="bvc-warning">
            <strong>Capture locked.</strong>
            This meeting is <?= bvcEscape($meeting['status']) ?>.
            New votes may be captured only while the meeting
            and the relevant motions are open.
        </div>

    <?php else: ?>

        <div class="bvc-success">
            <strong>Protected capture enabled.</strong>
            Existing ballots cannot be overwritten.
            Closed motions cannot receive new ballots.
        </div>

    <?php endif; ?>

    <div class="bvc-tabs">

        <?php
        $tabs = [
            'all' => 'All (' . count($all_owners) . ')',
            'proxy' => 'Proxy (' . $total_proxy . ')',
            'owner' => 'Owner (' . $total_owner . ')',
            'done' => 'Done (' . $total_done . ')',
            'pending' => 'Pending (' . $total_pending . ')',
        ];
        ?>

        <?php foreach ($tabs as $key => $label): ?>

            <a
                href="<?= bvcEscape(
                    bvcBuildUrl([
                        'filter' => $key,
                        'open' => '',
                    ])
                ) ?>"
                class="<?= $filter === $key ? 'active' : '' ?>"
            >
                <?= bvcEscape($label) ?>
            </a>

        <?php endforeach; ?>

    </div>

    <form
        method="GET"
        action="comms_vote_capture.php"
        class="bvc-search"
    >

        <input
            type="hidden"
            name="meeting_id"
            value="<?= $meeting_id ?>"
        >

        <input
            type="hidden"
            name="filter"
            value="<?= bvcEscape($filter) ?>"
        >

        <input
            type="hidden"
            name="sort"
            value="<?= bvcEscape($sort) ?>"
        >

        <input
            type="text"
            name="q"
            value="<?= bvcEscape($q) ?>"
            placeholder="Search erf or owner name"
            autocomplete="off"
        >

        <button type="submit">Search</button>

        <a href="<?= bvcEscape(
            bvcBuildUrl([
                'q' => '',
                'open' => '',
            ])
        ) ?>">
            Clear
        </a>

        <a href="<?= bvcEscape(
            bvcBuildUrl([
                'sort' => $sort === 'erf' ? 'name' : 'erf',
                'open' => '',
            ])
        ) ?>">
            Sort: <?= $sort === 'erf' ? 'Erf' : 'Name' ?>
        </a>

    </form>

    <div class="bvc-keyboard">
        <kbd>Y</kbd> For &nbsp;
        <kbd>N</kbd> Against &nbsp;
        <kbd>A</kbd> Abstain &nbsp;
        <kbd>P</kbd> Proxy / Owner &nbsp;
        <kbd>Enter</kbd> Save
    </div>

    <?php if (!$owners): ?>

        <div class="bvc-warning">
            No register entries match the selected filter.
        </div>

    <?php endif; ?>

    <?php foreach ($owners as $owner): ?>

        <?php
        $erf = (string) $owner['erf_number'];

        $is_open = ($erf === $open_erf);
        $is_done = (bool) $owner['is_done'];
        $is_partial = (bool) $owner['is_partial'];

        $owner_vote_map = $owner['vote_map'];
        $owner_method_map = $owner['method_map'];

        $display_type = $owner['display_type'];

       $card_classes = [];

        if ($is_done) {
            $card_classes[] = 'done';
        } elseif ($is_partial) {
            $card_classes[] = 'partial';
        }

        if ($is_open) {
            $card_classes[] = 'active';
        }

        $card_class = implode(' ', $card_classes);

        $toggle_url = bvcBuildUrl([
            'open' => $is_open ? '' : $erf,
        ]);

        $form_id = 'capture-form-' . (int) $owner['id'];
        ?>

        <div
            class="bvc-card <?= bvcEscape($card_class) ?>"
        >

            <a
                class="bvc-card-header"
                href="<?= bvcEscape($toggle_url) ?>"
            >

                <span class="bvc-erf">
                    <?= bvcEscape($erf) ?>
                </span>

                <?php if ($display_type === 'proxy'): ?>

                    <span class="bvc-badge proxy">
                        Proxy
                    </span>

                <?php elseif ($display_type === 'owner'): ?>

                    <span class="bvc-badge owner">
                        Owner
                    </span>

                <?php endif; ?>

                <span class="bvc-name">
                    <?= bvcEscape($owner['owner_name']) ?>
                </span>

                <span class="bvc-dots">

                    <?php foreach ($motions as $motion): ?>

                        <?php
                        $mid = (int) $motion['id'];

                        $choice =
                            $owner_vote_map[$mid] ?? '';

                        $dot_class = in_array(
                            $choice,
                            ['for', 'against', 'abstain'],
                            true
                        ) ? $choice : '';
                        ?>

                        <span
                            class="bvc-dot <?= bvcEscape($dot_class) ?>"
                            title="<?= bvcEscape(
                                $motion_labels[$mid] .
                                ': ' .
                                ($choice !== ''
                                    ? ucfirst($choice)
                                    : 'Not captured')
                            ) ?>"
                        ></span>

                    <?php endforeach; ?>

                </span>

                <?php if ($is_done): ?>

                    <span class="bvc-badge done">
                        Done
                    </span>

                <?php elseif ($is_partial): ?>

                    <span class="bvc-badge partial">
                        <?= (int) $owner['open_votes_cast'] ?>
                        /
                        <?= $open_motion_count ?>
                    </span>

                <?php else: ?>

                    <span class="bvc-muted">
                        Pending
                    </span>

                <?php endif; ?>

            </a>

            <?php if ($is_open): ?>

                <div class="bvc-body">

                    <form
                        method="POST"
                        action="comms_vote_capture.php?meeting_id=<?= $meeting_id ?>"
                        id="<?= bvcEscape($form_id) ?>"
                        class="bvc-capture-form"
                    >

                        <?= csrfField() ?>

                        <input
                            type="hidden"
                            name="save_votes"
                            value="1"
                        >

                        <input
                            type="hidden"
                            name="erf_number"
                            value="<?= bvcEscape($erf) ?>"
                        >

                        <input
                            type="hidden"
                            name="capture_method"
                            value="<?= $display_type === 'owner'
                                ? 'owner'
                                : 'proxy' ?>"
                            class="bvc-capture-method"
                        >

                        <input
                            type="hidden"
                            name="filter"
                            value="<?= bvcEscape($filter) ?>"
                        >

                        <input
                            type="hidden"
                            name="q"
                            value="<?= bvcEscape($q) ?>"
                        >

                        <input
                            type="hidden"
                            name="sort"
                            value="<?= bvcEscape($sort) ?>"
                        >

                       <?php if ($isCaptureAdministrator && $meeting_open && !$is_done): ?>

                            <div class="bvc-section">

                                <strong>Capture type</strong>

                                <div class="bvc-type-buttons">

                                    <button
                                        type="button"
                                        class="bvc-type-button <?= $display_type !== 'owner'
                                            ? 'selected'
                                            : '' ?>"
                                        data-type="proxy"
                                    >
                                        Proxy
                                    </button>

                                    <button
                                        type="button"
                                        class="bvc-type-button <?= $display_type === 'owner'
                                            ? 'selected'
                                            : '' ?>"
                                        data-type="owner"
                                    >
                                        Owner
                                    </button>

                                </div>

                            </div>

                            <div
                                class="bvc-section bvc-proxy-fields"
                                <?= $display_type === 'owner'
                                    ? 'hidden'
                                    : '' ?>
                            >

                                <strong>
                                    Proxy holder details
                                </strong>

                                <p class="bvc-muted">
                                    Enter the details from the
                                    signed proxy appointment.
                                    Existing proxy appointments
                                    are not changed by this form.
                                </p>

                                <div class="bvc-field-grid">

                                    <div>
                                        <label>
                                            Holder erf
                                        </label>

                                        <input
                                            type="text"
                                            name="holder_erf"
                                            value="<?= bvcEscape(
                                                $owner['holder_erf']
                                            ) ?>"
                                            autocomplete="off"
                                        >
                                    </div>

                                    <div>
                                        <label>
                                            Holder full name
                                        </label>

                                        <input
                                            type="text"
                                            name="holder_name"
                                            value="<?= bvcEscape(
                                                $owner['holder_name']
                                            ) ?>"
                                            autocomplete="off"
                                        >
                                    </div>

                                    <div>
                                        <label>
                                            Proxy form reference
                                        </label>

                                        <input
                                            type="text"
                                            name="form_reference"
                                            value="<?= bvcEscape(
                                                $owner['form_reference']
                                            ) ?>"
                                            placeholder="Document reference"
                                            autocomplete="off"
                                        >
                                    </div>

                                </div>

                            </div>

                            <div class="bvc-section bvc-quick">

                                <strong>Fill uncaptured:</strong>

                                <button
                                    type="button"
                                    class="bvc-quick-fill"
                                    data-choice="for"
                                >
                                    All For
                                </button>

                                <button
                                    type="button"
                                    class="bvc-quick-fill"
                                    data-choice="against"
                                >
                                    All Against
                                </button>

                                <button
                                    type="button"
                                    class="bvc-quick-fill"
                                    data-choice="abstain"
                                >
                                    All Abstain
                                </button>

                            </div>

                        <?php endif; ?>

                        <?php
                        $available_count = 0;
                        ?>

                        <?php foreach ($motions as $motion): ?>

                            <?php
                            $mid = (int) $motion['id'];

                            $existing_choice =
                                $owner_vote_map[$mid] ?? null;

                            $already_captured = isset(
                                $owner_method_map[$mid]
                            );

                            $motion_open =
                                $motion['status'] === 'open';

                           $can_capture =
                            $isCaptureAdministrator &&
                            $meeting_open &&
                            $motion_open &&
                            !$already_captured;

                            if ($can_capture) {
                                $available_count++;
                            }
                            ?>

                            <div
                                class="bvc-motion"
                                data-motion-id="<?= $mid ?>"
                                data-capturable="<?= $can_capture
                                    ? '1'
                                    : '0' ?>"
                            >

                                <span class="bvc-motion-label">
                                    <?= bvcEscape(
                                        $motion_labels[$mid]
                                    ) ?>
                                </span>

                                <span class="bvc-motion-title">
                                    <?= bvcEscape(
                                        $motion['title']
                                    ) ?>
                                </span>

                                <?php if ($already_captured): ?>

                                    <span class="bvc-existing">

                                        Captured:
                                        <?= bvcEscape(
                                            strtoupper(
                                                (string) $existing_choice
                                            )
                                        ) ?>

                                        <small>
                                            (
                                            <?= bvcEscape(
                                                $owner_method_map[$mid]
                                            ) ?>
                                            )
                                        </small>

                                        — Locked

                                    </span>

                                <?php elseif (!$isCaptureAdministrator || !$meeting_open || !$motion_open): ?>

                                    <span class="bvc-closed">
                                        Capture closed
                                    </span>

                                <?php else: ?>

                                    <div class="bvc-vote-buttons">

                                        <?php foreach (
                                            [
                                                'for' => 'Y For',
                                                'against' => 'N Against',
                                                'abstain' => 'A Abstain',
                                            ] as $choice => $label
                                        ): ?>

                                            <button
                                                type="button"
                                                class="bvc-vote-button"
                                                data-choice="<?= bvcEscape(
                                                    $choice
                                                ) ?>"
                                                aria-pressed="false"
                                            >
                                                <?= bvcEscape($label) ?>
                                            </button>

                                        <?php endforeach; ?>

                                    </div>

                                    <input
                                        type="hidden"
                                        class="bvc-vote-value"
                                        data-field-name="vote_<?= $mid ?>"
                                        value=""
                                        disabled
                                    >

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                        <div class="bvc-save">

                            <?php if ($available_count > 0): ?>

                                <span class="bvc-muted">
                                    Only new ballots will be saved.
                                    Existing votes remain unchanged.
                                </span>

                                <button
                                    type="submit"
                                    class="bvc-save-button"
                                >
                                    Save &amp; next
                                </button>

                            <?php else: ?>

                                <span class="bvc-existing">
                                    No motions available for capture.
                                </span>

                            <?php endif; ?>

                        </div>

                    </form>

                </div>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

    <div style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">

        <a
            class="btn btn-navy"
            href="comms_voting.php?action=list&meeting_id=<?= $meeting_id ?>"
        >
            Back to Motions
        </a>

        <a
            class="btn btn-navy"
            href="comms_voting.php?action=meetings_list"
        >
            Back to Meetings
        </a>

    </div>

</div>
</main>

<script>
(function () {
    'use strict';

    const forms = document.querySelectorAll(
        '.bvc-capture-form'
    );

    function selectedForm() {
    return document.querySelector(
        '.bvc-card.active .bvc-capture-form'
    );
}

    function capturableRows(form) {
        return Array.from(
            form.querySelectorAll(
                '.bvc-motion[data-capturable="1"]'
            )
        );
    }

    function selectedChoice(row) {
        const input = row.querySelector(
            '.bvc-vote-value'
        );

        return input && !input.disabled
            ? input.value
            : '';
    }

    function focusRow(row) {
        document.querySelectorAll(
            '.bvc-motion.keyboard-focus'
        ).forEach(function (item) {
            item.classList.remove('keyboard-focus');
        });

        if (!row) {
            return;
        }

        row.classList.add('keyboard-focus');

        row.scrollIntoView({
            block: 'nearest',
            behavior: 'smooth'
        });
    }

    function applyChoice(row, choice) {
        if (!row || row.dataset.capturable !== '1') {
            return;
        }

        const input = row.querySelector(
            '.bvc-vote-value'
        );

        if (!input) {
            return;
        }

        input.disabled = false;
        input.name = input.dataset.fieldName;
        input.value = choice;

        row.querySelectorAll(
            '.bvc-vote-button'
        ).forEach(function (button) {
            button.classList.remove(
                'selected-for',
                'selected-against',
                'selected-abstain'
            );

            const selected =
                button.dataset.choice === choice;

            button.setAttribute(
                'aria-pressed',
                selected ? 'true' : 'false'
            );

            if (selected) {
                button.classList.add(
                    'selected-' + choice
                );
            }
        });

        const form = row.closest(
            '.bvc-capture-form'
        );

        if (!form) {
            return;
        }

        const rows = capturableRows(form);
        const index = rows.indexOf(row);

        const next = rows.find(function (candidate, i) {
            return i > index &&
                selectedChoice(candidate) === '';
        });

        if (next) {
            focusRow(next);
        } else {
            form.querySelector(
                '.bvc-save-button'
            )?.focus();
        }
    }

    function setCaptureType(form, type) {
        if (type !== 'proxy' && type !== 'owner') {
            return;
        }

        const input = form.querySelector(
            '.bvc-capture-method'
        );

        if (input) {
            input.value = type;
        }

        form.querySelectorAll(
            '.bvc-type-button'
        ).forEach(function (button) {
            button.classList.toggle(
                'selected',
                button.dataset.type === type
            );
        });

        const proxyFields = form.querySelector(
            '.bvc-proxy-fields'
        );

        if (proxyFields) {
            proxyFields.hidden = (type !== 'proxy');
        }
    }

    forms.forEach(function (form) {
        form.querySelectorAll(
            '.bvc-type-button'
        ).forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    setCaptureType(
                        form,
                        button.dataset.type
                    );
                }
            );
        });

        form.querySelectorAll(
            '.bvc-vote-button'
        ).forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    const row = button.closest(
                        '.bvc-motion'
                    );

                    applyChoice(
                        row,
                        button.dataset.choice
                    );
                }
            );
        });

        form.querySelectorAll(
            '.bvc-quick-fill'
        ).forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    const choice =
                        button.dataset.choice;

                    capturableRows(form).forEach(
                        function (row) {
                            if (
                                selectedChoice(row) === ''
                            ) {
                                applyChoice(
                                    row,
                                    choice
                                );
                            }
                        }
                    );
                }
            );
        });

        form.addEventListener(
            'submit',
            function (event) {
                const selected = capturableRows(form)
                    .some(function (row) {
                        return selectedChoice(row) !== '';
                    });

                if (!selected) {
                    event.preventDefault();

                    alert(
                        'Please select at least one ' +
                        'uncaptured motion.'
                    );

                    return;
                }

                const method = form.querySelector(
                    '.bvc-capture-method'
                )?.value;

                if (method === 'proxy') {
                    const holder = form.querySelector(
                        'input[name="holder_name"]'
                    )?.value.trim();

                    if (!holder) {
                        event.preventDefault();

                        alert(
                            'Please enter the proxy ' +
                            'holder name.'
                        );

                        return;
                    }
                }

                const saveButton = form.querySelector(
                    '.bvc-save-button'
                );

                if (saveButton) {
                    saveButton.disabled = true;
                    saveButton.textContent = 'Saving...';
                }
            }
        );

        if (form.closest('.bvc-card.active')) {
        const first = capturableRows(form).find(
            function (row) {
                return selectedChoice(row) === '';
            }
    );

    if (first) {
        focusRow(first);
    }
}
    
        
    });

    document.addEventListener(
        'keydown',
        function (event) {
            const active = document.activeElement;

            if (
                active &&
                (
                    active.tagName === 'INPUT' ||
                    active.tagName === 'TEXTAREA' ||
                    active.tagName === 'SELECT'
                )
            ) {
                return;
            }

            const form = selectedForm();

            if (!form) {
                return;
            }

            const row = form.querySelector(
                '.bvc-motion.keyboard-focus'
            );

            const key = event.key.toLowerCase();

            if (key === 'y' && row) {
                event.preventDefault();
                applyChoice(row, 'for');
            }

            if (key === 'n' && row) {
                event.preventDefault();
                applyChoice(row, 'against');
            }

            if (key === 'a' && row) {
                event.preventDefault();
                applyChoice(row, 'abstain');
            }

            if (key === 'p') {
                event.preventDefault();

                const current = form.querySelector(
                    '.bvc-capture-method'
                )?.value;

                setCaptureType(
                    form,
                    current === 'proxy'
                        ? 'owner'
                        : 'proxy'
                );
            }

            if (key === 'enter') {
                const button = form.querySelector(
                    '.bvc-save-button'
                );

                if (button && !button.disabled) {
                    event.preventDefault();
                    form.requestSubmit(button);
                }
            }
        }
    );
})();
</script>

<?php pageFooter(); ?>