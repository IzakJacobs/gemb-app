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

// ── HTML for credit card (85×54mm) — table layout for Dompdf ──
$hours = htmlspecialchars($sp['access_days'] ?? 'Mon–Sat')
       . ' '
       . substr($sp['access_start'] ?? '07:00:00', 0, 5)
       . '–'
       . substr($sp['access_end']   ?? '17:00:00', 0, 5);

$html = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  * { margin:0; padding:0; }
  body { font-family: Arial, sans-serif; font-size:5.5pt; background:#fff; }
  table { border-collapse: collapse; }
  .outer { width:85mm; }
  /* Header */
  .hdr { background:#1a3c5e; color:#fff; padding:1.2mm 2mm; }
  .hdr .estate { font-size:5pt; font-weight:bold; letter-spacing:0.3pt; text-transform:uppercase; }
  .hdr .cat { font-size:5pt; background:#c8a84b; color:#000;
              padding:0.3mm 1.5mm; font-weight:bold; }
  /* Body cells */
  .qr-cell { width:33mm; padding:1.5mm 1mm 1mm 1.5mm; vertical-align:middle; text-align:center; }
  .qr-cell img { width:30mm; height:30mm; display:block; margin:0 auto; }
  .qr-code { font-size:5pt; font-weight:bold; font-family:monospace;
             color:#1a3c5e; letter-spacing:2pt; margin-top:0.8mm; }
  .info-cell { padding:1.5mm 2mm 1mm 0; vertical-align:top; }
  .name { font-size:8pt; font-weight:bold; color:#1a3c5e; margin-bottom:1.2mm; }
  .lbl { color:#888; }
  .row { font-size:5.5pt; color:#222; line-height:1.6; }
  /* Footer */
  .ftr { background:#f0f0f0; border-top:0.2mm solid #ccc;
         padding:0.5mm 2mm; font-size:4pt; color:#999; }
  .ftr-inner { width:100%; }
  .ftr-inner td { font-size:4pt; color:#999; }
</style>
</head><body>

<table class="outer">
  <tr>
    <td colspan="2" class="hdr">
      <table width="100%"><tr>
        <td class="estate">MOSSEL BAY GOLF ESTATE</td>
        <td align="right"><span class="cat">' . htmlspecialchars($catLabel) . '</span></td>
      </tr></table>
    </td>
  </tr>
  <tr>
    <td class="qr-cell">
      ' . $qrTag . '
      <div class="qr-code">' . htmlspecialchars($sp['unique_code']) . '</div>
    </td>
    <td class="info-cell">
      <div class="name">' . htmlspecialchars($sp['service_name']) . '</div>
      ' . ($sp['company_name'] ? '<div class="row"><span class="lbl">Company: </span>' . htmlspecialchars($sp['company_name']) . '</div>' : '') . '
      ' . ($sp['id_number']    ? '<div class="row"><span class="lbl">ID: </span>'      . htmlspecialchars($sp['id_number'])    . '</div>' : '') . '
      <div class="row"><span class="lbl">Resident: </span>' . htmlspecialchars($sp['resident_name'])  . '</div>
      <div class="row"><span class="lbl">Erf: </span>'      . htmlspecialchars($sp['resident_erfno']) . '</div>
      <div class="row"><span class="lbl">Valid: </span>'    . $validFrom . ' – ' . $validTo           . '</div>
      <div class="row"><span class="lbl">Hours: </span>'    . $hours                                   . '</div>
    </td>
  </tr>
  <tr>
    <td colspan="2" class="ftr">
      <table class="ftr-inner"><tr>
        <td>MBGE HOA Reg. 1999/001249/08</td>
        <td align="right">POPIA Act 4 of 2013</td>
      </tr></table>
    </td>
  </tr>
</table>

';

$html .= '</body></html>';


// Output PDF
$opt = new Options();
$opt->set('isRemoteEnabled', true);
$pdf = new Dompdf($opt);
$pdf->loadHtml($html);
$pdf->setPaper([0, 0, 241.0, 153.1]); // 85×54mm credit card
$pdf->render();
$pdf->stream('permit_card_' . $sp['unique_code'] . '.pdf',
             ['Attachment' => false]);
exit;
