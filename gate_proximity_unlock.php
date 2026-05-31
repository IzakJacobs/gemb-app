<?php
session_start();
if (!isset($_SESSION["user"])) {
    header("Location: visitor_login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "visitor_system");
if ($conn->connect_error) {
    if (isset($_POST["action"])) {
        header("Content-Type: application/json");
        echo json_encode(["success" => false, "error" => "Database connection failed"]);
        exit();
    }
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("CREATE TABLE IF NOT EXISTS proximity_unlock_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(255) NOT NULL,
    gate_id INT NOT NULL,
    gate_name VARCHAR(100) NOT NULL,
    resident_lat DECIMAL(10,8) NOT NULL,
    resident_lng DECIMAL(11,8) NOT NULL,
    distance_meters DECIMAL(8,2) NOT NULL,
    unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// ── Gate definitions ─────────────────────────────────────────────────────────
// Replace lat/lng values with actual GPS coordinates of each gate before go-live
$entrances = [
    [
        "code"  => "SS",
        "label" => "SS Entrance",
        "gates" => [
            ["id" => 1, "name" => "SS Gate 1", "lat" => -25.800000, "lng" => 28.200000],
            ["id" => 2, "name" => "SS Gate 2", "lat" => -25.800150, "lng" => 28.200200],
            ["id" => 3, "name" => "SS Gate 3", "lat" => -25.800300, "lng" => 28.200400],
        ],
    ],
    [
        "code"  => "CS",
        "label" => "CS Entrance",
        "gates" => [
            ["id" => 4, "name" => "CS Gate 1", "lat" => -25.802000, "lng" => 28.202000],
            ["id" => 5, "name" => "CS Gate 2", "lat" => -25.802200, "lng" => 28.202300],
        ],
    ],
];

// Flat lookup map: gate_id → gate
$gateMap = [];
foreach ($entrances as $ent) {
    foreach ($ent["gates"] as $g) {
        $gateMap[$g["id"]] = $g;
    }
}

define("PROXIMITY_LIMIT_M", 100);

function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $R    = 6371000;
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlam = deg2rad($lng2 - $lng1);
    $a    = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
    return 2 * $R * asin(sqrt($a));
}

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    header("Content-Type: application/json");
    $action = $_POST["action"];

    if ($action === "check_proximity") {
        $gateId = filter_input(INPUT_POST, "gate_id", FILTER_VALIDATE_INT);
        $lat    = filter_input(INPUT_POST, "lat",     FILTER_VALIDATE_FLOAT);
        $lng    = filter_input(INPUT_POST, "lng",     FILTER_VALIDATE_FLOAT);

        if (!$gateId || $lat === false || $lng === false || $lat === null || $lng === null) {
            echo json_encode(["success" => false, "error" => "Invalid parameters"]);
            exit();
        }
        if (!isset($gateMap[$gateId])) {
            echo json_encode(["success" => false, "error" => "Gate not found"]);
            exit();
        }

        $gate = $gateMap[$gateId];
        $dist = haversineDistance($lat, $lng, $gate["lat"], $gate["lng"]);

        echo json_encode([
            "success"  => true,
            "gate"     => $gate["name"],
            "distance" => round($dist, 1),
            "inRange"  => $dist <= PROXIMITY_LIMIT_M,
        ]);
        exit();
    }

    if ($action === "unlock_gate") {
        $gateId = filter_input(INPUT_POST, "gate_id", FILTER_VALIDATE_INT);
        $lat    = filter_input(INPUT_POST, "lat",     FILTER_VALIDATE_FLOAT);
        $lng    = filter_input(INPUT_POST, "lng",     FILTER_VALIDATE_FLOAT);

        if (!$gateId || $lat === false || $lng === false || $lat === null || $lng === null) {
            echo json_encode(["success" => false, "error" => "Invalid parameters"]);
            exit();
        }
        if (!isset($gateMap[$gateId])) {
            echo json_encode(["success" => false, "error" => "Gate not found"]);
            exit();
        }

        $gate = $gateMap[$gateId];
        $dist = haversineDistance($lat, $lng, $gate["lat"], $gate["lng"]);

        if ($dist > PROXIMITY_LIMIT_M) {
            echo json_encode([
                "success"  => false,
                "tooFar"   => true,
                "distance" => round($dist, 1),
                "error"    => "Too Far to Open Gate — you are " . round($dist) . " m away.",
            ]);
            exit();
        }

        $email    = $_SESSION["user"];
        $gateName = $gate["name"];
        $distVal  = round($dist, 2);

        $stmt = $conn->prepare(
            "INSERT INTO proximity_unlock_log
             (user_email, gate_id, gate_name, resident_lat, resident_lng, distance_meters)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sisddd", $email, $gateId, $gateName, $lat, $lng, $distVal);
        $stmt->execute();
        $stmt->close();

        // ── Hardware trigger ─────────────────────────────────────────────────
        // TODO: send HTTP/MQTT command to ESP32 controller here, e.g.:
        //   $ch = curl_init("http://esp32-controller.local/open?gate=" . $gateId);
        //   curl_exec($ch); curl_close($ch);
        // ────────────────────────────────────────────────────────────────────

        echo json_encode([
            "success"  => true,
            "gate"     => $gate["name"],
            "distance" => round($dist, 1),
        ]);
        exit();
    }

    echo json_encode(["success" => false, "error" => "Unknown action"]);
    exit();
}

// Recent unlock log for this user
$recentLogs = [];
$logStmt = $conn->prepare(
    "SELECT gate_name, distance_meters, unlocked_at
     FROM proximity_unlock_log
     WHERE user_email = ?
     ORDER BY unlocked_at DESC
     LIMIT 5"
);
$logStmt->bind_param("s", $_SESSION["user"]);
$logStmt->execute();
$res = $logStmt->get_result();
while ($row = $res->fetch_assoc()) { $recentLogs[] = $row; }
$logStmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Open Gate - MBGE</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Arial, sans-serif;
      background: #f0f4f8;
      padding: 16px 16px 40px;
    }
    .container { max-width: 460px; margin: auto; }

    /* ── Header ── */
    .header { text-align: center; padding: 10px 0 20px; }
    .header img  { width: 120px; margin-bottom: 10px; }
    .header h2   { font-size: 20px; color: #222; margin-bottom: 4px; }
    .header .sub { font-size: 13px; color: #888; }

    /* ── Dev note ── */
    .dev-note {
      background: #e8f0fe;
      border-left: 3px solid #4285f4;
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 12px;
      color: #1a3a6b;
      margin-bottom: 18px;
    }

    /* ── Entrance sections ── */
    .entrance-section { margin-bottom: 18px; }
    .entrance-label {
      font-size: 13px;
      font-weight: bold;
      color: #555;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      margin-bottom: 8px;
      padding-left: 2px;
    }
    .gate-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
    }
    .gate-btn {
      background: white;
      border: 2px solid #d0dbe8;
      border-radius: 12px;
      padding: 18px 8px;
      text-align: center;
      cursor: pointer;
      transition: border-color 0.15s, background 0.15s, transform 0.1s;
      user-select: none;
    }
    .gate-btn:hover   { border-color: #007bff; background: #f0f6ff; }
    .gate-btn:active  { transform: scale(0.96); }
    .gate-btn.active  { border-color: #007bff; background: #e6f0ff; }
    .gate-btn .g-icon { font-size: 28px; margin-bottom: 6px; }
    .gate-btn .g-name { font-size: 13px; font-weight: bold; color: #333; }

    /* ── Result panel ── */
    #resultPanel {
      margin-top: 4px;
      margin-bottom: 18px;
      display: none;
    }
    .result-card {
      background: white;
      border-radius: 14px;
      padding: 24px 20px;
      box-shadow: 0 3px 12px rgba(0,0,0,0.09);
      text-align: center;
    }
    .result-gate-name {
      font-size: 17px;
      font-weight: bold;
      color: #222;
      margin-bottom: 4px;
    }
    .result-dist {
      font-size: 13px;
      color: #777;
      margin-bottom: 20px;
    }

    /* Push button */
    .push-btn {
      width: 100%;
      padding: 22px 16px;
      font-size: 19px;
      font-weight: bold;
      background: linear-gradient(160deg, #28a745 0%, #1a7a32 100%);
      color: white;
      border: none;
      border-radius: 14px;
      cursor: pointer;
      box-shadow: 0 4px 18px rgba(40, 167, 69, 0.35);
      transition: transform 0.1s, box-shadow 0.1s;
      letter-spacing: 0.3px;
    }
    .push-btn:active {
      transform: scale(0.97);
      box-shadow: 0 2px 8px rgba(40, 167, 69, 0.25);
    }
    .push-btn:disabled {
      background: #aaa;
      box-shadow: none;
      cursor: not-allowed;
    }
    .push-btn-success {
      background: linear-gradient(160deg, #155724 0%, #0d3d18 100%) !important;
    }

    /* Too far */
    .too-far-box {
      background: #fff8e1;
      border: 2px solid #ffc107;
      border-radius: 14px;
      padding: 22px 16px;
    }
    .too-far-box .tf-icon { font-size: 42px; margin-bottom: 10px; }
    .too-far-box h3 { font-size: 19px; color: #856404; margin-bottom: 6px; }
    .too-far-box p  { font-size: 14px; color: #7d5a00; }

    /* Checking spinner */
    .checking-box {
      padding: 20px;
      color: #888;
      font-size: 15px;
    }

    /* ── Log section ── */
    .log-card {
      background: white;
      border-radius: 12px;
      padding: 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
      margin-bottom: 18px;
    }
    .log-card h3 { font-size: 14px; color: #555; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { padding: 7px 9px; border: 1px solid #e8e8e8; text-align: left; }
    th { background: #f7f7f7; color: #666; font-weight: 600; }
    .no-log { color: #aaa; font-size: 13px; font-style: italic; }

    .nav-link {
      display: block;
      text-align: center;
      color: #007bff;
      font-size: 15px;
      text-decoration: none;
    }
    .nav-link:hover { text-decoration: underline; }

    /* ── Toast ── */
    #toast {
      position: fixed;
      bottom: 28px;
      left: 50%;
      transform: translateX(-50%) translateY(100px);
      padding: 13px 22px;
      border-radius: 10px;
      font-size: 14px;
      max-width: 88%;
      text-align: center;
      color: white;
      background: #333;
      opacity: 0;
      transition: transform 0.28s, opacity 0.28s;
      z-index: 999;
      pointer-events: none;
    }
    #toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
    #toast.t-ok  { background: #28a745; }
    #toast.t-err { background: #dc3545; }
  </style>
</head>
<body>
<div class="container">

  <div class="header">
    <img src="logo.png" alt="MBGE Logo">
    <h2>Open Gate</h2>
    <p class="sub"><?php echo htmlspecialchars($_SESSION["user"]); ?></p>
  </div>

  <div class="dev-note">
    <strong>Dev mode:</strong> Gate coordinates are placeholders.
    Update the <code>$entrances</code> array with real GPS coordinates before go-live.
  </div>

  <!-- Gate selector -->
  <?php foreach ($entrances as $ent): ?>
  <div class="entrance-section">
    <div class="entrance-label"><?php echo htmlspecialchars($ent["label"]); ?></div>
    <div class="gate-grid">
      <?php foreach ($ent["gates"] as $gate): ?>
        <div class="gate-btn"
             id="gbtn-<?php echo $gate["id"]; ?>"
             onclick="selectGate(<?php echo $gate["id"]; ?>, '<?php echo htmlspecialchars($gate["name"], ENT_QUOTES); ?>')">
          <div class="g-icon">🚧</div>
          <div class="g-name"><?php echo htmlspecialchars($gate["name"]); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Result panel (populated by JS) -->
  <div id="resultPanel">
    <div class="result-card" id="resultCard"></div>
  </div>

  <!-- Recent unlocks -->
  <div class="log-card">
    <h3>Recent Unlocks</h3>
    <?php if (count($recentLogs) === 0): ?>
      <p class="no-log">No unlocks recorded yet.</p>
    <?php else: ?>
      <table>
        <tr><th>Gate</th><th>Distance</th><th>Time</th></tr>
        <?php foreach ($recentLogs as $log): ?>
          <tr>
            <td><?php echo htmlspecialchars($log["gate_name"]); ?></td>
            <td><?php echo htmlspecialchars($log["distance_meters"]); ?> m</td>
            <td><?php echo htmlspecialchars($log["unlocked_at"]); ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <a class="nav-link" href="visitor.html">← Back to Visitor Portal</a>
</div>

<div id="toast"></div>

<script>
  var activeGateId  = null;
  var activeLat     = null;
  var activeLng     = null;
  var LIMIT_M       = <?php echo PROXIMITY_LIMIT_M; ?>;

  // ── Step 1: resident taps a gate ──────────────────────────────────────────
  function selectGate(gateId, gateName) {
    // Highlight selected button
    document.querySelectorAll(".gate-btn").forEach(function(b) {
      b.classList.remove("active");
    });
    document.getElementById("gbtn-" + gateId).classList.add("active");

    activeGateId = gateId;
    showChecking(gateName);

    if (!navigator.geolocation) {
      showTooFar(gateName, null, "Geolocation not supported by this browser.");
      return;
    }

    navigator.geolocation.getCurrentPosition(
      function(pos) {
        activeLat = pos.coords.latitude;
        activeLng = pos.coords.longitude;
        checkProximity(gateId, gateName, activeLat, activeLng);
      },
      function(err) {
        var msg = "Could not get your location.";
        if (err.code === err.PERMISSION_DENIED) msg = "Location permission denied. Please allow access and try again.";
        showTooFar(gateName, null, msg);
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  }

  // ── Step 2: check proximity via server ────────────────────────────────────
  function checkProximity(gateId, gateName, lat, lng) {
    post({ action: "check_proximity", gate_id: gateId, lat: lat, lng: lng })
      .then(function(data) {
        if (!data.success) { showTooFar(gateName, null, data.error); return; }
        if (data.inRange) {
          showPushButton(data.gate, data.distance);
        } else {
          showTooFar(data.gate, data.distance, null);
        }
      })
      .catch(function() { showTooFar(gateName, null, "Network error. Please try again."); });
  }

  // ── Step 3: resident pushes the button ────────────────────────────────────
  function triggerUnlock() {
    if (!activeGateId || activeLat === null || activeLng === null) return;

    var btn = document.getElementById("pushBtn");
    btn.disabled = true;
    btn.textContent = "Opening…";

    post({ action: "unlock_gate", gate_id: activeGateId, lat: activeLat, lng: activeLng })
      .then(function(data) {
        if (data.success) {
          btn.textContent = "Gate Opening ✓";
          btn.classList.add("push-btn-success");
          toast("Gate is opening. Please proceed.", "ok");
          // Reset after 6 s so the button is usable again
          setTimeout(function() {
            btn.textContent = "Push Button to Open Gate";
            btn.disabled = false;
            btn.classList.remove("push-btn-success");
          }, 6000);
        } else if (data.tooFar) {
          showTooFar(data.gate || "Gate", data.distance, null);
        } else {
          btn.textContent = "Push Button to Open Gate";
          btn.disabled = false;
          toast(data.error, "err");
        }
      })
      .catch(function() {
        btn.textContent = "Push Button to Open Gate";
        btn.disabled = false;
        toast("Network error. Please try again.", "err");
      });
  }

  // ── UI helpers ────────────────────────────────────────────────────────────
  function showChecking(gateName) {
    var panel = document.getElementById("resultPanel");
    var card  = document.getElementById("resultCard");
    card.innerHTML =
      '<div class="checking-box">⏳ Getting your location for <strong>' + esc(gateName) + '</strong>…</div>';
    panel.style.display = "block";
  }

  function showPushButton(gateName, dist) {
    var panel = document.getElementById("resultPanel");
    var card  = document.getElementById("resultCard");
    card.innerHTML =
      '<div class="result-gate-name">' + esc(gateName) + '</div>' +
      '<div class="result-dist">You are ' + dist + ' m away (within ' + LIMIT_M + ' m)</div>' +
      '<button class="push-btn" id="pushBtn" onclick="triggerUnlock()">' +
        'Push Button to Open Gate' +
      '</button>';
    panel.style.display = "block";
  }

  function showTooFar(gateName, dist, customMsg) {
    var panel = document.getElementById("resultPanel");
    var card  = document.getElementById("resultCard");
    var distLine = dist !== null
      ? 'You are <strong>' + dist + ' m</strong> away. Move within ' + LIMIT_M + ' m of the gate.'
      : esc(customMsg || "Unable to verify your distance to this gate.");
    card.innerHTML =
      '<div class="too-far-box">' +
        '<div class="tf-icon">⚠️</div>' +
        '<h3>Too Far to Open Gate</h3>' +
        '<p>' + distLine + '</p>' +
      '</div>';
    panel.style.display = "block";
  }

  function post(params) {
    var body = Object.keys(params)
      .map(function(k) { return encodeURIComponent(k) + "=" + encodeURIComponent(params[k]); })
      .join("&");
    return fetch("gate_proximity_unlock.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body
    }).then(function(r) { return r.json(); });
  }

  function toast(msg, type) {
    var t = document.getElementById("toast");
    t.textContent = msg;
    t.className = "show t-" + type;
    clearTimeout(t._t);
    t._t = setTimeout(function() { t.className = ""; }, 4000);
  }

  function esc(s) {
    return String(s)
      .replace(/&/g,"&amp;").replace(/</g,"&lt;")
      .replace(/>/g,"&gt;").replace(/"/g,"&quot;");
  }
</script>
</body>
</html>
