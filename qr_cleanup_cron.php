<?php
// ============================================================
// GEMB Access Control — qr_cleanup_cron.php
// Daily cron job: purge expired QR image files from temp/
//
// Schedule in cPanel cron (daily at 02:00 SAST):
//   0 0 * * * /usr/bin/php /home/gembbfev/public_html/qr_cleanup_cron.php
//
// What it deletes:
//   temp/3XXXXX.png — visitor QR:   deleted if visit_date_to < today
//   temp/7XXXXX.png — SP QR:        deleted if end_date < today OR expired=1
//
// What it keeps:
//   Any file whose code is still active in the DB
//   Any file that does not match a known code pattern (leave alone)
//
// Logs to: logs/qr_cleanup.log (created if missing)
// ============================================================

// Run from CLI or web (web restricted to localhost)
if (PHP_SAPI !== 'cli') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403); exit('Forbidden');
    }
}

require_once __DIR__ . '/config.php';

$tempDir = __DIR__ . '/temp/';
$logDir  = __DIR__ . '/logs/';
$logFile = $logDir . 'qr_cleanup.log';

// Ensure log directory exists
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}

$today   = date('Y-m-d');
$deleted = 0;
$kept    = 0;
$errors  = 0;
$lines   = [];

$lines[] = "[$today " . date('H:i:s') . "] QR cleanup started";

// ── Fetch all codes still valid today ────────────────────
try {
    $validVisitor = db()->prepare(
        "SELECT code FROM visitors
         WHERE (visit_date_to >= ? OR visit_date >= ?)
           AND expired = 0 AND status = 'active'"
    );
    $validVisitor->execute([$today, $today]);
    $validVisitorCodes = array_column($validVisitor->fetchAll(), 'code');

    $validSP = db()->prepare(
        "SELECT unique_code FROM service_providers
         WHERE end_date >= ?
           AND expired = 0
           AND (approved = 'true' OR approved = 1)"
    );
    $validSP->execute([$today]);
    $validSpCodes = array_column($validSP->fetchAll(), 'unique_code');

    $validCodes = array_merge($validVisitorCodes, $validSpCodes);
    $validSet   = array_flip($validCodes); // O(1) lookup

} catch (Exception $e) {
    $lines[] = "  ERROR fetching valid codes: " . $e->getMessage();
    file_put_contents($logFile, implode("\n", $lines) . "\n", FILE_APPEND);
    exit(1);
}

$lines[] = "  Valid codes in DB: " . count($validCodes)
         . " (" . count($validVisitorCodes) . " visitor, "
         . count($validSpCodes) . " SP)";

// ── Scan temp/ directory ─────────────────────────────────
if (!is_dir($tempDir)) {
    $lines[] = "  temp/ directory not found — nothing to do";
    file_put_contents($logFile, implode("\n", $lines) . "\n", FILE_APPEND);
    exit(0);
}

$files = glob($tempDir . '*.png') ?: [];
$lines[] = "  Files in temp/: " . count($files);

foreach ($files as $filepath) {
    $code = basename($filepath, '.png');

    // Only process 6-digit codes (3XXXXX or 7XXXXX)
    if (!preg_match('/^[37]\d{5}$/', $code)) {
        $kept++;
        continue;
    }

    if (isset($validSet[$code])) {
        // Code still active — keep the file
        $kept++;
    } else {
        // Code expired or not in DB — delete
        if (@unlink($filepath)) {
            $deleted++;
            $lines[] = "  DELETED: {$code}.png";
        } else {
            $errors++;
            $lines[] = "  ERROR deleting: {$code}.png";
        }
    }
}

$lines[] = "  Result: deleted={$deleted}, kept={$kept}, errors={$errors}";

// ── DB maintenance tasks (merged from cron_cleanup.php) ──────
// Uses current schema column names (end_date / visit_date_to)
// not the old 'valid_until' column from the pre-migration schema.

// 1. Mark overdue visitor passes as expired
try {
    $stmt = db()->prepare(
        "UPDATE visitors SET status='expired', expired=1
         WHERE status='active'
           AND COALESCE(visit_date_to, visit_date) < CURDATE()"
    );
    $stmt->execute();
    $lines[] = "  Visitors marked expired: " . $stmt->rowCount();
} catch (Exception $e) {
    $lines[] = "  ERROR marking visitors expired: " . $e->getMessage();
}

// 2. Mark overdue service providers as expired
try {
    $stmt = db()->prepare(
        "UPDATE service_providers SET expired=1
         WHERE expired=0 AND end_date < CURDATE()"
    );
    $stmt->execute();
    $lines[] = "  Service providers marked expired: " . $stmt->rowCount();
} catch (Exception $e) {
    $lines[] = "  ERROR marking SPs expired: " . $e->getMessage();
}

// 3. POPIA retention — delete access_log older than 90 days
try {
    $stmt = db()->prepare(
        "DELETE FROM access_log
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
    );
    $stmt->execute();
    $lines[] = "  Access log rows purged (>90 days): " . $stmt->rowCount();
} catch (Exception $e) {
    $lines[] = "  ERROR purging access_log: " . $e->getMessage();
}

// 4. Delete old visitor records (POPIA — 90 days after visit)
try {
    $stmt = db()->prepare(
        "DELETE FROM visitors
         WHERE COALESCE(visit_date_to, visit_date)
               < DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
    );
    $stmt->execute();
    $lines[] = "  Old visitor records deleted: " . $stmt->rowCount();
} catch (Exception $e) {
    $lines[] = "  ERROR deleting old visitors: " . $e->getMessage();
}

// 5. Delete old service provider records (90 days after end_date)
try {
    $stmt = db()->prepare(
        "DELETE FROM service_providers
         WHERE end_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)"
    );
    $stmt->execute();
    $lines[] = "  Old SP records deleted: " . $stmt->rowCount();
} catch (Exception $e) {
    $lines[] = "  ERROR deleting old SPs: " . $e->getMessage();
}

// 6. Clear stale active_sessions (safety net — older than 24h)
try {
    $stmt = db()->query(
        "DELETE FROM active_sessions
         WHERE entered_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $lines[] = "  Stale active sessions cleared: " . $stmt->rowCount();
} catch (Exception $e) {
    // active_sessions table may not exist yet — safe to skip
    $lines[] = "  active_sessions: " . $e->getMessage();
}

// 7. Delete old notifications (60 days)
try {
    $stmt = db()->query(
        "DELETE FROM notifications
         WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)"
    );
    $lines[] = "  Old notifications deleted: " . $stmt->rowCount();
} catch (Exception $e) {
    $lines[] = "  notifications: " . $e->getMessage();
}

// 8. Purge brute force login_attempts older than 24h (housekeeping)
try {
    $stmt = db()->query(
        "DELETE FROM login_attempts
         WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $lines[] = "  Old login attempts purged: " . $stmt->rowCount();
} catch (Exception $e) {
    $lines[] = "  login_attempts: " . $e->getMessage();
}

// 9. Purge permit print log and associated PDF files
//    Retention: SP end_date + 30 days (set as purge_after at insert time)
try {
    // Fetch expired rows first so we can delete the PDF files from disk
    $expired = db()->query(
        "SELECT id, pdf_path FROM permit_print_log WHERE purge_after < CURDATE()"
    )->fetchAll();

    $filesPurged = 0;
    $filesNotFound = 0;
    foreach ($expired as $row) {
        $absPath = __DIR__ . $row['pdf_path'];
        if (file_exists($absPath)) {
            @unlink($absPath);
            $filesPurged++;
        } else {
            $filesNotFound++;
        }
    }

    // Delete expired DB rows
    $stmt = db()->query(
        "DELETE FROM permit_print_log WHERE purge_after < CURDATE()"
    );
    $lines[] = "  permit_print_log rows deleted: " . $stmt->rowCount()
             . " (PDFs deleted: {$filesPurged}, already gone: {$filesNotFound})";

    // Also clean up any empty monthly subdirectories left behind
    $permitsDir = __DIR__ . '/uploads/permits';
    if (is_dir($permitsDir)) {
        foreach (glob($permitsDir . '/*', GLOB_ONLYDIR) as $monthDir) {
            $remaining = glob($monthDir . '/*.pdf');
            if (empty($remaining)) {
                @rmdir($monthDir);
            }
        }
    }
} catch (Exception $e) {
    $lines[] = "  permit_print_log: " . $e->getMessage();
}

$lines[] = "[$today " . date('H:i:s') . "] QR cleanup complete";
$lines[] = str_repeat('-', 60);

file_put_contents($logFile, implode("\n", $lines) . "\n", FILE_APPEND);

echo implode("\n", $lines) . "\n";
exit(0);
