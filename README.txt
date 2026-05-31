MBGE ACCESS CONTROL SYSTEM
==========================
Version: 2.1 — Updated May 2026
26 files (16 core + 3 panic + 7 support)

QUICK START
-----------
1. Create a MySQL database on your hosting
2. Import install.sql into the database
3. Edit config.php — set DB_HOST, DB_NAME, DB_USER, DB_PASS
4. Upload ALL files to your web root (public_html)
5. Visit https://mbge.ink/AS-menu.html
6. Login as admin / password: Admin@MBGE2026
7. IMMEDIATELY change the admin password

DEFAULT LOGIN
-------------
Username: admin
Password: Admin@MBGE2026  ← CHANGE THIS IMMEDIATELY

FILE MAP
--------
AS-menu.html              Splash screen / entry point
config.php                Database credentials (PROTECTED)
layout.php                Shared CSS, header, footer, auth guards
logout.php                Universal session logout
guard.php                 Guard gate verification (login|verify|reset)
security.php              Security officer portal (login|menu|approvals|logs|qr)
resident.php              Resident portal (login|menu|vehicles|comms|helpdesk|reset)
visitor.php               Visitor management (select|add|delete) + Open Gate shortcut
visitor_qr.php            PUBLIC — visitor QR pass + GPS navigation to resident's home
gate_proximity_unlock.php Resident proximity gate unlock (GPS, 100 m limit, ESP32 trigger)
service_qr_verify.php     PUBLIC — service provider QR page
admin.php                 Admin portal (login|menu|add users|helpdesk|cleanup)
residents_admin.php       Resident CRUD (list|add|edit|delete)
newsletters_admin.php     Newsletter/notice upload (upload|delete)
export.php                Data exports (CSV downloads + activity report)
cron_cleanup.php          Nightly POPIA data purge
manifest.json             PWA manifest
service-worker.js         PWA offline caching
offline.html              PWA offline fallback page
install.sql               Database installation script
.htaccess                 Security rules (HTTPS, protected files)
panic/panic_login.php     Panic alert login
panic/panic_menu.php      Panic alert confirmation
panic/panic_send.php      Panic alert dispatch + log
icons/                    PWA icons (generate 8 sizes from your logo)
uploads/                  Newsletter/document uploads (writable by server)

CRON JOB SETUP (cPanel)
-----------------------
0 2 * * *   /usr/bin/php /home/USERNAME/public_html/cron_cleanup.php

GATES
-----
SSgate — Schoeman Street  (3 gates: SS Gate 1, SS Gate 2, SS Gate 3)
CSgate — Church Street    (2 gates: CS Gate 1, CS Gate 2)

Gate coordinates (decimal degrees, WGS84):
  Schoeman Street: -34.189300827611795, 22.12257774021871
  Church Street:   -34.19164638860875,  22.137356846132185

Note: Individual gate lat/lng are currently set to the entrance centroid.
Update each gate's coordinates in gate_proximity_unlock.php once the
exact positions of each gate post have been surveyed on-site.

PROXIMITY GATE UNLOCK (v2.1)
-----------------------------
Residents can open a gate from their phone without triggering LPR/FR/Tag:
1. Resident opens visitor.php → taps the green "Open Gate" card
2. Selects entrance (Schoeman St or Church St) and specific gate
3. Browser GPS is compared server-side against the gate's registered coords
4. Within 100 m  → "Push Button to Open Gate" activates
5. Beyond 100 m  → "Too Far to Open Gate" shown with actual distance
6. Every unlock is logged to the proximity_unlock_log table (auto-created)
7. Hardware trigger: edit the TODO block in gate_proximity_unlock.php to
   send an HTTP/MQTT command to the ESP32 gate controller

GPS NAVIGATION FOR VISITORS (v2.1)
------------------------------------
The visitor pass page (visitor_qr.php) now includes a "Get Directions"
section below the QR code. Visitors tap one button to open navigation
in Google Maps, Waze, or Apple Maps — pre-loaded with the resident's
home address from the residents table.

ROLES
-----
Admin      → Full system access
Security   → SP approvals, guards, logs, QR lookup
Guard      → Gate verification, access log
Resident   → Visitors, vehicles, notices, helpdesk, gate proximity unlock
Panic      → Emergency alert (guard credentials)

POPIA COMPLIANCE
----------------
- Data retained 90 days (configurable in settings table)
- No criminal record checks (unconstitutional)
- Biometric data not collected
- All personal data has POPIA notices on capture forms
- proximity_unlock_log retains resident_id, gate, distance, timestamp only

HOA REGISTRATION: 1999/001249/08
