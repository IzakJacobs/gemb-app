<?php
// ============================================================
// GEMB Communications Portal — comms.php
// Welcome / splash screen.
// Automatically redirects to the login page after 10 seconds.
// Standalone — no shared styling or dependencies with the
// estate access control system.
// ============================================================

if (session_status() === PHP_SESSION_NONE) session_start();

// If already authenticated, skip the splash entirely.
if (!empty($_SESSION['comms_logged_in']) || !empty($_SESSION['admin_id'])) {
    header('Location: comms_menu.php'); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="10;url=comms_login.php">
  <title>GEMB Communications Portal</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: linear-gradient(150deg, #0D2B5E 0%, #0D47A1 45%, #0277BD 100%);
      color: #fff;
      padding: 24px 16px;
      text-align: center;
    }

    /* ── Branding ── */
    .brand { margin-bottom: 40px; }
    .brand-icon {
      font-size: 4.5rem;
      display: block;
      margin-bottom: 14px;
      filter: drop-shadow(0 4px 12px rgba(0,0,0,.3));
    }
    .brand h1 {
      font-size: 2.1rem;
      font-weight: 800;
      letter-spacing: -0.5px;
      margin-bottom: 8px;
      text-shadow: 0 2px 8px rgba(0,0,0,.2);
    }
    .brand .tagline {
      font-size: 1rem;
      opacity: .8;
      font-weight: 400;
      letter-spacing: .3px;
    }

    /* ── Feature tiles ── */
    .features {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      max-width: 680px;
      width: 100%;
      margin-bottom: 50px;
    }
    .ftile {
      background: rgba(255,255,255,.1);
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 14px;
      padding: 22px 12px 18px;
      backdrop-filter: blur(6px);
      transition: background .2s;
    }
    .ftile:hover { background: rgba(255,255,255,.18); }
    .ftile .fi   { font-size: 2rem; display: block; margin-bottom: 8px; }
    .ftile .fn   { font-size: .82rem; font-weight: 600; line-height: 1.3; }

    /* ── Countdown ── */
    .cta-area { display: flex; flex-direction: column; align-items: center; gap: 18px; }

    .progress-wrap {
      width: 240px;
      background: rgba(255,255,255,.2);
      border-radius: 4px;
      height: 5px;
      overflow: hidden;
    }
    .progress-fill {
      height: 100%;
      background: #fff;
      border-radius: 4px;
      width: 100%;
      animation: countdown-shrink 10s linear forwards;
    }
    @keyframes countdown-shrink {
      from { width: 100%; }
      to   { width: 0%;   }
    }

    .countdown-text {
      font-size: .9rem;
      opacity: .75;
    }
    .countdown-text strong {
      font-size: 1.5rem;
      font-weight: 800;
      opacity: 1;
      display: inline-block;
      min-width: 1.5ch;
      color: #fff;
    }

    .btn-signin {
      display: inline-block;
      background: #fff;
      color: #0D47A1;
      font-size: 1rem;
      font-weight: 800;
      padding: 14px 44px;
      border-radius: 50px;
      text-decoration: none;
      letter-spacing: .3px;
      box-shadow: 0 4px 20px rgba(0,0,0,.25);
      transition: transform .15s, box-shadow .15s;
    }
    .btn-signin:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 30px rgba(0,0,0,.35);
    }

    /* ── Footer ── */
    .splash-footer {
      position: fixed;
      bottom: 16px;
      left: 0; right: 0;
      font-size: .72rem;
      opacity: .45;
      text-align: center;
    }

    @media (max-width: 580px) {
      .brand h1     { font-size: 1.5rem; }
      .brand-icon   { font-size: 3rem; }
      .features     { grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 36px; }
      .ftile        { padding: 16px 10px 14px; }
      .ftile .fi    { font-size: 1.6rem; }
    }
  </style>
</head>
<body>

  <div class="brand">
    <span class="brand-icon">📣</span>
    <h1>GEMB Communications</h1>
    <p class="tagline">Estate Communications &amp; Engagement Portal</p>
  </div>

  <div class="features">
    <div class="ftile">
      <span class="fi">📤</span>
      <span class="fn">Bulk Messages</span>
    </div>
    <div class="ftile">
      <span class="fi">💰</span>
      <span class="fn">Levy Statements</span>
    </div>
    <div class="ftile">
      <span class="fi">📋</span>
      <span class="fn">Surveys &amp; Polls</span>
    </div>
    <div class="ftile">
      <span class="fi">🗳️</span>
      <span class="fn">Voting</span>
    </div>
  </div>

  <div class="cta-area">
    <p class="countdown-text">
      Redirecting in <strong id="cnt">10</strong> s
    </p>
    <div class="progress-wrap">
      <div class="progress-fill"></div>
    </div>
    <a href="comms_login.php" class="btn-signin">Sign In Now &rarr;</a>
  </div>

  <div class="splash-footer">
    GEMB Estate &nbsp;&middot;&nbsp; Communications Module &nbsp;&middot;&nbsp; POPIA Act 4 of 2013
  </div>

  <script>
    (function () {
      var n = 10;
      var el = document.getElementById('cnt');
      var t = setInterval(function () {
        n--;
        if (el) el.textContent = n;
        if (n <= 0) {
          clearInterval(t);
          window.location.href = 'comms_login.php';
        }
      }, 1000);
    })();
  </script>

</body>
</html>
