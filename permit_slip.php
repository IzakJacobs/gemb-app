<?php
/**
 * permit_slip.php — MBGE Paper Slip Permit (A5)
 * For: contractor_worker (and any slip-type SP)
 * Shows: personal details + QR + must present with ID at gate
 */
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (empty($_SESSION['security_id']) && empty($_SESSION['admin_id'])) {
    header('Location: security.php?action=login'); exit;
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Missing ID');

$sp = db()->prepare("
    SELECT sp.*,
           (SELECT service_name FROM service_providers
            WHERE id = sp.lead_id) AS lead_name,
           (SELECT company_name FROM service_providers
            WHERE id = sp.lead_id) AS lead_company
    FROM service_providers sp
    WHERE sp.id = ? LIMIT 1
");
$sp->execute([$id]);
$sp = $sp->fetch();
if (!$sp) die('Record not found');

// QR code
$qrLib = __DIR__ . '/phpqrcode/qrlib.php';
if (file_exists($qrLib)) {
    require_once $qrLib;
    $tempDir   = __DIR__ . '/temp';
    if (!is_dir($tempDir)) @mkdir($tempDir, 0755, true);
    $qrFile    = $tempDir . '/' . $sp['unique_code'] . '.png';
    $verifyUrl = SITE_URL . '/service_qr_verify.php?code='
               . urlencode($sp['unique_code']);
    if (!file_exists($qrFile)) {
        QRcode::png($verifyUrl, $qrFile, QR_ECLEVEL_M, 8, 2);
    }
    $qrBase64 = file_exists($qrFile)
        ? base64_encode(file_get_contents($qrFile)) : '';
} else {
    $qrBase64 = '';
}

$validFrom = date('d M Y', strtotime($sp['start_date']));
$validTo   = date('d M Y', strtotime($sp['end_date']));
$printDate = date('d M Y H:i');

$catLabels = [
    'domestic'          => 'Domestic Worker',
    'delivery'          => 'Delivery',
    'resident_worker'   => 'Resident Worker',
    'contractor_lead'   => 'Contractor Lead',
    'contractor_worker' => 'Contractor Worker',
];
$catLabel = $catLabels[$sp['category'] ?? 'contractor_worker'] ?? 'Service Provider';

$qrTag = $qrBase64
    ? '<img src="data:image/png;base64,' . $qrBase64
      . '" style="width:45mm;height:45mm;">'
    : '<img src="https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl='
      . urlencode(SITE_URL . '/service_qr_verify.php?code=' . $sp['unique_code'])
      . '" style="width:45mm;height:45mm;">';

$html = '<!DOCTYPE html><html><head><meta charset="utf-8">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family: Arial, sans-serif; font-size: 10pt;
         background:#fff; padding:12mm; }

  .slip {
    width: 186mm;
    border: 0.5mm solid #1a3c5e;
    border-radius: 3mm;
    overflow: hidden;
  }

  .slip-header {
    background: #1a3c5e;
    color: #fff;
    padding: 3mm 5mm;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .slip-header h1 { font-size: 11pt; letter-spacing: 0.3pt; }
  .slip-header .type {
    font-size: 8pt;
    background: #c8a84b;
    color: #000;
    padding: 1mm 2.5mm;
    border-radius: 2mm;
    font-weight: bold;
    white-space: nowrap;
    margin-left: 4mm;
  }

  .slip-body {
    display: flex;
    gap: 4mm;
    padding: 4mm 5mm;
  }

  .slip-qr {
    flex-shrink: 0;
    text-align: center;
    width: 48mm;
  }
  .slip-qr img { width: 44mm; height: 44mm; }
  .slip-qr .code {
    font-size: 7pt;
    font-weight: bold;
    font-family: monospace;
    color: #1a3c5e;
    letter-spacing: 2pt;
    margin-top: 2mm;
  }

  .slip-details { flex: 1; min-width: 0; }
  .slip-details table { width: 100%; border-collapse: collapse; }
  .slip-details td {
    padding: 1.2mm 2mm;
    font-size: 8.5pt;
    vertical-align: top;
    border-bottom: 0.2mm solid #eee;
    word-break: break-word;
  }
  .slip-details td:first-child {
    color: #666;
    width: 30mm;
    font-size: 7.5pt;
    white-space: nowrap;
  }
  .slip-details td:last-child {
    font-weight: 600;
    color: #1a1a1a;
  }
  .name-row td {
    font-size: 11pt;
    font-weight: bold;
    color: #1a3c5e;
    border-bottom: 0.4mm solid #1a3c5e;
    padding-bottom: 2mm;
  }

  .slip-warning {
    background: #fff3cd;
    border-top: 0.4mm solid #ffc107;
    padding: 2.5mm 5mm;
    font-size: 7.5pt;
    color: #856404;
    font-weight: bold;
    text-align: center;
  }

  .slip-footer {
    background: #f5f5f5;
    border-top: 0.3mm solid #ddd;
    padding: 1.5mm 5mm;
    font-size: 6.5pt;
    color: #888;
    display: flex;
    justify-content: space-between;
  }
</style>
</head><body>

<div class="slip">
  <div class="slip-header">
    <h1>MBGE TEMPORARY ACCESS PERMIT</h1>
    <span class="type">' . $catLabel . '</span>
  </div>

  <div class="slip-body">
    <div class="slip-qr">
      ' . $qrTag . '
      <div class="code">' . htmlspecialchars($sp['unique_code']) . '</div>
    </div>

    <div class="slip-details">
      <table>
        <tr class="name-row">
          <td colspan="2">' . htmlspecialchars($sp['service_name']) . '</td>
        </tr>
        ' . ($sp['id_number'] ? '
        <tr>
          <td>ID Number</td>
          <td>' . htmlspecialchars($sp['id_number']) . '</td>
        </tr>' : '') . '
        ' . ($sp['lead_name'] ? '
        <tr>
          <td>Under Lead</td>
          <td>' . htmlspecialchars($sp['lead_name'])
              . ($sp['lead_company'] ? ' (' . htmlspecialchars($sp['lead_company']) . ')' : '')
              . '</td>
        </tr>' : '') . '
        <tr>
          <td>Resident</td>
          <td>' . htmlspecialchars($sp['resident_name']) . '</td>
        </tr>
        <tr>
          <td>Erf / Address</td>
          <td>' . htmlspecialchars($sp['resident_erfno']) . '</td>
        </tr>
        ' . ($sp['notes'] ? '
        <tr>
          <td>Work</td>
          <td>' . htmlspecialchars($sp['notes']) . '</td>
        </tr>' : '') . '
        <tr>
          <td>Valid Period</td>
          <td>' . $validFrom . ' – ' . $validTo . '</td>
        </tr>
        <tr>
          <td>Access Hours</td>
          <td>'
          . htmlspecialchars($sp['access_days'] ?? 'Mon–Fri')
          . '&nbsp;&nbsp;'
          . substr($sp['access_start'] ?? '07:00:00', 0, 5)
          . ' – '
          . substr($sp['access_end']   ?? '17:00:00', 0, 5)
          . '</td>
        </tr>
        <tr>
          <td>Issued</td>
          <td>' . $printDate . ' — ' . htmlspecialchars($_SESSION['security_name'] ?? 'Security') . '</td>
        </tr>
      </table>
    </div>
  </div>

  <div class="slip-warning">
    THIS PERMIT MUST BE PRESENTED TOGETHER WITH A PERSONAL IDENTITY DOCUMENT AT THE GATE
  </div>

  <div class="slip-footer">
    <span>MBGE HOA Reg. 1999/001249/08</span>
    <span>Scan QR code at gate for entry</span>
    <span>POPIA Act 4 of 2013</span>
  </div>
</div>

</body></html>';

$opt = new Options();
$opt->set('isRemoteEnabled', true);
$pdf = new Dompdf($opt);
$pdf->loadHtml($html);
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$pdf->stream('permit_slip_' . $sp['unique_code'] . '.pdf',
             ['Attachment' => false]);
exit;
