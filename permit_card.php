<?php
/**
 * permit_card.php — MBGE Credit Card Permit (85×54mm)
 * For: domestic, resident_worker, contractor_lead
 * Called by: security.php?action=print_permit&id=XX
 *
 * Requires: Dompdf (already installed for export files)
 * Usage: permit_card.php?id=42
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Auth — security or admin only
if (empty($_SESSION['security_id']) && empty($_SESSION['admin_id'])) {
    header('Location: security.php?action=login'); exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Missing ID');

$sp = db()->prepare("SELECT * FROM service_providers WHERE id=? LIMIT 1");
$sp->execute([$id]);
$sp = $sp->fetch();
if (!$sp) die('Record not found');

// Generate QR PNG if not exists
$qrLib = __DIR__ . '/phpqrcode/qrlib.php';
if (file_exists($qrLib)) {
    require_once $qrLib;
    $tempDir  = __DIR__ . '/temp';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
    $qrFile   = $tempDir . '/' . $sp['unique_code'] . '.png';
    $verifyUrl = SITE_URL . '/service_qr_verify.php?code='
               . urlencode($sp['unique_code']);
    if (!file_exists($qrFile)) {
        QRcode::png($verifyUrl, $qrFile, QR_ECLEVEL_M, 8, 2);
    }
    $qrBase64 = file_exists($qrFile)
        ? base64_encode(file_get_contents($qrFile))
        : '';
} else {
    // Fallback Google Charts QR
    $qrBase64 = '';
}

$catLabels = [
    'domestic'        => 'Domestic Worker',
    'resident_worker' => 'Resident Worker',
    'contractor_lead' => 'Contractor Lead',
    'contractor_worker' => 'Contractor Worker',
    'delivery'        => 'Delivery',
];

$catLabel  = $catLabels[$sp['category'] ?? 'domestic'] ?? 'Service Provider';
$validFrom = date('d M Y', strtotime($sp['start_date']));
$validTo   = date('d M Y', strtotime($sp['end_date']));

// QR image tag
if ($qrBase64) {
    $qrTag = '<img src="data:image/png;base64,' . $qrBase64
           . '" style="width:28mm;height:28mm;">';
} else {
    $qrUrl = 'https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl='
           . urlencode(SITE_URL . '/service_qr_verify.php?code='
           . $sp['unique_code']);
    $qrTag = '<img src="' . $qrUrl . '" style="width:28mm;height:28mm;">';
}

// ── HTML for credit card (85×54mm) ──────────────────────
// Print 2 cards per page (front + back concept on A4)
$html = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; background:#fff; }

  .page {
    width: 210mm;
    padding: 10mm;
    display: flex;
    flex-wrap: wrap;
    gap: 8mm;
  }

  /* One card = 85×54mm */
  .card {
    width: 85mm;
    height: 54mm;
    border: 0.5mm solid #1a3c5e;
    border-radius: 3mm;
    overflow: hidden;
    position: relative;
    background: #fff;
    page-break-inside: avoid;
  }

  /* Colour band at top */
  .card-header {
    background: #1a3c5e;
    color: #fff;
    padding: 1.5mm 2mm 1mm;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .card-header .estate {
    font-size: 5pt;
    font-weight: bold;
    letter-spacing: 0.3pt;
    text-transform: uppercase;
  }
  .card-header .cat {
    font-size: 5pt;
    background: #c8a84b;
    color: #000;
    padding: 0.5mm 1.5mm;
    border-radius: 1mm;
    font-weight: bold;
  }

  /* Body */
  .card-body {
    display: flex;
    padding: 2mm;
    gap: 2mm;
    height: calc(54mm - 10mm);
  }

  /* QR section */
  .card-qr {
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }
  .card-qr img {
    width: 26mm;
    height: 26mm;
  }
  .card-qr .code {
    font-size: 5.5pt;
    font-weight: bold;
    font-family: monospace;
    color: #1a3c5e;
    letter-spacing: 1pt;
    margin-top: 0.5mm;
  }

  /* Details section */
  .card-details {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }
  .card-details .name {
    font-size: 8pt;
    font-weight: bold;
    color: #1a3c5e;
    line-height: 1.2;
    margin-bottom: 1.5mm;
  }
  .card-details .row {
    font-size: 5.5pt;
    color: #333;
    line-height: 1.5;
  }
  .card-details .row span {
    color: #666;
  }

  /* Footer bar */
  .card-footer {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: #f5f5f5;
    border-top: 0.3mm solid #ddd;
    padding: 0.8mm 2mm;
    font-size: 4.5pt;
    color: #888;
    display: flex;
    justify-content: space-between;
  }
</style>
</head><body><div class="page">';

// Generate card (repeat twice for front/back or two copies)
for ($copy = 1; $copy <= 2; $copy++):
$html .= '
<div class="card">
  <div class="card-header">
    <span class="estate">MOSSEL BAY GOLF ESTATE</span>
    <span class="cat">' . htmlspecialchars($catLabel) . '</span>
  </div>
  <div class="card-body">
    <div class="card-qr">
      ' . $qrTag . '
      <div class="code">' . htmlspecialchars($sp['unique_code']) . '</div>
    </div>
    <div class="card-details">
      <div class="name">' . htmlspecialchars($sp['service_name']) . '</div>
      ' . ($sp['company_name'] ? '
      <div class="row">
        <span>Company: </span>' . htmlspecialchars($sp['company_name']) . '
      </div>' : '') . '
      ' . ($sp['id_number'] ? '
      <div class="row">
        <span>ID: </span>' . htmlspecialchars($sp['id_number']) . '
      </div>' : '') . '
      <div class="row">
        <span>Resident: </span>' . htmlspecialchars($sp['resident_name']) . '
      </div>
      <div class="row">
        <span>Erf: </span>' . htmlspecialchars($sp['resident_erfno']) . '
      </div>
      <div class="row">
        <span>Valid: </span>' . $validFrom . ' – ' . $validTo . '
      </div>
      <div class="row">
        <span>Hours: </span>'
        . htmlspecialchars($sp['access_days'] ?? 'Mon–Sat')
        . ' '
        . substr($sp['access_start'] ?? '07:00:00', 0, 5)
        . '–'
        . substr($sp['access_end']   ?? '17:00:00', 0, 5) . '
      </div>
    </div>
  </div>
  <div class="card-footer">
    <span>MBGE HOA Reg. 1999/001249/08</span>
    <span>POPIA Act 4 of 2013</span>
  </div>
</div>';
endfor;

$html .= '</div></body></html>';

// Output PDF
$opt = new Options();
$opt->set('isRemoteEnabled', true);
$pdf = new Dompdf($opt);
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$pdf->stream('permit_card_' . $sp['unique_code'] . '.pdf',
             ['Attachment' => false]);
exit;
