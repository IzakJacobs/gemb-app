<?php
/**
 * comms_vote_capture.php
 * Batch capture screen for proxy forms and paper ballots.
 * Linked from comms_voting.php meeting detail page.
 *
 * capture_method values written to vote_cast:
 *   proxy  — proxy form (existing ENUM value)
 *   manual — paper ballot (existing ENUM value)
 *
 * ballot_time — populated for manual (paper ballot) rows only.
 *
 * GET params:
 *   meeting_id  int     required
 *   open        string  erf_number of the expanded card
 *   filter      string  all|proxy|paper|done|incomplete (default: all)
 *   q           string  search — erf number or owner name
 *   sort        string  erf|name (default: erf)
 */

require_once __DIR__ . '/comms_core.php';
commsRequireAuth();
if (function_exists("commsSecurityHeaders")) commsSecurityHeaders();

$db = db();

// ── Resolve meeting ───────────────────────────────────────────────────────────
$meeting_id = (int)($_GET['meeting_id'] ?? 0);
if (!$meeting_id) {
    header('Location: comms_voting.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM meetings WHERE id = ? AND status = 'open'");
$stmt->execute([$meeting_id]);
$meeting = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$meeting) {
    header('Location: comms_voting.php?error=meeting_not_open');
    exit;
}

// ── Load open motions ─────────────────────────────────────────────────────────
$m_stmt = $db->prepare(
    "SELECT id, title, resolution_type, display_order
     FROM motions
     WHERE meeting_id = ? AND status = 'open'
     ORDER BY motion_number ASC"
);
$m_stmt->execute([$meeting_id]);
$motions      = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
$motion_count = count($motions);

if ($motion_count === 0) {
    header('Location: comms_voting.php?meeting_id=' . $meeting_id . '&error=no_open_motions');
    exit;
}

$motion_ids = array_column($motions, 'id');

// Precompute OR/SR labels
$motion_labels = [];
$ord_seq = $sp_seq = 0;
foreach ($motions as $m) {
    $type = strtolower($m['resolution_type'] ?? '');
    if (in_array($type, ['special', 'material', 'moi'])) {
        $sp_seq++;
        $motion_labels[$m['id']] = 'SR ' . $sp_seq;
    } else {
        $ord_seq++;
        $motion_labels[$m['id']] = 'OR ' . $ord_seq;
    }
}

// ── Admin session values ──────────────────────────────────────────────────────
$admin_id   = (int)($_SESSION['comms_user_id'] ?? 0);
$admin_role = 'admin';
$ip         = $_SERVER['REMOTE_ADDR'] ?? '';

// ── POST — save votes for one erf ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_votes'])) {
    verifyCsrfToken();

    $erf         = trim($_POST['erf_number'] ?? '');
    $cap_type    = $_POST['capture_type'] ?? 'proxy'; // 'proxy' or 'manual'
    if (!in_array($cap_type, ['proxy', 'manual'])) $cap_type = 'proxy';

    $holder_erf  = trim($_POST['holder_erf']  ?? '');
    $holder_name = trim($_POST['holder_name'] ?? '');
    $ballot_time = trim($_POST['ballot_time'] ?? '');

    // Validate ballot_time HH:MM
    if ($cap_type === 'manual' && !preg_match('/^\d{2}:\d{2}$/', $ballot_time)) {
        $ballot_time = null;
    } elseif ($cap_type !== 'manual') {
        $ballot_time = null;
    }

    // Confirm erf is in the register
    $chk = $db->prepare(
        "SELECT id FROM vote_eligible_owners WHERE meeting_id = ? AND erf_number = ?"
    );
    $chk->execute([$meeting_id, $erf]);
    if (!$chk->fetch()) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' .
               http_build_query(['meeting_id' => $meeting_id, 'open' => $erf,
                                 'filter' => $_POST['filter'] ?? 'all',
                                 'error'  => 'invalid_erf']));
        exit;
    }

    // For proxy: ensure a vote_proxy row exists per motion (keyed on motion_id)
    // proxy_row_ids maps motion_id => vote_proxy.id
    $proxy_row_ids = [];
    if ($cap_type === 'proxy') {
        foreach ($motions as $motion) {
            $mid = (int)$motion['id'];
            $exists = $db->prepare(
                "SELECT id FROM vote_proxy WHERE motion_id = ? AND erf_number = ?"
            );
            $exists->execute([$mid, $erf]);
            $existing_proxy = $exists->fetch(PDO::FETCH_ASSOC);

            if ($existing_proxy) {
                $proxy_row_ids[$mid] = (int)$existing_proxy['id'];
                // Update holder details if provided
                if ($holder_erf !== '' || $holder_name !== '') {
                    $db->prepare(
                        "UPDATE vote_proxy
                         SET proxy_holder = ?, proxy_holder_erf = ?
                         WHERE id = ?"
                    )->execute([
                        $holder_name ?: $existing_proxy['proxy_holder'] ?? '',
                        $holder_erf  ?: null,
                        $existing_proxy['id'],
                    ]);
                }
            } else {
                $db->prepare(
                    "INSERT INTO vote_proxy
                     (motion_id, erf_number, proxy_holder, proxy_holder_erf,
                      captured_by, notes)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([
                    $mid,
                    $erf,
                    $holder_name ?: 'Batch capture',
                    $holder_erf  ?: null,
                    $admin_id,
                    'Batch proxy capture',
                ]);
                $proxy_row_ids[$mid] = (int)$db->lastInsertId();
            }
        }
    }

    // Save each submitted motion vote
    foreach ($motions as $motion) {
        $mid    = (int)$motion['id'];
        $field  = 'vote_' . $mid;
        $choice = $_POST[$field] ?? '';
        if (!in_array($choice, ['for', 'against', 'abstain'])) continue;

        // Skip if already recorded
        $dup = $db->prepare(
            "SELECT id FROM vote_cast WHERE motion_id = ? AND erf_number = ?"
        );
        $dup->execute([$mid, $erf]);
        if ($dup->fetch()) continue;

        $db->beginTransaction();
        try {
            // vote_cast — participation record
            $ins = $db->prepare(
                "INSERT INTO vote_cast
                 (motion_id, erf_number, capture_method, cast_by_role, cast_by_id,
                  proxy_id, ip_address, cast_at, ballot_time)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)"
            );
            $ins->execute([
                $mid,
                $erf,
                $cap_type,          // 'proxy' or 'manual'
                $admin_role,        // 'admin'
                $admin_id,
                $proxy_row_ids[$mid] ?? null,  // NULL for paper ballots
                $ip,
                $ballot_time,       // NULL for proxy rows
            ]);
            $cast_id = (int)$db->lastInsertId();

            // vote_tally — choice record (secret ballot separation)
            $db->prepare(
                "INSERT INTO vote_tally (cast_id, vote_option) VALUES (?, ?)"
            )->execute([$cast_id, $choice]);

            // device_response_locks — block duplicate online vote for this erf/motion
            $db->prepare(
                "INSERT IGNORE INTO device_response_locks
                 (type, target_id, device_id, created_at)
                 VALUES ('vote', ?, ?, NOW())"
            )->execute([$mid, 'admin_capture_' . $erf]);

            // vote_admin_log
            $event = ($cap_type === 'proxy') ? 'proxy_capture' : 'paper_capture';
            $db->prepare(
                "INSERT INTO vote_admin_log
                 (meeting_id, motion_id, erf_number, event_type, admin_id, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())"
            )->execute([$meeting_id, $mid, $erf, $event, $admin_id, $ip]);

            $db->commit();

        } catch (Exception $e) {
            $db->rollBack();
            error_log('vote_capture: motion=' . $mid . ' erf=' . $erf . ' ' . $e->getMessage());
        }
    }

    // Redirect to next uncompleted erf
    $next = nextUncompletedErf($db, $meeting_id, $motion_ids, $erf);
    header('Location: comms_vote_capture.php?' . http_build_query([
        'meeting_id' => $meeting_id,
        'open'       => $next,
        'filter'     => $_POST['filter'] ?? 'all',
        'q'          => $_POST['q']      ?? '',
        'sort'       => $_POST['sort']   ?? 'erf',
    ]));
    exit;
}

// ── Next uncompleted erf helper ───────────────────────────────────────────────
function nextUncompletedErf(PDO $db, int $meeting_id, array $motion_ids, string $after_erf): string
{
    if (!$motion_ids) return '';
    $ph     = implode(',', array_fill(0, count($motion_ids), '?'));
    $params = array_merge($motion_ids, [$meeting_id, count($motion_ids)]);
    $stmt   = $db->prepare(
        "SELECT veo.erf_number
         FROM vote_eligible_owners veo
         LEFT JOIN vote_cast vc
           ON vc.erf_number = veo.erf_number AND vc.motion_id IN ($ph)
         WHERE veo.meeting_id = ?
         GROUP BY veo.erf_number
         HAVING COUNT(vc.id) < ?
         ORDER BY veo.erf_number ASC"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($rows as $erf) {
        if ($erf > $after_erf) return $erf;
    }
    return $rows[0] ?? ''; // wrap to first if nothing found after
}

// ── Query parameters ──────────────────────────────────────────────────────────
$filter   = in_array($_GET['filter'] ?? '', ['all','proxy','paper','done','incomplete'])
            ? $_GET['filter'] : 'all';
$q        = trim($_GET['q'] ?? '');
$sort     = ($_GET['sort'] ?? 'erf') === 'name' ? 'owner_name' : 'erf_number';
$open_erf = trim($_GET['open'] ?? '');

// ── Load register with vote status ───────────────────────────────────────────
$ph     = implode(',', array_fill(0, count($motion_ids), '?'));
$params = array_merge($motion_ids, [$meeting_id]);

$where = 'veo.meeting_id = ?';
if ($q !== '') {
    $where   .= " AND (veo.erf_number LIKE ? OR veo.owner_name LIKE ?)";
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

$rows = $db->prepare(
    "SELECT veo.erf_number,
            veo.owner_name,
            MIN(vp.id)              AS proxy_row_id,
            MIN(vp.proxy_holder_erf) AS holder_erf,
            MIN(vp.proxy_holder)     AS holder_name,
            COUNT(DISTINCT vc.id)   AS votes_cast,
            GROUP_CONCAT(DISTINCT vc.motion_id ORDER BY vc.motion_id SEPARATOR ',') AS voted_mids,
            GROUP_CONCAT(vt.vote_option ORDER BY vc.motion_id SEPARATOR ',')        AS voted_choices,
            MAX(vc.capture_method)  AS capture_method,
            MAX(vc.ballot_time)     AS ballot_time
     FROM vote_eligible_owners veo
     LEFT JOIN vote_cast vc
       ON vc.erf_number = veo.erf_number AND vc.motion_id IN ($ph)
     LEFT JOIN vote_tally vt
       ON vt.cast_id = vc.id
     LEFT JOIN vote_proxy vp
       ON vp.erf_number = veo.erf_number
      AND vp.motion_id IN (SELECT id FROM motions WHERE meeting_id = ?)
     WHERE $where
     GROUP BY veo.erf_number, veo.owner_name
     ORDER BY veo.$sort ASC"
);
$rows->execute(array_merge($params, [$meeting_id]));
$all_owners = $rows->fetchAll(PDO::FETCH_ASSOC);

// Enrich each row
foreach ($all_owners as &$o) {
    // Build vote map: motion_id => choice
    $o['vote_map'] = [];
    if ($o['voted_mids']) {
        $mids    = explode(',', $o['voted_mids']);
        $choices = explode(',', $o['voted_choices']);
        foreach ($mids as $i => $mid) {
            $o['vote_map'][(int)$mid] = $choices[$i] ?? '';
        }
    }
    $cast           = (int)$o['votes_cast'];
    $o['is_done']       = $cast === $motion_count;
    $o['is_incomplete'] = $cast > 0 && !$o['is_done'];
    // display_type: if a proxy row exists or capture_method = proxy → proxy; else paper
    $o['display_type']  = ($o['proxy_row_id'] || $o['capture_method'] === 'proxy')
                          ? 'proxy' : 'paper';
}
unset($o);

// Stats (always from full unfiltered list)
$total_proxy      = count(array_filter($all_owners, fn($o) => $o['display_type'] === 'proxy'));
$total_paper      = count(array_filter($all_owners, fn($o) => $o['display_type'] === 'paper'));
$total_done       = count(array_filter($all_owners, fn($o) => $o['is_done']));
$total_left       = count($all_owners) - $total_done;
$total_incomplete = count(array_filter($all_owners, fn($o) => $o['is_incomplete']));

// Apply tab filter
$owners = array_values(array_filter($all_owners, fn($o) => match ($filter) {
    'proxy'      => $o['display_type'] === 'proxy',
    'paper'      => $o['display_type'] === 'paper',
    'done'       => $o['is_done'],
    'incomplete' => $o['is_incomplete'],
    default      => true,
}));

// Auto-open first uncompleted if none specified
if ($open_erf === '') {
    foreach ($owners as $o) {
        if (!$o['is_done']) { $open_erf = $o['erf_number']; break; }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────


function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function buildUrl(array $over = []): string
{
    global $meeting_id, $filter, $q, $sort, $open_erf;
    $sort_param = ($sort === 'owner_name') ? 'name' : 'erf';
    return 'comms_vote_capture.php?' . http_build_query(array_merge([
        'meeting_id' => $meeting_id,
        'filter'     => $filter,
        'q'          => $q,
        'sort'       => $sort_param,
        'open'       => $open_erf,
    ], $over));
}

<?php
pageHeader('Batch Vote Capture', 'admin');
renderHeader('✏️ Batch Vote Capture — ' . e($meeting['title']), 'comms_voting.php?action=meetings_list');
?>
<style>
    .capture-wrap { max-width: 860px; margin: 0 auto; padding: 1rem; }

    /* Header */
    .page-hdr { background: var(--surface-card); border: 1px solid var(--border); border-radius: 10px; padding: 1rem 1.25rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: .75rem; }
    .page-hdr h1 { font-size: 1.1rem; font-weight: 600; margin: 0 0 .2rem; }
    .page-hdr p  { font-size: .85rem; color: var(--text-muted); margin: 0; }

    .stat-strip { display: flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .stat-cell  { padding: .4rem 1rem; text-align: center; border-right: 1px solid var(--border); min-width: 60px; }
    .stat-cell:last-child { border-right: none; }
    .stat-n { font-size: 1.15rem; font-weight: 600; line-height: 1; }
    .stat-l { font-size: .68rem; color: var(--text-muted); margin-top: 2px; text-transform: uppercase; letter-spacing: .04em; }
    .n-proxy { color: #b97a00; } .n-paper { color: #1a6db5; }
    .n-done  { color: #2e7d32; } .n-left  { color: #c62828; }

    /* Tabs */
    .tab-row   { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: .6rem; }
    .tab-row a { padding: 5px 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--surface-card); font-size: .8rem; color: var(--text-muted); text-decoration: none; }
    .tab-row a.active { background: #e3f0fb; border-color: #90c3e8; color: #1a5a8a; font-weight: 600; }

    /* Search */
    .search-row { display: flex; gap: 6px; margin-bottom: .6rem; align-items: center; flex-wrap: wrap; }
    .search-row input[type=text] { flex: 1; min-width: 160px; font-size: .9rem; }

    /* Keyboard tip */
    .kbd-tip     { background: #e8f4fd; border: 1px solid #90c3e8; border-radius: 6px; padding: 7px 12px; font-size: .8rem; color: #1a5a8a; margin-bottom: .75rem; }
    .kbd-tip kbd { background: #fff; border: 1px solid #90c3e8; border-radius: 3px; padding: 1px 5px; font-family: monospace; font-size: .78rem; }

    /* Cards */
    .erf-card { border: 1px solid var(--border); border-radius: 10px; margin-bottom: 6px; overflow: hidden; background: var(--surface-card); }
    .erf-card.state-done       { border-color: #81c784; }
    .erf-card.state-active     { border-color: #64b5f6; }
    .erf-card.state-incomplete { border-color: #e57373; }

    .card-hdr { padding: 9px 1rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; text-decoration: none; color: inherit; cursor: pointer; }
    .card-hdr.bg-done       { background: #f1f9f1; }
    .card-hdr.bg-active     { background: #e8f4fd; }
    .card-hdr.bg-incomplete { background: #fdf1f1; }

    .card-erf  { font-size: .95rem; font-weight: 700; min-width: 72px; }
    .card-name { font-size: .85rem; color: var(--text-muted); flex: 1; min-width: 100px; }
    .holder-sum { font-size: .75rem; color: var(--text-muted); white-space: nowrap; }

    .badge       { font-size: .7rem; padding: 2px 7px; border-radius: 5px; font-weight: 600; white-space: nowrap; }
    .badge-proxy { background: #fff3cd; color: #7a5500; border: 1px solid #ffe08a; }
    .badge-paper { background: #dbeafe; color: #1a4a7a; border: 1px solid #93c5fd; }
    .badge-done  { background: #d4edda; color: #1a4d2a; border: 1px solid #81c784; }
    .badge-warn  { background: #fde8e8; color: #7a1a1a; border: 1px solid #e57373; }

    .dots { display: flex; gap: 3px; align-items: center; }
    .dot            { width: 9px; height: 9px; border-radius: 50%; border: 1px solid #ccc; background: #f0f0f0; }
    .dot-for        { background: #2e7d32; border-color: #2e7d32; }
    .dot-against    { background: #c62828; border-color: #c62828; }
    .dot-abstain    { background: #757575; border-color: #757575; }

    .prog-txt { font-size: .75rem; color: var(--text-muted); white-space: nowrap; }

    /* Card body */
    .card-body { border-top: 1px solid var(--border); }

    /* Proxy holder strip */
    .proxy-strip       { padding: 9px 1rem; background: #fffbeb; border-bottom: 1px solid #ffe08a; }
    .proxy-strip-label { font-size: .7rem; color: #7a5500; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin: 0 0 6px; }
    .proxy-fields      { display: grid; grid-template-columns: 1fr 1.6fr; gap: 6px; }
    .proxy-fields label { display: block; font-size: .75rem; color: var(--text-muted); margin-bottom: 3px; }
    .proxy-fields input { font-size: .9rem; width: 100%; }

    /* Paper ballot time strip */
    .time-strip       { padding: 9px 1rem; background: var(--surface-bg); border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .time-strip label { font-size: .85rem; color: var(--text-muted); flex: 1; }
    .time-strip input[type=time] { font-size: .9rem; padding: 4px 8px; width: 130px; }

    /* Quick fill */
    .quick-row   { display: flex; align-items: center; gap: 5px; padding: 7px 1rem; background: var(--surface-bg); border-bottom: 1px solid var(--border); flex-wrap: wrap; }
    .quick-label { font-size: .78rem; color: var(--text-muted); flex: 1; min-width: 80px; }
    .quick-btn          { padding: 4px 9px; border-radius: 5px; border: 1px solid var(--border); background: var(--surface-card); font-size: .78rem; cursor: pointer; font-weight: 600; }
    .quick-btn-for      { color: #2e7d32; border-color: #81c784; }
    .quick-btn-against  { color: #c62828; border-color: #e57373; }
    .quick-btn-abstain  { color: #757575; }

    /* Motion divider */
    .motion-divider { padding: 4px 1rem; background: var(--surface-bg); border-bottom: 1px solid var(--border); font-size: .7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }

    /* Motion rows */
    .motion-row          { display: flex; align-items: center; padding: 8px 1rem; border-bottom: 1px solid var(--border); gap: 8px; }
    .motion-row.voted    { background: #fafafa; }
    .motion-row.kb-focus { outline: 2px solid #64b5f6; outline-offset: -2px; border-radius: 4px; }
    .motion-row:last-of-type { border-bottom: none; }
    .motion-num  { font-size: .8rem; font-weight: 700; color: var(--text-muted); min-width: 36px; }
    .motion-text { font-size: .85rem; color: var(--text-body); flex: 1; line-height: 1.4; }
    .vote-btns   { display: flex; gap: 3px; flex-shrink: 0; }

    /* Vote buttons */
    .vb     { padding: 6px 10px; border-radius: 5px; border: 1px solid #ccc; background: var(--surface-card); font-size: .8rem; cursor: pointer; color: #555; font-weight: 600; white-space: nowrap; }
    .vb:hover { opacity: .85; }
    .vbf    { background: #d4edda; border-color: #81c784; color: #1a4d2a; }
    .vba    { background: #fde8e8; border-color: #e57373; color: #7a1a1a; }
    .vbab   { background: #f0f0f0; border-color: #aaa;    color: #555; }

    /* Save row */
    .save-row    { padding: 9px 1rem; display: flex; justify-content: flex-end; align-items: center; gap: 8px; border-top: 1px solid var(--border); flex-wrap: wrap; }
    .save-note   { font-size: .78rem; color: var(--text-muted); flex: 1; }
    .btn-save    { font-size: .85rem; padding: 7px 16px; background: #1a6db5; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
    .btn-save:hover { background: #155a96; }
    .btn-discard { font-size: .85rem; padding: 7px 12px; }

    @media (max-width: 600px) {
      .proxy-fields { grid-template-columns: 1fr; }
      .vote-btns    { flex-wrap: wrap; }
      .stat-cell    { padding: .4rem .5rem; min-width: 48px; }
    }
  </style>

<main class="pc-main">
<div class="capture-wrap">

  <!-- Page header + stats -->
  <div class="page-hdr">
    <div>
      <h1>Batch vote capture</h1>
      <p>
        <?= e($meeting['title']) ?>
        <?= !empty($meeting['meeting_date']) ? ' · ' . date('j F Y', strtotime($meeting['meeting_date'])) : '' ?>
        · <?= $motion_count ?> motion<?= $motion_count !== 1 ? 's' : '' ?> open
      </p>
    </div>
    <div class="stat-strip">
      <div class="stat-cell"><div class="stat-n n-proxy"><?= $total_proxy ?></div><div class="stat-l">Proxies</div></div>
      <div class="stat-cell"><div class="stat-n n-paper"><?= $total_paper ?></div><div class="stat-l">Paper</div></div>
      <div class="stat-cell"><div class="stat-n n-done"><?= $total_done  ?></div><div class="stat-l">Done</div></div>
      <div class="stat-cell"><div class="stat-n n-left"><?= $total_left  ?></div><div class="stat-l">Left</div></div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="tab-row">
    <?php foreach ([
        'all'        => 'All (' . count($all_owners) . ')',
        'proxy'      => 'Proxies ('     . $total_proxy      . ')',
        'paper'      => 'Paper ('       . $total_paper      . ')',
        'done'       => 'Done ('        . $total_done       . ')',
        'incomplete' => 'Incomplete ('  . $total_incomplete . ')',
    ] as $key => $label): ?>
      <a href="<?= e(buildUrl(['filter' => $key, 'open' => ''])) ?>"
         class="<?= $filter === $key ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Search + sort -->
  <form method="GET" action="comms_vote_capture.php" class="search-row">
    <input type="hidden" name="meeting_id" value="<?= $meeting_id ?>">
    <input type="hidden" name="filter"     value="<?= e($filter) ?>">
    <input type="hidden" name="open"       value="">
    <input type="text"   name="q"          value="<?= e($q) ?>"
           placeholder="Erf number or owner name…" autocomplete="off">
    <input type="hidden" name="sort" value="<?= e($sort === 'owner_name' ? 'name' : 'erf') ?>">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?>
      <a href="<?= e(buildUrl(['q' => '', 'open' => ''])) ?>" style="font-size:.8rem; padding:5px 8px;">Clear</a>
    <?php endif; ?>
    <a href="<?= e(buildUrl(['sort' => $sort === 'owner_name' ? 'erf' : 'name', 'open' => ''])) ?>"
       style="font-size:.8rem; padding:5px 10px; border:1px solid var(--border); border-radius:6px; background:var(--surface-card); text-decoration:none; white-space:nowrap;">
      Sort: <?= $sort === 'owner_name' ? 'Name ↕' : 'Erf ↕' ?>
    </a>
  </form>

  <!-- Keyboard shortcut reminder -->
  <div class="kbd-tip">
    <kbd>Y</kbd> For &nbsp;·&nbsp;
    <kbd>N</kbd> Against &nbsp;·&nbsp;
    <kbd>A</kbd> Abstain &nbsp;·&nbsp;
    <kbd>Enter</kbd> Save &amp; next &nbsp;·&nbsp;
    <kbd>Tab</kbd> Next motion
  </div>

  <!-- Card list -->
  <?php foreach ($owners as $owner):
    $erf         = $owner['erf_number'];
    $is_open     = ($erf === $open_erf);
    $is_done     = $owner['is_done'];
    $is_inc      = $owner['is_incomplete'];
    $cap_type    = $owner['display_type']; // 'proxy' or 'paper'
    $vote_map    = $owner['vote_map'];
    $votes_cast  = (int)$owner['votes_cast'];

    $card_state  = $is_done ? 'state-done' : ($is_open ? 'state-active' : ($is_inc ? 'state-incomplete' : ''));
    $hdr_bg      = $is_done ? 'bg-done'    : ($is_open ? 'bg-active'    : ($is_inc ? 'bg-incomplete'    : ''));
    $toggle_url  = $is_open ? buildUrl(['open' => '']) : buildUrl(['open' => $erf]);
  ?>
  <div class="erf-card <?= $card_state ?>" id="card-<?= e($erf) ?>">

    <!-- Card header (always visible, click to toggle) -->
    <a href="<?= e($toggle_url) ?>" class="card-hdr <?= $hdr_bg ?>">
      <span class="card-erf"><?= e($erf) ?></span>

      <span class="badge <?= $cap_type === 'paper' ? 'badge-paper' : 'badge-proxy' ?>">
        <?= $cap_type === 'paper' ? 'Paper' : 'Proxy' ?>
      </span>

      <span class="card-name"><?= e($owner['owner_name']) ?></span>

      <?php if ($is_done && !empty($owner['holder_erf'])): ?>
        <span class="holder-sum">
          Holder: <?= e($owner['holder_erf']) ?> · <?= e($owner['holder_name'] ?? '') ?>
        </span>
      <?php endif; ?>

      <!-- One dot per motion -->
      <div class="dots">
        <?php foreach ($motions as $m):
          $mid    = (int)$m['id'];
          $choice = $vote_map[$mid] ?? '';
          $dc = match($choice) {
              'for'     => 'dot dot-for',
              'against' => 'dot dot-against',
              'abstain' => 'dot dot-abstain',
              default   => 'dot',
          };
        ?>
          <div class="<?= $dc ?>"
               title="<?= e($motion_labels[$mid]) ?>: <?= $choice !== '' ? ucfirst($choice) : 'Not captured' ?>">
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($is_done): ?>
        <span class="badge badge-done">✓ Saved</span>
      <?php elseif ($is_inc): ?>
        <span class="badge badge-warn">⚠ <?= $votes_cast ?>/<?= $motion_count ?> — incomplete</span>
      <?php elseif (!$is_open): ?>
        <span class="prog-txt">0/<?= $motion_count ?> — tap to open</span>
      <?php endif; ?>
    </a>

    <!-- Expanded body -->
    <?php if ($is_open): ?>
    <div class="card-body">
      <form method="POST"
            action="comms_vote_capture.php?meeting_id=<?= $meeting_id ?>"
            id="capture-form">
        <?= csrfField() ?>
        <input type="hidden" name="save_votes"  value="1">
        <input type="hidden" name="erf_number"  value="<?= e($erf) ?>">
        <input type="hidden" name="capture_type" value="<?= $cap_type === 'paper' ? 'manual' : 'proxy' ?>">
        <input type="hidden" name="filter"       value="<?= e($filter) ?>">
        <input type="hidden" name="q"            value="<?= e($q) ?>">
        <input type="hidden" name="sort"         value="<?= e($sort === 'owner_name' ? 'name' : 'erf') ?>">

        <?php if ($cap_type === 'proxy'): ?>
        <!-- Proxy holder strip -->
        <div class="proxy-strip">
          <p class="proxy-strip-label">Proxy holder — from signed form</p>
          <div class="proxy-fields">
            <div>
              <label for="h_erf_<?= e($erf) ?>">Erf number</label>
              <input type="text"
                     id="h_erf_<?= e($erf) ?>"
                     name="holder_erf"
                     value="<?= e($owner['holder_erf'] ?? '') ?>"
                     placeholder="e.g. E15198"
                     autocomplete="off">
            </div>
            <div>
              <label for="h_name_<?= e($erf) ?>">Full name</label>
              <input type="text"
                     id="h_name_<?= e($erf) ?>"
                     name="holder_name"
                     value="<?= e($owner['holder_name'] ?? '') ?>"
                     placeholder="as written on form"
                     autocomplete="off">
            </div>
          </div>
        </div>

        <?php else: ?>
        <!-- Paper ballot time strip -->
        <div class="time-strip">
          <label for="bt_<?= e($erf) ?>">Time ballot handed in</label>
          <input type="time"
                 id="bt_<?= e($erf) ?>"
                 name="ballot_time"
                 value="<?= e($owner['ballot_time'] ?? date('H:i')) ?>">
          <button type="button"
                  onclick="document.getElementById('bt_<?= e($erf) ?>').value=nowTime()">
            Now
          </button>
        </div>
        <?php endif; ?>

        <!-- Quick fill -->
        <div class="quick-row">
          <span class="quick-label">Fill all remaining:</span>
          <button type="button" class="quick-btn quick-btn-for"
                  onclick="quickFill('for')">All For</button>
          <button type="button" class="quick-btn quick-btn-against"
                  onclick="quickFill('against')">All Against</button>
          <button type="button" class="quick-btn quick-btn-abstain"
                  onclick="quickFill('abstain')">All Abstain</button>
        </div>

        <!-- Motion rows -->
        <?php
        $last_section = '';
        $row_index    = 0;
        foreach ($motions as $m):
          $mid     = (int)$m['id'];
          $label   = $motion_labels[$mid];
          $choice  = $vote_map[$mid] ?? '';
          $voted   = $choice !== '';
          $section = str_starts_with($label, 'SR')
                     ? 'Special resolutions' : 'Ordinary resolutions';
          if ($section !== $last_section):
            $last_section = $section;
        ?>
        <div class="motion-divider"><?= e($section) ?></div>
        <?php endif; ?>

        <div class="motion-row <?= $voted ? 'voted' : '' ?>"
             data-motion-index="<?= $row_index ?>"
             data-motion-id="<?= $mid ?>">
          <span class="motion-num"><?= e($label) ?></span>
          <span class="motion-text"><?= e($m['title']) ?></span>
          <div class="vote-btns">
            <button type="submit" name="vote_<?= $mid ?>" value="for"
                    class="vb <?= $choice === 'for' ? 'vbf' : '' ?>">Y For</button>
            <button type="submit" name="vote_<?= $mid ?>" value="against"
                    class="vb <?= $choice === 'against' ? 'vba' : '' ?>">N Against</button>
            <button type="submit" name="vote_<?= $mid ?>" value="abstain"
                    class="vb <?= $choice === 'abstain' ? 'vbab' : '' ?>">A Abstain</button>
          </div>
        </div>
        <?php $row_index++; endforeach; ?>

        <!-- Save row -->
        <div class="save-row">
          <span class="save-note">🔒 Written to audit trail on save</span>
          <a href="<?= e(buildUrl(['open' => ''])) ?>" class="btn-discard">Discard</a>
          <button type="submit" name="save_all" value="1" class="btn-save">
            Save &amp; next →
          </button>
        </div>

      </form>
    </div>
    <?php endif; // is_open ?>

  </div><!-- .erf-card -->
  <?php endforeach; ?>

  <?php if (empty($owners)): ?>
    <p style="text-align:center; color:var(--text-muted); padding:2rem 0;">
      No entries match<?= $q !== '' ? ' your search' : ' this filter' ?>.
    </p>
  <?php endif; ?>

</div><!-- .capture-wrap -->

<script>
function nowTime() {
  const d = new Date();
  return String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0');
}

// Quick fill — only fills rows not already voted
function quickFill(choice) {
  document.querySelectorAll('.motion-row').forEach(row => {
    const btns = row.querySelectorAll('button[name^="vote_"]');
    const alreadyVoted = [...btns].some(b =>
      b.classList.contains('vbf') || b.classList.contains('vba') || b.classList.contains('vbab')
    );
    if (alreadyVoted) return;

    const mid = row.dataset.motionId;
    btns.forEach(b => {
      b.classList.remove('vbf','vba','vbab');
      if (b.value === choice) {
        b.classList.add(choice === 'for' ? 'vbf' : choice === 'against' ? 'vba' : 'vbab');
      }
    });
    setHidden('vote_' + mid, choice);
  });
}

// Set or replace a hidden input on the capture form
function setHidden(name, value) {
  const form = document.getElementById('capture-form');
  let h = form.querySelector('input[type=hidden][name="' + name + '"]');
  if (!h) {
    h = document.createElement('input');
    h.type = 'hidden';
    h.name = name;
    form.appendChild(h);
  }
  h.value = value;
}

// Keyboard shortcuts
(function () {
  const rows = Array.from(document.querySelectorAll('.motion-row'));
  if (!rows.length) return;

  // Start focus on first unvoted row
  let fi = rows.findIndex(r => {
    const btns = r.querySelectorAll('button[name^="vote_"]');
    return ![...btns].some(b =>
      b.classList.contains('vbf') || b.classList.contains('vba') || b.classList.contains('vbab')
    );
  });
  if (fi < 0) fi = 0;
  setFocus(fi);

  function setFocus(idx) {
    rows.forEach(r => r.classList.remove('kb-focus'));
    if (rows[idx]) rows[idx].classList.add('kb-focus');
    fi = idx;
  }

  function applyVote(choice) {
    const row  = rows[fi];
    if (!row) return;
    const mid  = row.dataset.motionId;
    const btns = row.querySelectorAll('button[name^="vote_"]');

    btns.forEach(b => {
      b.classList.remove('vbf','vba','vbab');
      if (b.value === choice) {
        b.classList.add(choice === 'for' ? 'vbf' : choice === 'against' ? 'vba' : 'vbab');
      }
    });
    setHidden('vote_' + mid, choice);

    // Advance to next unvoted row
    const next = rows.findIndex((r, i) => {
      if (i <= fi) return false;
      const rb = r.querySelectorAll('button[name^="vote_"]');
      return ![...rb].some(b =>
        b.classList.contains('vbf') || b.classList.contains('vba') || b.classList.contains('vbab')
      );
    });
    if (next >= 0) setFocus(next);
  }

  document.addEventListener('keydown', e => {
    const tag = document.activeElement.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

    switch (e.key.toLowerCase()) {
      case 'y':     applyVote('for');     break;
      case 'n':     applyVote('against'); break;
      case 'a':     applyVote('abstain'); break;
      case 'tab':
        e.preventDefault();
        setFocus((fi + 1) % rows.length);
        break;
      case 'enter':
        e.preventDefault();
        document.querySelector('button[name="save_all"]')?.click();
        break;
    }
  });
})();
</script>

</div><!-- .capture-wrap -->
</main>

<?php pageFooter();