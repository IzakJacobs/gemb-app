<?php
// ============================================================
// MBGE Resident PWA — resident-app.php
// Entry point for the resident PWA home screen shortcut.
// If already logged in → go to menu
// If not logged in → go to login
// This file also serves the PWA manifest link for the
// resident app so it gets its own icon and name.
// ============================================================
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['resident_id'])) {
    header('Location: resident.php?action=menu'); exit;
} else {
    header('Location: resident.php?action=login'); exit;
}
