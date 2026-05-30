<?php
require_once __DIR__ . '/db.php';
$res  = requireResident();   // redirects to login.php if not authenticated
$csrf = csrfToken();

// Fetch saved visitors for autocomplete (most recent 50 distinct plates per resident)
$saved = [];
$stmt  = db()->prepare('SELECT visitor_name, plate, idnum FROM invitations WHERE invited_by=? ORDER BY id DESC LIMIT 100');
$stmt->bind_param('i', $res['id']);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($rows as $r) {
    if (!isset($saved[$r['visitor_name']])) {
        $saved[$r['visitor_name']] = ['plate' => $r['plate'], 'idnum' => $r['idnum']];
    }
}
$savedJson = json_encode($saved);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Visitor Access — MBGE</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:#f0f4f8;min-height:100vh}
    header{background:#002855;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:14px 20px;gap:12px}
    header strong{display:block;font-size:15px}
    header span{opacity:.72;font-size:12px}
    .hbtn{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:8px;padding:7px 12px;font-size:12px;cursor:pointer;text-decoration:none}
    .hbtn:hover{background:rgba(255,255,255,.25)}
    .container{max-width:500px;margin:0 auto;padding:18px 16px}
    .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,.08);margin-bottom:14px}
    .card-title{color:#002855;font-size:14px;font-weight:700;margin-bottom:14px;padding-bottom:8px;border-bottom:2px solid #e8edf2}
    label{display:block;font-size:12px;font-weight:700;color:#555;margin-bottom:4px;margin-top:12px;text-transform:uppercase;letter-spacing:.4px}
    label:first-child{margin-top:0}
    input[type=text],textarea{width:100%;padding:11px 12px;font-size:15px;border:1px solid #d0d7e0;border-radius:8px;outline:none}
    input:focus,textarea:focus{border-color:#007bff}
    .date-row{display:flex;gap:10px}
    .date-btn{flex:1;padding:12px;border:2px solid #007bff;border-radius:8px;background:#fff;color:#007bff;font-size:14px;font-weight:700;cursor:pointer}
    .date-btn.sel{background:#007bff;color:#fff}
    .method-row{display:flex;gap:20px;padding:6px 0;align-items:center}
    .method-row label{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600;margin:0;text-transform:none;letter-spacing:0;cursor:pointer}
    .method-row input[type=radio]{width:18px;height:18px;margin:0;accent-color:#007bff}
    textarea{resize:none;font-size:14px}
    .btn-send{width:100%;padding:15px;background:#007bff;color:#fff;font-size:16px;font-weight:700;border:none;border-radius:10px;cursor:pointer}
    .btn-send:hover{background:#0062cc}
    .btn-send:disabled{opacity:.6;cursor:not-allowed}
    #qrSection{display:none;text-align:center;padding:10px 0 4px}
    #qrSection p{font-size:13px;color:#555;margin-bottom:10px}
    .sig-badge{display:inline-block;padding:3px 10px;border-radius:5px;font-size:12px;font-weight:700;margin-bottom:8px}
    .signed{background:#d4edda;color:#155724}
    .unsigned{background:#fff3cd;color:#856404}
    .btn-dl{display:inline-block;margin-top:10px;padding:10px 20px;background:#28a745;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer}
    .btn-dl:hover{background:#218838}
    .msg{font-size:13px;padding:9px 11px;border-radius:6px;margin-bottom:12px}
    .msg.ok{background:#f0fff4;color:#1e7e34;border:1px solid #b7dfc0}
    .msg.err{background:#fff0f0;color:#c0392b;border:1px solid #f5c6c6}
    .msg.warn{background:#fff3cd;color:#856404;border:1px solid #ffc107}
  </style>
</head>
<body>
<header>
  <div>
    <strong><?= e($res['name']) ?></strong>
    <span>Unit <?= e($res['unit']) ?></span>
  </div>
  <a class="hbtn" href="logout.php">Logout</a>
</header>

<div class="container">
  <div id="noSignMsg" class="msg warn" style="display:none;">
    No estate signing key is configured — QR codes will be unsigned. Ask your admin to set up the key.
  </div>

  <div class="card">
    <div class="card-title">Visitor Details</div>
    <label>Visitor's Name</label>
    <input list="savedList" type="text" id="name" placeholder="e.g. John Jones">
    <datalist id="savedList"></datalist>
    <label>Licence Plate</label>
    <input type="text" id="plate" placeholder="e.g. ABC123GP">
    <label>ID Number (13 digits)</label>
    <input type="text" id="idnum" placeholder="e.g. 1234567890123" maxlength="13" inputmode="numeric">
  </div>

  <div class="card">
    <div class="card-title">Visit Date</div>
    <div class="date-row">
      <button class="date-btn" id="todayBtn" type="button">Today</button>
      <button class="date-btn" id="tomorrowBtn" type="button">Tomorrow</button>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Send Invitation</div>
    <label>Send via</label>
    <div class="method-row">
      <label><input type="radio" name="method" value="wa" checked> WhatsApp</label>
      <label><input type="radio" name="method" value="sms"> SMS</label>
    </div>
    <label>Message Preview</label>
    <textarea id="preview" rows="5" readonly></textarea>
    <div id="qrSection">
      <div id="sigBadge" class="sig-badge"></div>
      <p>QR code for guard verification:</p>
      <div id="qrcode"></div>
      <button class="btn-dl" onclick="downloadQR()">Download QR Code</button>
    </div>
  </div>

  <div id="feedback" class="msg" style="display:none;"></div>
  <button class="btn-send" id="sendBtn" onclick="send()">Generate QR &amp; Send</button>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
  const SAVED = <?= $savedJson ?>;
  const CSRF  = <?= json_encode($csrf) ?>;
  let selectedDate = '';

  // Build saved visitor datalist
  const dl = document.getElementById('savedList');
  Object.keys(SAVED).forEach(n => { const o=document.createElement('option'); o.value=n; dl.appendChild(o); });

  // Show warning if no signing key (server tells us via signed flag in response)
  // We'll show it after first generation attempt if unsigned.

  function today(){ return new Date().toISOString().split('T')[0]; }
  function tomorrow(){ const d=new Date(); d.setDate(d.getDate()+1); return d.toISOString().split('T')[0]; }

  function selectDate(choice){
    selectedDate = choice==='today' ? today() : tomorrow();
    document.getElementById('todayBtn').classList.toggle('sel', choice==='today');
    document.getElementById('tomorrowBtn').classList.toggle('sel', choice==='tomorrow');
    updatePreview();
  }

  function getInput(){
    return {
      name:  document.getElementById('name').value.trim(),
      plate: document.getElementById('plate').value.trim().toUpperCase(),
      idnum: document.getElementById('idnum').value.trim(),
      date:  selectedDate
    };
  }

  function buildMsg(i){
    return i.name+' has been granted access on '+i.date+' to the estate with vehicle licence plate '+i.plate+' and ID number '+i.idnum+'.';
  }

  function updatePreview(){
    const i=getInput();
    document.getElementById('preview').value = (i.name&&i.plate&&i.idnum&&i.date) ? buildMsg(i) : '';
  }

  document.getElementById('name').addEventListener('change', function(){
    if(SAVED[this.value]){
      document.getElementById('plate').value = SAVED[this.value].plate;
      document.getElementById('idnum').value = SAVED[this.value].idnum||'';
    }
    updatePreview();
  });
  document.getElementById('name').addEventListener('input', updatePreview);
  document.getElementById('plate').addEventListener('input', updatePreview);
  document.getElementById('idnum').addEventListener('input', function(){ this.value=this.value.replace(/\D/g,''); updatePreview(); });
  document.getElementById('todayBtn').addEventListener('click', () => {
    if(!document.getElementById('name').value.trim()||!document.getElementById('plate').value.trim()){alert('Fill in Visitor Name and Plate first.');return;} selectDate('today');
  });
  document.getElementById('tomorrowBtn').addEventListener('click', () => {
    if(!document.getElementById('name').value.trim()||!document.getElementById('plate').value.trim()){alert('Fill in Visitor Name and Plate first.');return;} selectDate('tomorrow');
  });

  async function send(){
    const inp = getInput();
    if(!inp.name||!inp.plate||!inp.date){ alert('Fill in all fields and select a date.'); return; }
    if(!/^\d{13}$/.test(inp.idnum)){ alert('ID number must be exactly 13 digits.'); return; }

    const btn = document.getElementById('sendBtn');
    btn.disabled=true; btn.textContent='Generating…';

    try {
      const res = await fetch('api.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({action:'generate_qr', csrf:CSRF, ...inp})
      });
      const data = await res.json();
      if(!data.ok){ showFeedback(data.error||'Error. Please try again.','err'); btn.disabled=false; btn.textContent='Generate QR & Send'; return; }

      // Render QR (already HMAC-signed by server)
      const qrCon = document.getElementById('qrcode');
      qrCon.innerHTML='';
      new QRCode(qrCon,{text:data.qr,width:220,height:220});

      const badge = document.getElementById('sigBadge');
      if(data.signed){ badge.textContent='Signed — guard-verifiable'; badge.className='sig-badge signed'; }
      else { badge.textContent='Unsigned — admin has not configured estate key'; badge.className='sig-badge unsigned'; document.getElementById('noSignMsg').style.display='block'; }
      document.getElementById('qrSection').style.display='block';

      const msg = buildMsg(inp);
      const method = document.querySelector('input[name=method]:checked').value;
      showFeedback('Invitation saved. Opening '+(method==='wa'?'WhatsApp':'SMS')+'…','ok');
      setTimeout(()=>{
        window.open(method==='wa'?'https://wa.me/?text='+encodeURIComponent(msg):'sms:?&body='+encodeURIComponent(msg),'_blank');
      },350);
    } catch(e){
      showFeedback('Network error. Please try again.','err');
    }
    btn.disabled=false; btn.textContent='Generate QR & Send';
  }

  function downloadQR(){
    setTimeout(()=>{
      const c=document.querySelector('#qrcode canvas');
      if(!c)return;
      const a=document.createElement('a'); a.href=c.toDataURL('image/png');
      a.download='visitor_qr.png'; a.click();
    },150);
  }

  function showFeedback(msg,type){
    const el=document.getElementById('feedback');
    el.textContent=msg; el.className='msg '+type; el.style.display='block';
  }

  selectDate('today');
</script>
</body>
</html>
