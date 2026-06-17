GEMB ACCESS CONTROL SYSTEM
==========================
Version: 2.0 — Rebuilt May 2026
25 files (15 core + 3 panic + 7 support)

QUICK START
-----------
1. Create a MySQL database on your hosting
2. Import install.sql into the database
3. Edit config.php — set DB_HOST, DB_NAME, DB_USER, DB_PASS
4. Upload ALL files to your web root (public_html)
5. Visit https://gemb.ink/AS-menu.html
6. Login as admin / password: Admin@GEMB2026
7. IMMEDIATELY change the admin password

DEFAULT LOGIN
-------------
Username: admin
Password: Admin@GEMB2026  ← CHANGE THIS IMMEDIATELY

FILE MAP
--------
AS-menu.html          Splash screen / entry point
config.php            Database credentials (PROTECTED)
layout.php            Shared CSS, header, footer, auth guards
logout.php            Universal session logout
guard.php             Guard gate verification (login|verify|reset)
security.php          Security officer portal (login|menu|approvals|logs|qr)
resident.php          Resident portal (login|menu|vehicles|comms|helpdesk|reset)
visitor.php           Visitor management (select|add|delete)
visitor_qr.php        PUBLIC — visitor QR landing page
service_qr_verify.php PUBLIC — service provider QR page
admin.php             Admin portal (login|menu|add users|helpdesk|cleanup)
residents_admin.php   Resident CRUD (list|add|edit|delete)
newsletters_admin.php Newsletter/notice upload (upload|delete)
export.php            Data exports (CSV downloads + activity report)
cron_cleanup.php      Nightly POPIA data purge
manifest.json         PWA manifest
service-worker.js     PWA offline caching
offline.html          PWA offline fallback page
install.sql           Database installation script
.htaccess             Security rules (HTTPS, protected files)
panic/panic_login.php Panic alert login
panic/panic_menu.php  Panic alert confirmation
panic/panic_send.php  Panic alert dispatch + log
icons/                PWA icons (generate 8 sizes from your logo)
uploads/              Newsletter/document uploads (writable by server)

CRON JOB SETUP (cPanel)
-----------------------
0 2 * * *   /usr/bin/php /home/USERNAME/public_html/cron_cleanup.php

GATES
-----
SSgate — Schoeman Street
CSgate — Church Street

ROLES
-----
Admin      → Full system access
Security   → SP approvals, guards, logs, QR lookup
Guard      → Gate verification, access log
Resident   → Visitors, vehicles, notices, helpdesk
Panic      → Emergency alert (guard credentials)

POPIA COMPLIANCE
----------------
- Data retained 90 days (configurable in settings table)
- No criminal record checks (unconstitutional)
- Biometric data not collected
- All personal data has POPIA notices on capture forms

HOA REGISTRATION: 1999/001249/08
