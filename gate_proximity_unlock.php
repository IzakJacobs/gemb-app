<?php
// ============================================================
// MBGE Access Control — gate_proximity_unlock.php
// Resident proximity gate unlock (Schoeman St & Church St)
// Requires resident session via layout.php / requireResident()
// ============================================================
require_once __DIR__ . '/layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireResident();

$rid   = $_SESSION['resident_id']   ?? 0;
$rname = $_SESSION['resident_name'] ?? '';

// ── Ensure log table exists ───────────────────────────────
db()->exec("CREATE TABLE IF NOT EXISTS proximity_unlock_log (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    resident_id     INT          NOT NULL,
    resident_name   VARCHAR(255) NOT NULL,
    gate_id         INT          NOT NULL,
    gate_name       VARCHAR(100) NOT NULL,
    resident_lat    DECIMAL(10,8) NOT NULL,
    resident_lng    DECIMAL(11,8) NOT NULL,
    distance_meters DECIMAL(8,2) NOT NULL,
    unlocked_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Gate definitions ──────────────────────────────────────
// Entrance coordinates confirmed: Mossel Bay Golf Estate, WC
// Individual gate lat/lng = entrance centroid until each gate
// post is surveyed and its own coordinate is recorded.
$entrances = [
    [
        'code'  => 'SS',
        'label' => 'Schoeman Street Entrance',
        'gates' => [
            ['id' => 1, 'name' => 'SS Gate 1', 'lat' => -34.189300827611795, 'lng' => 22.12257774021871],
            ['id' => 2, 'name' => 'SS Gate 2', 'lat' => -34.189300827611795, 'lng' => 22.12257774021871],
            ['id' => 3, 'name' => 'SS Gate 3', 'lat' => -34.189300827611795, 'lng' => 22.12257774021871],
        ],
    ],
    [
        'code'  => 'CS',
        'label' => 'Church Street Entrance',
        'gates' => [
            ['id' => 4, 'name' => 'CS Gate 1', 'lat' => -34.19164638860875, 'lng' => 22.137356846132185],
            ['id' => 5, 'name' => 'CS Gate 2', 'lat' => -34.19164638860875, 'lng' => 22.137356846132185],
        ],
    ],
];

// Flat gate lookup
$gateMap = [];
foreach ($entrances as $ent) {
    foreach ($ent['gates'] as $g) {
        $gateMap[$g['id']] = $g;
    }
}

define('GATE_LIMIT_M', 100);

function haversineDistance($lat1, $lng1, $lat2, $lng2): float {
    $R    = 6371000;
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlam = deg2rad($lng2 - $lng1);
    $a    = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
    return 2 * $R * asin(sqrt($a));
}

// ── AJAX handlers (must run before any HTML output) ───────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'check_proximity') {
        $gateId = filter_input(INPUT_POST, 'gate_id', FILTER_VALIDATE_INT);
        $lat    = filter_input(INPUT_POST, 'lat',     FILTER_VALIDATE_FLOAT);
        $lng    = filter_input(INPUT_POST, 'lng',     FILTER_VALIDATE_FLOAT);

        if (!$gateId || $lat === false || $lng === false || $lat === null || $lng === null) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit();
        }
        if (!isset($gateMap[$gateId])) {
            echo json_encode(['success' => false, 'error' => 'Gate not found']);
            exit();
        }

        $gate = $gateMap[$gateId];
        $dist = haversineDistance($lat, $lng, $gate['lat'], $gate['lng']);

        echo json_encode([
            'success'  => true,
            'gate'     => $gate['name'],
            'distance' => round($dist, 1),
            'inRange'  => $dist <= GATE_LIMIT_M,
        ]);
        exit();
    }

    if ($action === 'unlock_gate') {
        $gateId = filter_input(INPUT_POST, 'gate_id', FILTER_VALIDATE_INT);
        $lat    = filter_input(INPUT_POST, 'lat',     FILTER_VALIDATE_FLOAT);
        $lng    = filter_input(INPUT_POST, 'lng',     FILTER_VALIDATE_FLOAT);

        if (!$gateId || $lat === false || $lng === false || $lat === null || $lng === null) {
            echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
            exit();
        }
        if (!isset($gateMap[$gateId])) {
            echo json_encode(['success' => false, 'error' => 'Gate not found']);
            exit();
        }

        $gate = $gateMap[$gateId];
        $dist = haversineDistance($lat, $lng, $gate['lat'], $gate['lng']);

        if ($dist > GATE_LIMIT_M) {
            echo json_encode([
                'success'  => false,
                'tooFar'   => true,
                'distance' => round($dist, 1),
                'error'    => 'Too Far to Open Gate — you are ' . round($dist) . ' m away.',
            ]);
            exit();
        }

        $gateName = $gate['name'];
        $distVal  = round($dist, 2);

        db()->prepare(
            "INSERT INTO proximity_unlock_log
             (resident_id, resident_name, gate_id, gate_name,
              resident_lat, resident_lng, distance_meters)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$rid, $rname, $gateId, $gateName, $lat, $lng, $distVal]);

        // ── Hardware trigger ─────────────────────────────────
        // TODO: send HTTP/MQTT command to ESP32 controller, e.g.:
        //   file_get_contents('http://esp32.local/open?gate=' . $gateId);
        // ────────────────────────────────────────────────────

        echo json_encode([
            'success'  => true,
            'gate'     => $gate['name'],
            'distance' => round($dist, 1),
        ]);
        exit();
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit();
}

// ── Recent unlocks for this resident ─────────────────────
$recentLogs = db()->prepare(
    "SELECT gate_name, distance_meters, unlocked_at
     FROM proximity_unlock_log
     WHERE resident_id = ?
     ORDER BY unlocked_at DESC
     LIMIT 5"
);
$recentLogs->execute([$rid]);
$recentLogs = $recentLogs->fetchAll();

// ── Page output ───────────────────────────────────────────
pageHeader('Open Gate', 'resident');
renderHeader('🔓 Open Gate', 'visitor.php?action=select');
?>
<div class="container" style="max-width:480px;">

  <?= getFlash() ?>

  <!-- Entrance / gate selector -->
  <?php foreach ($entrances as $ent): ?>
  <div style="margin-bottom:20px;">
    <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;
                letter-spacing:.8px;color:#888;margin-bottom:8px;">
      <?= htmlspecialchars($ent['label']) ?>
    </div>
    <div style="display:grid;grid-template-columns:repeat(<?= count($ent['gates']) ?>,1fr);gap:10px;">
      <?php foreach ($ent['gates'] as $gate): ?>
      <div class="card"
           id="gbtn-<?= $gate['id'] ?>"
           onclick="selectGate(<?= $gate['id'] ?>, '<?= htmlspecialchars($gate['name'], ENT_QUOTES) ?>')"
           style="text-align:center;padding:18px 8px;cursor:pointer;
                  border:2px solid #dee2e6;border-radius:12px;
                  transition:border-color .15s,background .15s;
                  user-select:none;margin-bottom:0;">
        <div style="font-size:1.8rem;margin-bottom:6px;">🚧</div>
        <div style="font-size:.85rem;font-weight:700;color:#333;">
          <?= htmlspecialchars($gate['name']) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Result panel -->
  <div id="resultPanel" style="display:none;margin-bottom:20px;">
    <div class="card" id="resultCard" style="text-align:center;padding:24px 20px;">
    </div>
  </div>

  <!-- Info note -->
  <div style="background:#fff8e1;border-left:3px solid #ffc107;
              border-radius:8px;padding:12px 16px;
              font-size:.84rem;color:#666;margin-bottom:20px;line-height:1.5;">
    You must be <strong>within 100 metres</strong> of the gate.
    Every unlock is logged with your name and timestamp.
  </div>

  <!-- Recent unlocks -->
  <div class="card">
    <div style="font-weight:700;font-size:.9rem;color:var(--accent);margin-bottom:10px;">
      Recent Unlocks
    </div>
    <?php if (empty($recentLogs)): ?>
      <p style="color:#aaa;font-size:.85rem;font-style:italic;">No unlocks recorded yet.</p>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
        <tr style="background:#f7f7f7;">
          <th style="padding:7px 9px;border:1px solid #eee;text-align:left;">Gate</th>
          <th style="padding:7px 9px;border:1px solid #eee;text-align:left;">Distance</th>
          <th style="padding:7px 9px;border:1px solid #eee;text-align:left;">Time</th>
        </tr>
        <?php foreach ($recentLogs as $log): ?>
        <tr>
          <td style="padding:7px 9px;border:1px solid #eee;">
            <?= htmlspecialchars($log['gate_name']) ?>
          </td>
          <td style="padding:7px 9px;border:1px solid #eee;">
            <?= htmlspecialchars($log['distance_meters']) ?> m
          </td>
          <td style="padding:7px 9px;border:1px solid #eee;">
            <?= htmlspecialchars($log['unlocked_at']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

</div>

<script>
var userLat  = null;
var userLng  = null;
var LIMIT_M  = <?= GATE_LIMIT_M ?>;

function selectGate(gateId, gateName) {
  document.querySelectorAll('[id^="gbtn-"]').forEach(function(b) {
    b.style.borderColor = '#dee2e6';
    b.style.background  = '';
  });
  var btn = document.getElementById('gbtn-' + gateId);
  if (btn) { btn.style.borderColor = '#28a745'; btn.style.background = '#f0fff4'; }

  showResult('<p style="color:#888;padding:10px 0;">⏳ Getting your location for <strong>' + esc(gateName) + '</strong>…</p>');

  if (!navigator.geolocation) {
    showResult(tooFarHtml(gateName, null, 'Geolocation is not supported by this browser.'));
    return;
  }

  navigator.geolocation.getCurrentPosition(
    function(pos) {
      userLat = pos.coords.latitude;
      userLng = pos.coords.longitude;
      checkProximity(gateId, gateName, userLat, userLng);
    },
    function(err) {
      var msg = 'Could not get your location.';
      if (err.code === err.PERMISSION_DENIED)
        msg = 'Location permission denied. Please allow access in your browser settings.';
      showResult(tooFarHtml(gateName, null, msg));
    },
    { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
  );
}

function checkProximity(gateId, gateName, lat, lng) {
  post({ action: 'check_proximity', gate_id: gateId, lat: lat, lng: lng })
    .then(function(data) {
      if (!data.success) { showResult(tooFarHtml(gateName, null, data.error)); return; }
      if (data.inRange) {
        showResult(pushButtonHtml(data.gate, data.distance, gateId));
      } else {
        showResult(tooFarHtml(data.gate, data.distance, null));
      }
    })
    .catch(function() {
      showResult(tooFarHtml(gateName, null, 'Network error. Please try again.'));
    });
}

function triggerUnlock(gateId) {
  if (userLat === null || userLng === null) return;
  var btn = document.getElementById('pushBtn');
  btn.disabled = true;
  btn.textContent = 'Opening…';

  post({ action: 'unlock_gate', gate_id: gateId, lat: userLat, lng: userLng })
    .then(function(data) {
      if (data.success) {
        btn.textContent = 'Gate Opening ✓';
        btn.style.background = '#155724';
        setTimeout(function() {
          btn.textContent = 'Push Button to Open Gate';
          btn.disabled = false;
          btn.style.background = '';
        }, 6000);
      } else if (data.tooFar) {
        showResult(tooFarHtml('Gate', data.distance, null));
      } else {
        btn.textContent = 'Push Button to Open Gate';
        btn.disabled = false;
        alert(data.error);
      }
    })
    .catch(function() {
      btn.textContent = 'Push Button to Open Gate';
      btn.disabled = false;
      alert('Network error. Please try again.');
    });
}

function pushButtonHtml(gateName, dist, gateId) {
  return '<div style="font-size:1rem;font-weight:700;color:#222;margin-bottom:4px;">'
       + esc(gateName) + '</div>'
       + '<div style="font-size:.82rem;color:#777;margin-bottom:20px;">'
       + 'You are ' + dist + ' m away (within ' + LIMIT_M + ' m)</div>'
       + '<button id="pushBtn" onclick="triggerUnlock(' + gateId + ')"'
       + ' style="width:100%;padding:22px 16px;font-size:1.1rem;font-weight:800;'
       + 'background:linear-gradient(160deg,#28a745 0%,#1a7a32 100%);color:white;'
       + 'border:none;border-radius:14px;cursor:pointer;'
       + 'box-shadow:0 4px 18px rgba(40,167,69,.35);letter-spacing:.3px;">'
       + 'Push Button to Open Gate</button>';
}

function tooFarHtml(gateName, dist, customMsg) {
  var line = dist !== null
    ? 'You are <strong>' + dist + ' m</strong> away. Move within ' + LIMIT_M + ' m of the gate.'
    : esc(customMsg || 'Unable to verify your distance.');
  return '<div style="background:#fff8e1;border:2px solid #ffc107;border-radius:12px;padding:22px 16px;">'
       + '<div style="font-size:2.2rem;margin-bottom:8px;">⚠️</div>'
       + '<div style="font-size:1.1rem;font-weight:700;color:#856404;margin-bottom:6px;">Too Far to Open Gate</div>'
       + '<div style="font-size:.88rem;color:#7d5a00;">' + line + '</div>'
       + '</div>';
}

function showResult(html) {
  var panel = document.getElementById('resultPanel');
  document.getElementById('resultCard').innerHTML = html;
  panel.style.display = 'block';
}

function post(params) {
  var body = Object.keys(params)
    .map(function(k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
    .join('&');
  return fetch('gate_proximity_unlock.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body
  }).then(function(r) { return r.json(); });
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                  .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php pageFooter(); ?>
