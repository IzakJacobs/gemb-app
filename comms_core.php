<?php
// ============================================================
// GEMB / gemB — comms_core.php
// ============================================================
// Shared core for the Communications module.
//
// Used by: comms.php, comms_bulk.php, comms_levy.php,
//          comms_surveys.php, comms_voting.php, comms_archive.php
//
// Provides:
//   - commsRequireAuth()      auth adapter (embedded admin OR
//                              standalone comms login)
//   - commsRecipients()        recipient resolution by audience
//   - commsSendEmail()         unified HTML/plain-text mailer
//   - commsLog() / commsBroadcastCreate() / commsBroadcastUpdateCounts()
//                               unified send-log + reporting
//   - commsHandleUpload()       shared file-upload validation/storage
//   - commsParseCsv()           flexible CSV parser (BOM/delimiter aware)
//
// Schema additions (see comms_schema.sql):
//   comms_broadcasts   — superset of document_broadcasts, +channel column
//   comms_send_log     — superset of document_send_log,  +channel column
// ============================================================

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/twilio_helper.php';   // sendEmail() plain-text fallback

if (session_status() === PHP_SESSION_NONE) session_start();


// ────────────────────────────────────────────────────────────
// AUTH ADAPTER
// ────────────────────────────────────────────────────────────
//
// Embedded mode: admin.php sets $_SESSION['admin_logged_in'] as normal.
//   Comms pages accept that session directly — no separate login needed.
//
// Standalone mode: comms_login.php sets $_SESSION['comms_logged_in']
//   (+ $_SESSION['comms_user'] for display) against the comms_users table.
//
// Either flag is sufficient. If neither is set, redirect to comms_login.php
// (which itself offers a link back to admin.php for embedded deployments).
// ────────────────────────────────────────────────────────────
function commsRequireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (!empty($_SESSION['admin_id']) || !empty($_SESSION['comms_logged_in'])) {
        return;
    }

    header('Location: comms_login.php');
    exit;
}

// True if running inside the GEMB admin suite (shows "back to admin menu" links etc.)
function commsIsEmbedded(): bool {
    return !empty($_SESSION['admin_id']);
}

// Display name for "sent by" / page header — works for either session type.
function commsCurrentUser(): string {
    return $_SESSION['admin_name']
        ?? $_SESSION['comms_user']
        ?? 'System';
}

// Numeric id for sent_by columns — comms_users.id is stored as a negative
// range or separate column historically; for now just store admin_id when
// available, else null (standalone comms_users id stored separately).
function commsCurrentUserId(): ?int {
    return $_SESSION['admin_id'] ?? $_SESSION['comms_user_id'] ?? null;
}


// ────────────────────────────────────────────────────────────
// RECIPIENT RESOLUTION
// ────────────────────────────────────────────────────────────
//
// $audience:
//   'all_residents'        — every active primary resident with an email
//                             (Bulk messages)
//   'vote_eligible_owners'  — owners registered for a specific meeting
//                             (Voting / AGM-restricted Surveys)
//                             requires $meetingId
//   'levy_csv'              — parsed from an uploaded CSV (Personalised /
//                             levy statements) — see commsParseCsv()
//
// Returns array of ['email' => ..., 'name' => ..., 'erf' => ...|null, 'meta' => [...]]
// ────────────────────────────────────────────────────────────
function commsRecipients(string $audience, array $opts = []): array {
    switch ($audience) {

        case 'all_residents':
            $rows = db()->query("
                SELECT DISTINCT email, resident_name, resident_erfno
                FROM residents
                WHERE status = 'active'
                  AND email IS NOT NULL
                  AND email != ''
                  AND is_primary = 1
            ")->fetchAll();

            return array_map(static fn($r) => [
                'email' => $r['email'],
                'name'  => $r['resident_name'] ?? 'Resident',
                'erf'   => $r['resident_erfno'] ?? null,
                'meta'  => [],
            ], $rows);

        case 'comms_contacts':
            // Standalone contact list — independent of residents or any other table.
            // Populated via CSV import in comms_contacts.php.
            $groupTag = $opts['group_tag'] ?? null;
            if ($groupTag !== null && $groupTag !== '') {
                $stmt = db()->prepare("
                    SELECT email, name, erf, phone
                    FROM comms_contacts
                    WHERE active = 1 AND group_tag = ?
                    ORDER BY name, email
                ");
                $stmt->execute([$groupTag]);
            } else {
                $stmt = db()->query("
                    SELECT email, name, erf, phone
                    FROM comms_contacts
                    WHERE active = 1
                    ORDER BY name, email
                ");
            }
            return array_map(static fn($r) => [
                'email' => $r['email'],
                'name'  => $r['name'] ?? 'Contact',
                'erf'   => $r['erf']  ?? null,
                'meta'  => ['phone' => $r['phone'] ?? null],
            ], $stmt->fetchAll());

        case 'vote_eligible_owners':
            $meetingId = (int)($opts['meeting_id'] ?? 0);
            if (!$meetingId) return [];

            $stmt = db()->prepare("
                SELECT erf_number, owner_name, owner_email, owner_phone
                FROM vote_eligible_owners
                WHERE meeting_id = ?
            ");
            $stmt->execute([$meetingId]);

            return array_map(static fn($r) => [
                'email' => $r['owner_email'],
                'name'  => $r['owner_name'] ?? 'Owner',
                'erf'   => $r['erf_number'] ?? null,
                'meta'  => ['phone' => $r['owner_phone'] ?? null],
            ], $stmt->fetchAll());

        case 'levy_csv':
            // Caller passes pre-parsed rows from commsParseCsv().
            $rows = $opts['rows'] ?? [];
            $out  = [];
            foreach ($rows as $i => $row) {
                $rawEmail = '';
                foreach ($row as $col => $val) {
                    if (in_array(strtolower(trim((string)$col)),
                            ['email','e-mail','e_mail','email address','emailaddress','mail'], true)) {
                        $rawEmail = trim((string)$val);
                        break;
                    }
                }
                if (!$rawEmail) {
                    foreach ($row as $val) {
                        if (strpos((string)$val, '@') !== false) { $rawEmail = trim((string)$val); break; }
                    }
                }

                $email = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);

                // Try to find a name and amount column flexibly too
                $name = '';
                foreach ($row as $col => $val) {
                    if (in_array(strtolower(trim((string)$col)), ['name','resident','resident_name','owner','owner_name'], true)) {
                        $name = trim((string)$val);
                        break;
                    }
                }
                $amount = '';
                foreach ($row as $col => $val) {
                    if (in_array(strtolower(trim((string)$col)), ['amount','amount due','levy','levy amount','total'], true)) {
                        $amount = trim((string)$val);
                        break;
                    }
                }
                $erf = '';
                foreach ($row as $col => $val) {
                    if (in_array(strtolower(trim((string)$col)), ['erf','erf number','erf_number','erfno','resident_erfno'], true)) {
                        $erf = trim((string)$val);
                        break;
                    }
                }

                $out[] = [
                    'email'      => $email ?: null,
                    'raw_email'  => $rawEmail,
                    'name'       => $name,
                    'erf'        => $erf ?: null,
                    'row_index'  => $i,
                    'meta'       => ['amount' => $amount, 'raw_row' => $row],
                ];
            }
            return $out;

        default:
            return [];
    }
}


// ────────────────────────────────────────────────────────────
// CSV PARSER (BOM / delimiter aware) — used by levy + survey imports
// ────────────────────────────────────────────────────────────
function commsParseCsv(string $tmpPath): array {
    $rows = [];
    if (($fh = fopen($tmpPath, 'r')) === false) return $rows;

    $header = null;

    $firstLine = fgets($fh);
    rewind($fh);
    $firstLine = ltrim((string)$firstLine, "\xEF\xBB\xBF");
    $tabs   = substr_count($firstLine, "\t");
    $semis  = substr_count($firstLine, ';');
    $commas = substr_count($firstLine, ',');

    if ($tabs >= $semis && $tabs >= $commas)  $delim = "\t";
    elseif ($semis > $commas)                 $delim = ';';
    else                                       $delim = ',';

    while (($row = fgetcsv($fh, 4000, $delim)) !== false) {
        if (!$header) {
            $header = array_map(
                static fn($h) => strtolower(trim(ltrim((string)$h, "\xEF\xBB\xBF"))),
                $row
            );
            continue;
        }
        if (count($row) < 1 || implode('', $row) === '') continue;

        $rows[] = array_combine(
            array_slice($header, 0, count($row)),
            array_slice($row, 0, count($header))
        );
    }
    fclose($fh);
    return $rows;
}


// ────────────────────────────────────────────────────────────
// FILE UPLOAD HANDLING
// ────────────────────────────────────────────────────────────
//
// $rules:
//   field         — $_FILES key (default 'file')
//   extensions    — allowed extensions, e.g. ['pdf'] or ['csv','txt']
//   max_size      — bytes (default 10MB)
//   dest_dir      — absolute path to store in
//   prefix        — filename prefix (default: date('Ymd_His'))
//
// Returns ['ok' => bool, 'stored' => string|null, 'original' => string|null,
//          'tmp_name' => string|null, 'error' => string|null]
// Note: for CSV uploads you typically don't need to move the file —
// just call commsParseCsv() on $_FILES[...]['tmp_name'] directly.
// commsHandleUpload() is mainly for files that need to persist (PDFs, etc.)
// ────────────────────────────────────────────────────────────
function commsHandleUpload(array $rules): array {
    $field = $rules['field'] ?? 'file';
    $exts  = $rules['extensions'] ?? [];
    $max   = $rules['max_size'] ?? (10 * 1024 * 1024);
    $dest  = rtrim($rules['dest_dir'] ?? (UPLOAD_DIR), '/');
    $prefix = $rules['prefix'] ?? date('Ymd_His');

    if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No file uploaded.'];
    }

    $file     = $_FILES[$field];
    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if ($exts && !in_array($ext, $exts, true)) {
        return ['ok' => false, 'error' => 'File type not allowed (' . implode(', ', $exts) . ' only).'];
    }
    if ($file['size'] > $max) {
        return ['ok' => false, 'error' => 'File too large — maximum ' . round($max / 1024 / 1024) . ' MB.'];
    }

    if (!is_dir($dest)) @mkdir($dest, 0755, true);

    $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $stored   = $prefix . '_' . $safeBase . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dest . '/' . $stored)) {
        return ['ok' => false, 'error' => 'File upload failed. Check server permissions.'];
    }

    return [
        'ok'       => true,
        'stored'   => $stored,
        'original' => $origName,
        'error'    => null,
    ];
}


// ────────────────────────────────────────────────────────────
// EMAIL — unified sender + templates
// ────────────────────────────────────────────────────────────
//
// commsSendEmail() sends HTML when $html is provided, else falls back to
// the plain-text sendEmail() from twilio_helper.php.
// ────────────────────────────────────────────────────────────
function commsSendEmail(string $to, string $subject, string $html): bool {
    require_once __DIR__ . '/smtp_mail.php';
    return smtpSend($to, $subject, $html);
}

function commsEmailShell(string $eyebrow, string $bodyHtml): string {
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;margin:0;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;
            box-shadow:0 2px 8px rgba(0,0,0,.1);">
  <div style="background:#003366;padding:24px 28px;">
    <h1 style="color:#fff;margin:0;font-size:1.3rem;">🏡 GEMB Estate</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:.9rem;">{$eyebrow}</p>
  </div>
  <div style="padding:28px;">
    {$bodyHtml}
    <p style="color:#888;font-size:.82rem;margin-top:24px;border-top:1px solid #eee;padding-top:16px;">
      This email was sent by GEMB Estate Management.<br>
      If you have any queries, please contact the estate office.
    </p>
  </div>
</div>
</body></html>
HTML;
}

// Channel: bulk (circular/PDF download link)
function commsBuildCircularEmail(string $name, string $title, string $url, string $notes): string {
    $notesHtml = $notes ? '<p style="color:#555;margin:16px 0;">' . nl2br(htmlspecialchars($notes)) . '</p>' : '';
    $body = '<p style="color:#333;margin:0 0 8px;">Dear ' . htmlspecialchars($name) . ',</p>'
          . '<p style="color:#333;margin:0 0 16px;">Please find the following document available for download:</p>'
          . '<h2 style="color:#003366;margin:0 0 16px;font-size:1.1rem;">📄 ' . htmlspecialchars($title) . '</h2>'
          . $notesHtml
          . '<div style="text-align:center;margin:24px 0;">'
          . '  <a href="' . htmlspecialchars($url) . '" target="_blank"'
          . '     style="display:inline-block;background:#003366;color:#fff;padding:14px 32px;'
          . '            border-radius:6px;text-decoration:none;font-weight:700;font-size:1rem;">'
          . '    📥 Download Document'
          . '  </a>'
          . '</div>';
    return commsEmailShell('Resident Communication', $body);
}

// Channel: levy (personalised statement)
function commsBuildLevyEmail(string $name, string $amount, string $message, string $subject): string {
    $greeting = $name ? "Dear {$name}," : "Dear Resident,";
    $amountHtml = $amount
        ? '<div style="background:#f0f8ff;border:2px solid #003366;border-radius:8px;'
        . '            padding:16px;text-align:center;margin:20px 0;">'
        . '  <div style="font-size:.85rem;color:#666;margin-bottom:4px;">Amount Due</div>'
        . '  <div style="font-size:2rem;font-weight:900;color:#003366;">R ' . htmlspecialchars($amount) . '</div>'
        . '</div>'
        : '';
    $msgHtml = $message ? '<p style="color:#555;margin:16px 0;">' . nl2br(htmlspecialchars($message)) . '</p>' : '';

    $body = '<p style="color:#333;margin:0 0 16px;">' . htmlspecialchars($greeting) . '</p>'
          . $amountHtml
          . $msgHtml;
    return commsEmailShell('Levy Notice', $body);
}

// Channel: surveys / voting — simple notification + link
function commsBuildNotifyEmail(string $name, string $heading, string $message, ?string $url = null, ?string $linkLabel = null): string {
    $greeting = $name ? "Dear {$name}," : "Dear Resident,";
    $linkHtml = $url
        ? '<div style="text-align:center;margin:24px 0;">'
        . '  <a href="' . htmlspecialchars($url) . '" target="_blank"'
        . '     style="display:inline-block;background:#003366;color:#fff;padding:14px 32px;'
        . '            border-radius:6px;text-decoration:none;font-weight:700;font-size:1rem;">'
        . '    ' . htmlspecialchars($linkLabel ?? 'Open') . '</a>'
        . '</div>'
        : '';

    $body = '<p style="color:#333;margin:0 0 16px;">' . htmlspecialchars($greeting) . '</p>'
          . '<h2 style="color:#003366;margin:0 0 16px;font-size:1.1rem;">' . htmlspecialchars($heading) . '</h2>'
          . '<p style="color:#555;margin:16px 0;">' . nl2br(htmlspecialchars($message)) . '</p>'
          . $linkHtml;
    return commsEmailShell('Estate Notice', $body);
}


// ────────────────────────────────────────────────────────────
// BROADCAST / SEND-LOG (unified reporting)
// ────────────────────────────────────────────────────────────
//
// $channel: 'bulk' | 'levy' | 'survey' | 'vote' | 'archive'
//
// comms_broadcasts is a superset of document_broadcasts (+channel column).
// comms_send_log   is a superset of document_send_log   (+channel column).
// See comms_schema.sql for the migration from the old tables.
// ────────────────────────────────────────────────────────────
function commsBroadcastCreate(string $channel, string $type, string $title, ?string $filename, ?string $originalName, ?string $notes): int {
    db()->prepare("
        INSERT INTO comms_broadcasts (channel, type, title, filename, original_name, notes, sent_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([$channel, $type, $title, $filename, $originalName, $notes, commsCurrentUserId()]);

    return (int)db()->lastInsertId();
}

function commsBroadcastUpdateCounts(int $broadcastId, int $sent, int $failed): void {
    db()->prepare("UPDATE comms_broadcasts SET sent_to=?, failed=? WHERE id=?")
        ->execute([$sent, $failed, $broadcastId]);
}

function commsLog(int $broadcastId, string $channel, ?string $email, ?string $name, string $status, ?string $errorMsg = null, ?float $amount = null, ?string $message = null): void {
    db()->prepare("
        INSERT INTO comms_send_log (broadcast_id, channel, recipient_email, recipient_name, amount, message, status, error_msg)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$broadcastId, $channel, $email, $name, $amount, $message, $status, $errorMsg]);
}

// Send to one recipient + log the result in one call. Returns true/false.
function commsSendAndLog(int $broadcastId, string $channel, string $email, string $name, string $subject, string $html, ?float $amount = null, ?string $message = null): bool {
    $ok = commsSendEmail($email, $subject, $html);
    commsLog($broadcastId, $channel, $email, $name, $ok ? 'sent' : 'failed', $ok ? null : 'mail() returned false', $amount, $message);
    return $ok;
}

function commsBroadcastGet(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM comms_broadcasts WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function commsLogList(int $broadcastId): array {
    $stmt = db()->prepare("SELECT * FROM comms_send_log WHERE broadcast_id = ? ORDER BY sent_at ASC");
    $stmt->execute([$broadcastId]);
    return $stmt->fetchAll();
}

// Unified reporting list across all channels, most recent first.
// $channel = null for all channels, or one of bulk|levy|survey|vote|archive
function commsBroadcastList(?string $channel = null, int $limit = 50): array {
    if ($channel) {
        $stmt = db()->prepare("SELECT * FROM comms_broadcasts WHERE channel = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bindValue(1, $channel, PDO::PARAM_STR);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    $stmt = db()->prepare("SELECT * FROM comms_broadcasts ORDER BY created_at DESC LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}


// ────────────────────────────────────────────────────────────
// RECIPIENT COUNT PREVIEWS (for "Send to N residents" UI)
// ────────────────────────────────────────────────────────────
function commsRecipientCount(string $audience, array $opts = []): int {
    switch ($audience) {
        case 'all_residents':
            return (int)db()->query("
                SELECT COUNT(DISTINCT email) FROM residents
                WHERE status='active' AND email IS NOT NULL AND email != '' AND is_primary=1
            ")->fetchColumn();

        case 'comms_contacts':
            $groupTag = $opts['group_tag'] ?? null;
            if ($groupTag !== null && $groupTag !== '') {
                $stmt = db()->prepare("SELECT COUNT(*) FROM comms_contacts WHERE active=1 AND group_tag=?");
                $stmt->execute([$groupTag]);
                return (int)$stmt->fetchColumn();
            }
            return (int)db()->query("SELECT COUNT(*) FROM comms_contacts WHERE active=1")->fetchColumn();

        case 'vote_eligible_owners':
            $meetingId = (int)($opts['meeting_id'] ?? 0);
            if (!$meetingId) return 0;
            $stmt = db()->prepare("SELECT COUNT(*) FROM vote_eligible_owners WHERE meeting_id = ?");
            $stmt->execute([$meetingId]);
            return (int)$stmt->fetchColumn();

        default:
            return 0;
    }
}
