<?php
/**
 * generate_visitor_qr.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Generates the QR PNG for a visitor pass and saves it to /temp/<CODE>.png
 * QR payload = https://gemb.co.za/visitor_qr_verify.php?code=3XXXXX
 *
 * Visitor codes: 3 + 5 digits  (e.g. 312847)
 * Called after INSERT into visitors table.
 *
 * Usage: generate_visitor_qr.php?visitor_id=42
 * Returns JSON: { "ok": true, "path": "/temp/312847.png" }
 * ─────────────────────────────────────────────────────────────────────────────
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/phpqrcode/qrlib.php';

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in']) && empty($_SESSION['resident_logged_in'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']); exit;
}

$visitorId = filter_input(INPUT_GET, 'visitor_id', FILTER_VALIDATE_INT);
if (!$visitorId) {
    echo json_encode(['ok' => false, 'error' => 'Missing visitor_id']); exit;
}

$stmt = $conn->prepare("SELECT id, code FROM visitors WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $visitorId);
$stmt->execute();
$visitor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$visitor) {
    echo json_encode(['ok' => false, 'error' => 'Visitor not found']); exit;
}

$code = $visitor['code'];   // must be 3XXXXX

/* validate code format before generating */
if (!preg_match('/^3\d{5}$/', $code)) {
    echo json_encode(['ok' => false, 'error' => "Code '{$code}' is not valid visitor format (3XXXXX)"]); exit;
}

/* QR payload → verify endpoint (guard scans this) */
$verifyUrl = 'https://gemb.co.za/visitor_qr_verify.php?code=' . urlencode($code);

$tempDir = __DIR__ . '/temp';
if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
$filePath = $tempDir . '/' . $code . '.png';
$webPath  = '/temp/' . $code . '.png';

QRcode::png($verifyUrl, $filePath, QR_ECLEVEL_M, 6, 2);

if (!file_exists($filePath)) {
    echo json_encode(['ok' => false, 'error' => 'QR generation failed']); exit;
}

$stmt = $conn->prepare("UPDATE visitors SET qrcode = ? WHERE id = ?");
$stmt->bind_param('si', $webPath, $visitorId);
$stmt->execute();
$stmt->close();

echo json_encode(['ok' => true, 'path' => $webPath, 'url' => $verifyUrl, 'code' => $code]);

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * CODE GENERATION  — add this to add_visitor.php when creating a new visitor
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Visitor code (3 + 5 random digits, unique):
 *
 *   do {
 *       $code = '3' . str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
 *       $chk  = $conn->prepare("SELECT id FROM visitors WHERE code = ? LIMIT 1");
 *       $chk->bind_param('s', $code); $chk->execute();
 *       $exists = $chk->get_result()->num_rows > 0;
 *       $chk->close();
 *   } while ($exists);
 *   // $code is now unique, e.g. "312847"
 *
 * Service provider code (7 + 5 random digits):
 *
 *   do {
 *       $code = '7' . str_pad(random_int(0, 99999), 5, '0', STR_PAD_LEFT);
 *       $chk  = $conn->prepare("SELECT id FROM service_providers WHERE unique_code = ? LIMIT 1");
 *       $chk->bind_param('s', $code); $chk->execute();
 *       $exists = $chk->get_result()->num_rows > 0;
 *       $chk->close();
 *   } while ($exists);
 * ─────────────────────────────────────────────────────────────────────────────
 */
