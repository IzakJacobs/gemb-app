<?php
// ============================================================
// GEMB — newsletter_download.php
// Serves newsletter files stored as blobs in the DB
// Requires resident login
// ============================================================
require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireResident();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: resident.php?action=comms'); exit; }

$stmt = db()->prepare("SELECT filename, filetype, filedata FROM newsletters WHERE id=?");
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file || !$file['filedata']) {
    die('File not found.');
}

$mime = $file['filetype'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes($file['filename']) . '"');
header('Content-Length: ' . strlen($file['filedata']));
echo $file['filedata'];
exit;
