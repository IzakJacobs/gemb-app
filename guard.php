<?php
require_once __DIR__ . '/db.php';
requireGuard();
$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Security Gate — MBGE</title>
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,sans-serif;background:#f0f4f8;min-height:100vh}
    header{background:#1a5c2a;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:14px 18px}
    header h1{font-size:16px}
    .hbtn{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:7px;padding:7px 12px;font-size:12px;cursor:pointer;text-decoration:none}
    .hbtn:hover{background:rgba(255,255,255,.25)}
    .date-badge{background:rgba(255,255,255,.15);padding:5px 10px;border-radius:6px;font-size:12px}
    .tabs{display:flex;background:#fff;border-bottom:2px solid #e0e8f0}
    .tab-btn{flex:1;padding:13px 8px;border:none;background:transparent;font-size:14px;font-weight:600;color:#777;cursor:pointer;border-bottom:3px solid transparent}
    .tab-btn.active{color:#1a5c2a;border-bottom-color:#1a5c2a}
    .tab-content{display:none;padding:16px;max-width:540px;margin:0 auto}
    .tab-content.active{display:block}
    /* Scanner */
    .scan-wrap{position:relative;background:#111;border-radius:12px;overflow:hidden;aspect-ratio:4/3;max-height:300px;display:flex;align-items:center;justify-content:center}
    #video{width:100%;height:100%;object-fit:cover;display:none}
    .scan-ph{color:#888;text-align:center;padding:20px;font-size:14px}
    .scan-overlay{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:180px;height:180px;border:3px solid rgba(255,255,255,.7);border-radius:12px;display:none}
    .scan-overlay.on{display:block}
    #canvas{display:none}
    .btn-scan{width:100%;padding:13px;margin-top:10px;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;color:#fff;background:#1a5c2a}
    .btn-scan:hover{background:#145221}
    .btn-scan.stop{background:#c0392b}
    .btn-scan.stop:hover{background:#a93226}
    /* Visitor card */
    .vcard{display:none;background:#fff;border-radius:14px;padding:18px;margin-top:14px;box-shadow:0 2px 12px rgba(0,0,0,.1);border-left:5px solid #6c757d}
    .vcard.verified{border-left-color:#28a745}.vcard.unsigned{border-left-color:#ffc107}.vcard.invalid{border-left-color:#dc3545}
    .vbadge,.dbadge{display:inline-block;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:700;margin-bottom:8px}
    .vbadge{margin-right:4px}
    .vb-verified{background:#d4edda;color:#155724}.vb-unsigned{background:#fff3cd;color:#856404}.vb-invalid{background:#f8d7da;color:#721c24}.vb-nosec{background:#cce5ff;color:#004085}
    .db-today{background:#d4edda;color:#155724}.db-future{background:#fff3cd;color:#856404}.db-expired{background:#f8d7da;color:#721c24}.db-none{background:#e2e8f0;color:#444}
    .vrow{display:flex;padding:6px 0;border-bottom:1px solid #f0f0f0;font-size:14px}
    .vrow:last-of-type{border-bottom:none}
    .vlabel{color:#777;width:90px;flex-shrink:0}.vval{color:#111;font-weight:600}
    .act-row{display:flex;gap:10px;margin-top:14px}
    .btn-g,.btn-d{flex:1;padding:14px;border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;color:#fff}
    .btn-g{background:#28a745}.btn-g:hover{background:#218838}
    .btn-d{background:#dc3545}.btn-d:hover{background:#c0392b}
    .act-result{display:none;padding:14px;border-radius:10px;text-align:center;font-size:15px;font-weight:700;margin-top:10px}
    .act-result.granted{background:#d4edda;color:#155724}.act-result.denied{background:#f8d7da;color:#721c24}
    .btn-reset{width:100%;margin-top:8px;padding:10px;background:transparent;border:1px solid #ccc;border-radius:8px;font-size:14px;cursor:pointer;color:#555}
    .btn-reset:hover{background:#f5f5f5}
    .qr-err{display:none;background:#fff3cd;border:1px solid #ffc107;color:#856404;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:10px}
    /* Manual */
    .field{margin-bottom:14px}
    .field label{display:block;font-size:13px;font-weight:600;color:#444;margin-bottom:5px}
    .field input{width:100%;padding:12px;font-size:15px;border:1px solid #d0d7e0;border-radius:8px;outline:none}
    .field input:focus{border-color:#1a5c2a}
    .man-row{display:flex;gap:10px;margin-top:6px}
    #manResult{margin-top:12px;padding:12px;border-radius:8px;font-size:14px;font-weight:600;display:none}
    #manResult.granted{background:#d4edda;color:#155724}#manResult.denied{background:#f8d7da;color:#721c24}
    /* Log */
    .log-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
    .log-hdr h3{color:#002855;font-size:15px}
    .log-filter{padding:7px 10px;border:1px solid #d0d7e0;border-radius:8px;font-size:13px;outline:none}
    .log-table{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.07)}
    .log-table th{background:#002855;color:#fff;padding:10px 8px;text-align:left;font-size:12px}
    .log-table td{padding:9px 8px;border-bottom:1px solid #f0f4f8;vertical-align:top}
    .log-table tr:last-child td{border-bottom:none}
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700}
    .badge.granted{background:#d4edda;color:#155724}.badge.denied{background:#f8d7da;color:#721c24}.badge.manual{background:#cce5ff;color:#004085}
    .empty-log{text-align:center;color:#aaa;padding:24px;font-size:14px}
    .log-count{font-size:12px;color:#777;margin-top:8px}
  </style>
</head>
<body>
<header>
  <h1>MBGE Security Gate</h1>
  <div style="display:flex;gap:8px;align-items:center">
    <span class="date-badge" id="todayDate"></span>
    <a class="hbtn" href="logout.php">Exit</a>
  </div>
</header>

<nav class="tabs">
  <button class="tab-btn active" onclick="sw('scan')">Scan QR</button>
  <button class="tab-btn"        onclick="sw('manual')">Manual</button>
  <button class="tab-btn"        onclick="sw('log')">Access Log</button>
</nav>

<!-- Scan -->
<div id="scanTab" class="tab-content active">
  <div class="scan-wrap">
    <div class="scan-ph" id="scanPH"><div style="font-size:36px;margin-bottom:8px">&#x25A6;</div>Press <strong>Start Scanner</strong></div>
    <video id="video" playsinline autoplay></video>
    <canvas id="canvas"></canvas>
    <div class="scan-overlay" id="scanOverlay"></div>
  </div>
  <button class="btn-scan" id="startBtn" onclick="startScan()">Start Scanner</button>
  <button class="btn-scan stop" id="stopBtn" onclick="stopScan()" style="display:none">Stop Scanner</button>
  <div class="qr-err" id="qrErr"></div>

  <div class="vcard" id="vcard">
    <div><span class="vbadge" id="vbadge"></span><span class="dbadge" id="dbadge"></span></div>
    <div class="vrow"><span class="vlabel">Visitor</span><span class="vval" id="vName"></span></div>
    <div class="vrow"><span class="vlabel">Plate</span>  <span class="vval" id="vPlate"></span></div>
    <div class="vrow"><span class="vlabel">ID No.</span> <span class="vval" id="vId"></span></div>
    <div class="vrow"><span class="vlabel">Date</span>   <span class="vval" id="vDate"></span></div>
    <div class="vrow"><span class="vlabel">Invited by</span><span class="vval" id="vBy"></span></div>
    <div class="act-row" id="actRow">
      <button class="btn-g" onclick="logAccess('granted')">GRANT ACCESS</button>
      <button class="btn-d" onclick="logAccess('denied')">DENY ENTRY</button>
    </div>
    <div class="act-result" id="actResult"></div>
    <button class="btn-reset" id="scanAgain" style="display:none" onclick="resetScan()">Scan Another</button>
  </div>
</div>

<!-- Manual -->
<div id="manualTab" class="tab-content">
  <div class="field"><label>Visitor Full Name</label><input type="text" id="manName" placeholder="e.g. John Jones"></div>
  <div class="field"><label>Licence Plate</label><input type="text" id="manPlate" placeholder="e.g. ABC123GP"></div>
  <div class="field"><label>Invited by — Resident / Unit <small style="font-weight:400;color:#999">(optional)</small></label><input type="text" id="manBy" placeholder="e.g. John Smith / A12"></div>
  <div class="man-row">
    <button class="btn-g" onclick="manAction('granted')">GRANT ACCESS</button>
    <button class="btn-d" onclick="manAction('denied')">DENY ENTRY</button>
  </div>
  <div id="manResult"></div>
</div>

<!-- Log -->
<div id="logTab" class="tab-content">
  <div class="log-hdr">
    <h3>Access Log</h3>
    <select class="log-filter" id="logFilter" onchange="loadLog()">
      <option value="today">Today</option>
      <option value="all">All entries</option>
    </select>
  </div>
  <table class="log-table">
    <thead><tr><th>Time</th><th>Visitor</th><th>Plate</th><th>Status</th></tr></thead>
    <tbody id="logBody"></tbody>
  </table>
  <p class="log-count" id="logCount"></p>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script>
  const CSRF    = <?= json_encode($csrf) ?>;
  const today   = new Date().toISOString().split('T')[0];
  document.getElementById('todayDate').textContent = new Date().toLocaleDateString('en-ZA',{weekday:'short',day:'numeric',month:'short',year:'numeric'});

  let stream=null, scanning=false, currentVisitor=null;
  const video=document.getElementById('video'), canvas=document.getElementById('canvas'), ctx=canvas.getContext('2d');

  function sw(tab){
    ['scan','manual','log'].forEach((t,i)=>{
      document.querySelectorAll('.tab-btn')[i].classList.toggle('active',t===tab);
      document.getElementById(t+'Tab').classList.toggle('active',t===tab);
    });
    if(tab==='log') loadLog();
    if(tab!=='scan') stopScan();
  }

  async function startScan(){
    try{
      stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment',width:{ideal:1280},height:{ideal:720}}});
      video.srcObject=stream; await video.play();
      scanning=true; video.style.display='block';
      document.getElementById('scanPH').style.display='none';
      document.getElementById('scanOverlay').classList.add('on');
      document.getElementById('startBtn').style.display='none';
      document.getElementById('stopBtn').style.display='block';
      document.getElementById('qrErr').style.display='none';
      requestAnimationFrame(scanFrame);
    }catch(e){
      document.getElementById('qrErr').textContent='Camera access denied. Use Manual tab.';
      document.getElementById('qrErr').style.display='block';
    }
  }

  function stopScan(){
    scanning=false;
    if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}
    video.srcObject=null; video.style.display='none';
    document.getElementById('scanPH').style.display='block';
    document.getElementById('scanOverlay').classList.remove('on');
    document.getElementById('startBtn').style.display='block';
    document.getElementById('stopBtn').style.display='none';
  }

  function scanFrame(){
    if(!scanning)return;
    if(video.readyState<video.HAVE_ENOUGH_DATA){requestAnimationFrame(scanFrame);return;}
    canvas.width=video.videoWidth; canvas.height=video.videoHeight;
    ctx.drawImage(video,0,0);
    const img=ctx.getImageData(0,0,canvas.width,canvas.height);
    const code=jsQR(img.data,img.width,img.height,{inversionAttempts:'dontInvert'});
    if(code){stopScan(); verifyQR(code.data);}
    else requestAnimationFrame(scanFrame);
  }

  async function verifyQR(raw){
    try{
      const res=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'verify_qr',csrf:CSRF,qr:raw})});
      const data=await res.json();
      if(!data.ok){
        document.getElementById('qrErr').textContent=data.error||'Unrecognised QR code.';
        document.getElementById('qrErr').style.display='block'; return;
      }
      currentVisitor={...data.visitor, verifyState:data.verifyState, dateState:data.dateState};
      showCard(data.visitor, data.verifyState, data.dateState);
    }catch(e){
      document.getElementById('qrErr').textContent='Server error. Try again.';
      document.getElementById('qrErr').style.display='block';
    }
  }

  function maskId(id){if(!id||id.length<4)return id||'N/A'; return '*'.repeat(id.length-4)+id.slice(-4);}
  function fmtDate(d){if(!d)return 'Not specified'; return new Date(d+'T00:00:00').toLocaleDateString('en-ZA',{weekday:'long',day:'numeric',month:'long',year:'numeric'});}

  function showCard(v, vs, ds){
    const vbMap={verified:['vb-verified','SIGNATURE VERIFIED'],invalid:['vb-invalid','INVALID SIGNATURE'],unsigned:['vb-unsigned','UNSIGNED — VERIFY MANUALLY'],'no-secret':['vb-nosec','UNVERIFIED']};
    const [vc,vt]=vbMap[vs]||['vb-nosec','UNVERIFIED'];
    document.getElementById('vbadge').className='vbadge '+vc;
    document.getElementById('vbadge').textContent=vt;

    const dbMap={today:['db-today','Valid today'],future:['db-future','Future date — not valid today'],expired:['db-expired','EXPIRED'],none:['db-none','Date not specified']};
    const [dc,dt]=dbMap[ds]||['db-none',''];
    document.getElementById('dbadge').className='dbadge '+dc;
    document.getElementById('dbadge').textContent=dt;

    document.getElementById('vcard').className='vcard '+(vs==='verified'?'verified':vs==='invalid'?'invalid':'unsigned');
    document.getElementById('vName').textContent =v.name||'N/A';
    document.getElementById('vPlate').textContent=v.plate||'N/A';
    document.getElementById('vId').textContent   =maskId(v.id);
    document.getElementById('vDate').textContent =fmtDate(v.date);
    document.getElementById('vBy').textContent   =[v.byN,v.unit].filter(Boolean).join(' — Unit ')||'Unknown';

    document.getElementById('vcard').style.display='block';
    document.getElementById('actResult').style.display='none';
    document.getElementById('scanAgain').style.display='none';
    document.getElementById('actRow').style.display='flex';
  }

  async function logAccess(action){
    if(!currentVisitor)return;
    const body={action:'log_access',csrf:CSRF,name:currentVisitor.name,plate:currentVisitor.plate,
      idnum:currentVisitor.id,date:currentVisitor.date||today,byN:currentVisitor.byN||'',
      unit:currentVisitor.unit||'',action:action,source:'qr',verifyState:currentVisitor.verifyState,invId:currentVisitor.invId||null};
    await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});

    const r=document.getElementById('actResult');
    r.textContent=action==='granted'?'ACCESS GRANTED — Entry recorded':'ENTRY DENIED — Record logged';
    r.className='act-result '+action; r.style.display='block';
    document.getElementById('actRow').style.display='none';
    document.getElementById('scanAgain').style.display='block';
  }

  function resetScan(){
    currentVisitor=null;
    document.getElementById('vcard').style.display='none';
    document.getElementById('actResult').style.display='none';
    document.getElementById('scanAgain').style.display='none';
    document.getElementById('actRow').style.display='flex';
    document.getElementById('qrErr').style.display='none';
  }

  async function manAction(action){
    const name =document.getElementById('manName').value.trim();
    const plate=document.getElementById('manPlate').value.trim().toUpperCase();
    const by   =document.getElementById('manBy').value.trim();
    if(!name||!plate){alert('Enter Visitor Name and Plate.');return;}

    await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'log_access',csrf:CSRF,name,plate,idnum:'',date:today,byN:by||'Manual entry',unit:'',action,source:'manual',verifyState:'manual',invId:null})});

    const r=document.getElementById('manResult');
    r.textContent=(action==='granted'?'ACCESS GRANTED':'ENTRY DENIED')+' — '+name+' ('+plate+')';
    r.className=action; r.style.display='block';
    document.getElementById('manName').value=document.getElementById('manPlate').value=document.getElementById('manBy').value='';
    setTimeout(()=>r.style.display='none',4000);
  }

  async function loadLog(){
    const filter=document.getElementById('logFilter').value;
    const date  =filter==='today'?today:'';
    const url   ='api.php?action_get=log'+(date?'&date='+date:'');
    // Use POST for consistency
    const res=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'log_access_get',csrf:CSRF,date,filter})});
    // Note: we use a direct DB query on this page for simplicity (guard session verified by PHP)
    // Reload log via inline approach below
    loadLogDirect(date);
  }

  async function loadLogDirect(date){
    // Fetch entries via dedicated action
    const res=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'get_log_guard',csrf:CSRF,date})});
    // Fallback: handle via separate endpoint
    fetchLog(date);
  }

  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
</script>
<?php
// Inline log fetch (guard page — server-side render avoids extra round-trip)
// Accessed via AJAX from guard.php JS
?>
<script>
  async function fetchLog(date){
    try{
      const params=new URLSearchParams({action:'get_log',date:date||''});
      const res=await fetch('log_data.php?'+params);
      const data=await res.json();
      renderLog(data.entries||[]);
    }catch(e){ document.getElementById('logBody').innerHTML='<tr><td colspan="4" class="empty-log">Error loading log.</td></tr>'; }
  }

  function renderLog(entries){
    const tbody=document.getElementById('logBody');
    tbody.innerHTML='';
    if(!entries.length){ tbody.innerHTML='<tr><td colspan="4" class="empty-log">No entries found</td></tr>'; document.getElementById('logCount').textContent=''; return; }
    entries.forEach(e=>{
      const t=new Date(e.logged_at).toLocaleTimeString('en-ZA',{hour:'2-digit',minute:'2-digit'});
      const src=e.source==='manual'?' <span class="badge manual">manual</span>':'';
      const tr=document.createElement('tr');
      tr.innerHTML='<td>'+t+'</td><td>'+esc(e.visitor_name)+(e.invited_by_name&&e.invited_by_name!=='Manual entry'?'<br><small style="color:#999">'+esc(e.invited_by_name)+'</small>':'')+'</td><td>'+esc(e.plate)+'</td><td><span class="badge '+e.action+'">'+(e.action==='granted'?'GRANTED':'DENIED')+'</span>'+src+'</td>';
      tbody.appendChild(tr);
    });
    document.getElementById('logCount').textContent=entries.length+' entr'+(entries.length===1?'y':'ies');
  }

  // Initial log load
  fetchLog(today);
</script>
</body>
</html>
