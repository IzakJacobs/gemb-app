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

// Gate definitions — update lat/lng to match actual gate positions before going live
$gates = [
    ["id" => 1, "name" => "Main Gate",        "lat" => -25.800000, "lng" => 28.200000],
    ["id" => 2, "name" => "North Gate",       "lat" => -25.799000, "lng" => 28.200500],
    ["id" => 3, "name" => "South Gate",       "lat" => -25.801500, "lng" => 28.199800],
    ["id" => 4, "name" => "Pedestrian Gate",  "lat" => -25.800300, "lng" => 28.200800],
];

function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371000;
    $phi1 = deg2rad($lat1); $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlam = deg2rad($lng2 - $lng1);
    $a = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlam / 2) ** 2;
    return 2 * $R * asin(sqrt($a));
}

// AJAX handlers
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    header("Content-Type: application/json");
    $action    = $_POST["action"];
    $userEmail = $_SESSION["user"];

    if ($action === "check_proximity") {
        $lat = filter_input(INPUT_POST, "lat", FILTER_VALIDATE_FLOAT);
        $lng = filter_input(INPUT_POST, "lng", FILTER_VALIDATE_FLOAT);
        if ($lat === false || $lng === false || $lat === null || $lng === null) {
            echo json_encode(["success" => false, "error" => "Invalid coordinates"]);
            exit();
        }
        $result = [];
        foreach ($gates as $gate) {
            $dist = haversineDistance($lat, $lng, $gate["lat"], $gate["lng"]);
            $result[] = [
                "id"       => $gate["id"],
                "name"     => $gate["name"],
                "distance" => round($dist, 1),
                "inRange"  => $dist <= 50,
            ];
        }
        usort($result, fn($a, $b) => $a["distance"] <=> $b["distance"]);
        echo json_encode(["success" => true, "gates" => $result]);
        exit();
    }

    if ($action === "unlock_gate") {
        $gateId = filter_input(INPUT_POST, "gate_id", FILTER_VALIDATE_INT);
        $lat    = filter_input(INPUT_POST, "lat", FILTER_VALIDATE_FLOAT);
        $lng    = filter_input(INPUT_POST, "lng", FILTER_VALIDATE_FLOAT);

        if (!$gateId || $lat === false || $lng === false || $lat === null || $lng === null) {
            echo json_encode(["success" => false, "error" => "Invalid request parameters"]);
            exit();
        }

        $gate = null;
        foreach ($gates as $g) {
            if ($g["id"] === $gateId) { $gate = $g; break; }
        }
        if (!$gate) {
            echo json_encode(["success" => false, "error" => "Gate not found"]);
            exit();
        }

        // Server-side distance check — client coordinates are not trusted alone
        $dist = haversineDistance($lat, $lng, $gate["lat"], $gate["lng"]);
        if ($dist > 50) {
            echo json_encode([
                "success"  => false,
                "error"    => "You are " . round($dist) . "m from " . $gate["name"] . ". Must be within 50m to unlock.",
                "distance" => round($dist, 1),
            ]);
            exit();
        }

        $gateName   = $gate["name"];
        $distRounded = round($dist, 2);
        $stmt = $conn->prepare(
            "INSERT INTO proximity_unlock_log
             (user_email, gate_id, gate_name, resident_lat, resident_lng, distance_meters)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param("sisddd", $userEmail, $gateId, $gateName, $lat, $lng, $distRounded);
        $stmt->execute();
        $stmt->close();

        // TODO: integrate with gate hardware controller here (HTTP/MQTT/relay trigger)

        echo json_encode([
            "success"  => true,
            "message"  => $gate["name"] . " is opening. Please proceed.",
            "distance" => round($dist, 1),
            "gate"     => $gate["name"],
        ]);
        exit();
    }

    echo json_encode(["success" => false, "error" => "Unknown action"]);
    exit();
}

// Fetch recent unlock log for the current user
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
$logResult = $logStmt->get_result();
while ($row = $logResult->fetch_assoc()) {
    $recentLogs[] = $row;
}
$logStmt->close();
$conn->close();

$currentUser = $_SESSION["user"];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gate Proximity Unlock - MBGE</title>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: Arial, sans-serif;
      background: #f4f7fa;
      margin: 0;
      padding: 20px;
    }
    .container { max-width: 480px; margin: auto; }

    .header { text-align: center; margin-bottom: 20px; }
    .header img { width: 130px; margin-bottom: 10px; }
    .header h2 { margin: 0 0 4px; color: #222; }
    .subtitle { color: #666; font-size: 14px; margin: 0; }

    .card {
      background: white;
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 16px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .loc-status {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 14px;
      font-size: 15px;
    }
    .loc-idle    { background: #f0f4ff; color: #444; }
    .loc-loading { background: #fff8e1; color: #e65100; }
    .loc-active  { background: #e8f5e9; color: #2e7d32; }
    .loc-error   { background: #fdecea; color: #c62828; }

    .accuracy-info {
      font-size: 12px;
      color: #888;
      margin-bottom: 12px;
      font-family: monospace;
    }

    button {
      width: 100%;
      padding: 14px;
      font-size: 16px;
      background: #007bff;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
    }
    button:hover:not(:disabled) { background: #0056b3; }
    button:disabled { background: #bbb; cursor: not-allowed; }

    #gatesList h3 { margin: 0 0 12px; color: #333; font-size: 16px; }

    .gate-card {
      background: white;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.07);
      border-left: 4px solid #ccc;
    }
    .gate-in-range  { border-left-color: #28a745; }
    .gate-out-range { border-left-color: #dc3545; }
    .gate-unlocked  { border-left-color: #007bff; background: #f0f8ff; }

    .gate-info {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
    }
    .gate-icon    { font-size: 22px; line-height: 1; }
    .gate-details { flex: 1; }
    .gate-name    { font-weight: bold; font-size: 16px; color: #222; }
    .gate-dist    { font-size: 13px; color: #666; margin-top: 2px; }

    .badge {
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: bold;
      white-space: nowrap;
    }
    .badge-green { background: #d4edda; color: #155724; }
    .badge-grey  { background: #f0f0f0; color: #555; }

    .unlock-btn {
      width: 100%;
      padding: 12px;
      font-size: 15px;
      background: #28a745;
      color: white;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background 0.2s;
    }
    .unlock-btn:hover:not(:disabled) { background: #1e7e34; }
    .unlock-btn:disabled { background: #ccc; cursor: not-allowed; }
    .unlock-btn-success { background: #155724 !important; }

    .info-note {
      background: #fff8e1;
      border-radius: 8px;
      padding: 12px 16px;
      margin: 4px 0 16px;
      font-size: 14px;
      color: #555;
      border-left: 3px solid #ffc107;
    }

    .dev-note {
      background: #e8f0fe;
      border-radius: 8px;
      padding: 12px 16px;
      margin-bottom: 16px;
      font-size: 13px;
      color: #1a3a6b;
      border-left: 3px solid #4285f4;
    }
    .dev-note strong { display: block; margin-bottom: 4px; }

    .log-section h3 { font-size: 15px; color: #444; margin: 0 0 10px; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th, td { padding: 8px 10px; border: 1px solid #ddd; text-align: left; }
    th { background: #f0f0f0; color: #555; font-weight: 600; }
    .no-log { color: #999; font-style: italic; font-size: 13px; }

    .nav-link {
      display: block;
      text-align: center;
      color: #007bff;
      text-decoration: none;
      margin-top: 20px;
      font-size: 15px;
    }
    .nav-link:hover { text-decoration: underline; }

    .toast {
      position: fixed;
      bottom: 30px;
      left: 50%;
      transform: translateX(-50%) translateY(120px);
      background: #333;
      color: white;
      padding: 14px 24px;
      border-radius: 10px;
      font-size: 15px;
      max-width: 90%;
      text-align: center;
      transition: transform 0.3s ease, opacity 0.3s ease;
      z-index: 9999;
      opacity: 0;
      pointer-events: none;
    }
    .toast-visible { transform: translateX(-50%) translateY(0); opacity: 1; }
    .toast-success { background: #28a745; }
    .toast-error   { background: #dc3545; }

    .loading-text { color: #888; text-align: center; padding: 12px; }
    .error-text   { color: #dc3545; text-align: center; padding: 12px; }
  </style>
</head>
<body>
<div class="container">

  <div class="header">
    <img src="logo.png" alt="MBGE Logo">
    <h2>Gate Proximity Unlock</h2>
    <p class="subtitle">Logged in as: <?php echo htmlspecialchars($currentUser); ?></p>
  </div>

  <div class="dev-note">
    <strong>Development / Test Mode</strong>
    Gate coordinates are placeholders. Update the <code>$gates</code> array in
    <code>gate_proximity_unlock.php</code> with actual GPS coordinates before going live.
  </div>

  <div class="card">
    <div id="locStatus" class="loc-status loc-idle">
      <span>📍</span>
      <span id="statusText">Tap the button to get your location</span>
    </div>
    <div id="accuracyInfo" class="accuracy-info" style="display:none;"></div>
    <button id="locateBtn" onclick="getLocation()">Find Nearby Gates</button>
  </div>

  <div id="gatesList" style="display:none;">
    <h3>Gates</h3>
    <div id="gatesContainer"></div>
  </div>

  <div class="info-note">
    You can unlock a gate when you are <strong>within 50 metres</strong> of it.
    Every unlock is logged with your account and timestamp.
  </div>

  <div class="card log-section">
    <h3>Your Recent Unlocks</h3>
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

<div id="toast" class="toast"></div>

<script>
  let userLat = null;
  let userLng = null;

  function getLocation() {
    const btn = document.getElementById("locateBtn");
    const statusEl = document.getElementById("locStatus");
    const statusText = document.getElementById("statusText");

    if (!navigator.geolocation) {
      showToast("Geolocation is not supported by your browser.", "error");
      return;
    }

    btn.disabled = true;
    btn.textContent = "Getting location…";
    statusEl.className = "loc-status loc-loading";
    statusText.textContent = "Acquiring GPS signal…";

    navigator.geolocation.getCurrentPosition(
      function(pos) {
        userLat = pos.coords.latitude;
        userLng = pos.coords.longitude;
        const acc = Math.round(pos.coords.accuracy);

        statusEl.className = "loc-status loc-active";
        statusText.textContent = "Location found (±" + acc + " m accuracy)";

        const accInfo = document.getElementById("accuracyInfo");
        accInfo.style.display = "block";
        accInfo.textContent = "Lat: " + userLat.toFixed(6) + "  Lng: " + userLng.toFixed(6);

        btn.textContent = "Refresh Location";
        btn.disabled = false;

        checkProximity(userLat, userLng);
      },
      function(err) {
        let msg = "Location error: ";
        if (err.code === err.PERMISSION_DENIED)   msg += "Permission denied. Please allow location access in your browser.";
        else if (err.code === err.POSITION_UNAVAILABLE) msg += "Position unavailable.";
        else if (err.code === err.TIMEOUT)         msg += "Request timed out. Try again.";
        else                                        msg += "Unknown error.";

        statusEl.className = "loc-status loc-error";
        statusText.textContent = msg;
        btn.textContent = "Try Again";
        btn.disabled = false;
        showToast(msg, "error");
      },
      { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
  }

  function checkProximity(lat, lng) {
    const gatesList = document.getElementById("gatesList");
    const container = document.getElementById("gatesContainer");
    container.innerHTML = '<p class="loading-text">Checking gate distances…</p>';
    gatesList.style.display = "block";

    post({ action: "check_proximity", lat: lat, lng: lng })
      .then(data => {
        if (!data.success) {
          container.innerHTML = '<p class="error-text">' + escHtml(data.error) + '</p>';
          return;
        }
        renderGates(data.gates);
      })
      .catch(() => {
        container.innerHTML = '<p class="error-text">Failed to check gate proximity. Please try again.</p>';
      });
  }

  function renderGates(gates) {
    const container = document.getElementById("gatesContainer");
    container.innerHTML = "";

    gates.forEach(gate => {
      const distLabel = gate.distance < 1000
        ? gate.distance + " m"
        : (gate.distance / 1000).toFixed(1) + " km";

      const badge = gate.inRange
        ? '<span class="badge badge-green">In Range</span>'
        : '<span class="badge badge-grey">' + escHtml(distLabel) + ' away</span>';

      const card = document.createElement("div");
      card.className = "gate-card " + (gate.inRange ? "gate-in-range" : "gate-out-range");
      card.id = "gate-card-" + gate.id;

      card.innerHTML =
        '<div class="gate-info">' +
          '<div class="gate-icon">' + (gate.inRange ? "🟢" : "🔴") + '</div>' +
          '<div class="gate-details">' +
            '<div class="gate-name">' + escHtml(gate.name) + '</div>' +
            '<div class="gate-dist">' + escHtml(distLabel) + '</div>' +
          '</div>' +
          badge +
        '</div>' +
        '<button class="unlock-btn" id="unlock-btn-' + gate.id + '" ' +
          (gate.inRange ? '' : 'disabled ') +
          'onclick="unlockGate(' + gate.id + ', \'' + escHtml(gate.name) + '\')">' +
          (gate.inRange ? 'Unlock Gate' : 'Out of Range') +
        '</button>';

      container.appendChild(card);
    });
  }

  function unlockGate(gateId, gateName) {
    if (userLat === null || userLng === null) {
      showToast("Location not available. Get your location first.", "error");
      return;
    }

    const btn = document.getElementById("unlock-btn-" + gateId);
    btn.disabled = true;
    btn.textContent = "Unlocking…";

    post({ action: "unlock_gate", gate_id: gateId, lat: userLat, lng: userLng })
      .then(data => {
        if (data.success) {
          btn.textContent = "Unlocked ✓";
          btn.className = "unlock-btn unlock-btn-success";
          document.getElementById("gate-card-" + gateId).className = "gate-card gate-unlocked";
          showToast(data.message, "success");
          // Re-enable after 5 s so the tester can try again without refreshing
          setTimeout(() => {
            btn.textContent = "Unlock Again";
            btn.disabled = false;
            btn.className = "unlock-btn";
            document.getElementById("gate-card-" + gateId).className = "gate-card gate-in-range";
          }, 5000);
        } else {
          btn.textContent = "Unlock Gate";
          btn.disabled = false;
          showToast(data.error, "error");
        }
      })
      .catch(() => {
        btn.textContent = "Unlock Gate";
        btn.disabled = false;
        showToast("Network error. Please try again.", "error");
      });
  }

  function post(params) {
    const body = Object.entries(params)
      .map(([k, v]) => encodeURIComponent(k) + "=" + encodeURIComponent(v))
      .join("&");
    return fetch("gate_proximity_unlock.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body
    }).then(r => r.json());
  }

  function showToast(msg, type) {
    const toast = document.getElementById("toast");
    toast.textContent = msg;
    toast.className = "toast toast-" + type + " toast-visible";
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => { toast.className = "toast"; }, 4500);
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
</script>
</body>
</html>
