<?php
// ============================================================
// GEMB Communications Portal — layout.php
// /home/gembcoza/public_html/comms/layout.php
//
// Self-contained layout for the comms module.
// Zero dependency on the access control system's layout.php.
//
// Provides:
//   pageHeader(string $title)   — outputs full HTML head + CSS
//   pageFooter()                — closes body/html, no SW
//   renderHeader(string $title, string $backUrl)
//                               — sticky top bar with back link
//   All CSS design tokens tuned for the comms portal
// ============================================================

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Security headers ─────────────────────────────────────
if (!headers_sent()) {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header(
        "Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none';"
    );
}

// ─────────────────────────────────────────────────────────
// pageHeader()
// Outputs the full HTML <head> and opens <body>.
// $title appears in the browser tab.
// ─────────────────────────────────────────────────────────
function pageHeader(string $title, string $role = ''): void {
    // $role parameter kept for API compatibility with comms files
    // that pass 'admin' or 'public' — not used for colour logic here.
    $GLOBALS['_comms_page_role'] = $role;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= htmlspecialchars($title) ?> | GEMB Communications</title>
<meta name="theme-color" content="#0D47A1">
<style>
/* ══════════════════════════════════════════════════════
   GEMB Communications Portal — Design System
   ══════════════════════════════════════════════════════ */

/* ── Tokens ── */
:root {
  --accent:      #0D47A1;   /* GEMB navy */
  --accent-dark: #0A2F6B;
  --accent-hover:#1565C0;
  --bg:          #F0F4FA;
  --card-bg:     #FFFFFF;
  --text:        #1A1A2E;
  --muted:       #64748B;
  --border:      #DEE2E6;
  --success:     #28a745;
  --danger:      #dc3545;
  --warning:     #ffc107;
  --info:        #17a2b8;
  --radius:      10px;
  --shadow:      0 2px 12px rgba(0,0,0,.08);
  --font:        -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); font-family: var(--font); color: var(--text); min-height: 100vh; }

/* ── Sticky top bar (rendered by renderHeader()) ── */
.site-header {
  background: var(--accent); color: #fff;
  padding: 0 16px;
  display: flex; align-items: center; justify-content: space-between;
  height: 56px;
  position: sticky; top: 0; z-index: 100;
  box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.site-header h1 { font-size: 1.05rem; font-weight: 700; letter-spacing: .3px; }
.site-header a.back-btn {
  color: rgba(255,255,255,.85); font-size: .8rem; text-decoration: none;
  border: 1px solid rgba(255,255,255,.4); padding: 4px 12px; border-radius: 20px;
  transition: background .15s;
}
.site-header a.back-btn:hover { background: rgba(255,255,255,.15); }

/* ── Page containers ── */
.pc-main  { max-width: 900px;  margin: 0 auto; padding: 20px 16px 80px; }
.container { max-width: 860px; margin: 0 auto; padding: 20px 16px 60px; }

/* ── Cards ── */
.card {
  background: var(--card-bg); border-radius: var(--radius);
  box-shadow: var(--shadow); padding: 20px; margin-bottom: 18px;
}
.card-wide { max-width: 100%; }
.card-title { font-size: 1rem; font-weight: 700; margin-bottom: 14px; color: var(--accent); }
.card-toolbar {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px; margin-bottom: 16px;
}

/* ── Section heading inside a card ── */
.section-title {
  font-size: .9rem; font-weight: 700; color: var(--accent);
  margin-bottom: 12px; text-transform: uppercase; letter-spacing: .5px;
}

/* ── Buttons ── */
.btn {
  display: inline-block; padding: 10px 22px; border-radius: 6px;
  font-size: .92rem; font-weight: 600; cursor: pointer; border: none;
  text-decoration: none; text-align: center; transition: opacity .2s, transform .1s;
}
.btn:hover   { opacity: .88; }
.btn:active  { transform: scale(.98); }
.btn-primary { background: var(--accent);       color: #fff; }
.btn-green   { background: #2e7d32;             color: #fff; }
.btn-blue    { background: #1565c0;             color: #fff; }
.btn-navy    { background: var(--accent-dark);  color: #fff; }
.btn-amber   { background: #e65100;             color: #fff; }
.btn-red     { background: var(--danger);       color: #fff; }
.btn-secondary { background: var(--muted);      color: #fff; }
.btn-sm      { padding: 5px 12px; font-size: .82rem; }
.btn-block   { display: block; width: 100%; }
.btn-group   { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.btn-group-sm { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

/* ── Forms ── */
.form-group { margin-bottom: 14px; }
.form-group label {
  display: block; font-size: .82rem; font-weight: 700;
  margin-bottom: 5px; color: var(--muted);
}
.form-group input,
.form-group select,
.form-group textarea {
  width: 100%; padding: 10px 12px;
  border: 1.5px solid var(--border); border-radius: 6px;
  font-size: .95rem; font-family: var(--font);
  background: #fff; color: var(--text);
  transition: border-color .2s, box-shadow .2s;
}
.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
  outline: none; border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(13,71,161,.1);
}
.form-row {
  display: grid; grid-template-columns: 1fr 1fr; gap: 14px;
}
@media (max-width: 540px) { .form-row { grid-template-columns: 1fr; } }
.required { color: var(--danger); margin-left: 2px; }

/* ── Tables ── */
.table-responsive { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.data-table th {
  background: var(--accent); color: #fff;
  padding: 9px 12px; text-align: left; font-weight: 700;
}
.data-table td { padding: 8px 12px; border-bottom: 1px solid var(--border); }
.data-table tr:hover td { background: #f5f7ff; }
.data-table .text-center { text-align: center; }

/* ── Badges ── */
.badge {
  display: inline-block; padding: 3px 10px; border-radius: 20px;
  font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
}
.badge-green  { background: #d4edda; color: #155724; }
.badge-amber  { background: #fff3cd; color: #7d5a00; }
.badge-grey   { background: #e2e3e5; color: #495057; }
.badge-blue   { background: #cce5ff; color: #004085; }
.badge-red    { background: #f8d7da; color: #721c24; }
.badge-info   { background: #d1ecf1; color: #0c5460; }

/* ── Alerts ── */
.alert {
  padding: 12px 16px; border-radius: 6px;
  margin-bottom: 14px; font-size: .92rem; line-height: 1.5;
}
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-danger  { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.alert-info    { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
.alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
.alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

/* ── Survey / question rows ── */
.question-row   { padding: 14px 0; }
.question-meta  { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.q-number       { font-size: .8rem; font-weight: 800; color: var(--muted); min-width: 20px; }
.question-text  { font-weight: 600; font-size: .97rem; margin-bottom: 8px; }
.option-list    { margin: 6px 0 0 28px; font-size: .88rem; color: #555; }
.option-list li { margin-bottom: 3px; }
.q-divider      { border: none; border-top: 1px solid var(--border); margin: 4px 0; }

/* ── Misc helpers ── */
.muted-note  { font-size: .82rem; color: var(--muted); }
.text-center { text-align: center; }

/* ── POPIA notice ── */
.popia-notice {
  font-size: .75rem; color: var(--muted);
  border-top: 1px solid var(--border);
  margin-top: 16px; padding-top: 10px; line-height: 1.5;
}

/* ── Footer ── */
.site-footer {
  text-align: center; font-size: .72rem; color: var(--muted);
  padding: 14px; border-top: 1px solid var(--border); margin-top: 30px;
}

/* ── Login card (used by comms_login.php public page) ── */
.login-wrap {
  min-height: 100vh; display: flex;
  align-items: center; justify-content: center;
  background: linear-gradient(135deg, var(--accent) 0%, #1a1a2e 100%);
  padding: 20px;
}
.login-card {
  background: #fff; border-radius: 14px; padding: 36px 28px;
  width: 100%; max-width: 400px;
  box-shadow: 0 8px 32px rgba(0,0,0,.25);
}
.login-card h2 { text-align: center; color: var(--accent); margin-bottom: 6px; }
.login-card .subtitle {
  text-align: center; color: var(--muted);
  font-size: .85rem; margin-bottom: 24px;
}
.login-logo { text-align: center; font-size: 3rem; margin-bottom: 10px; }

/* ── Mobile ── */
@media (max-width: 600px) {
  .pc-main   { padding: 12px 10px 60px; }
  .container { padding: 12px 10px 60px; }
  .hide-mobile { display: none; }
  .btn-group, .btn-group-sm { flex-direction: column; align-items: stretch; }
  .btn-group .btn, .btn-group-sm .btn { width: 100%; }
}
</style>
</head>
<body>
    <?php
}

// ─────────────────────────────────────────────────────────
// pageFooter()
// Closes body/html. No service worker — comms portal
// is admin-only and does not need offline capability.
// ─────────────────────────────────────────────────────────
function pageFooter(): void {
    ?>
<div class="site-footer">
  GEMB Communications Portal &nbsp;|&nbsp; POPIA Act 4 of 2013 &nbsp;|&nbsp; HOA Reg. 1999/001249/08
</div>
</body>
</html>
    <?php
}

// ─────────────────────────────────────────────────────────
// renderHeader()
// Sticky top bar with title and a back/logout link.
// $backUrl  — href for the right-side button.
//             Pass 'comms_menu.php' for most pages,
//             or 'comms_login.php?action=logout' for the menu.
// ─────────────────────────────────────────────────────────
function renderHeader(string $title, string $backUrl = 'comms_menu.php'): void {
    $label = (str_contains($backUrl, 'logout')) ? 'Logout' : '← Back';
    echo '<div class="site-header">';
    echo '<h1>📣 ' . htmlspecialchars($title) . '</h1>';
    echo '<a href="' . htmlspecialchars($backUrl) . '" class="back-btn">' . $label . '</a>';
    echo '</div>';
}
