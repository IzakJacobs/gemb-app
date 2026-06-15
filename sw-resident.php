<?php
header('Content-Type: application/javascript');
header('Cache-Control: no-cache');
?>
// MBGE Resident Service Worker
const CACHE = 'mbge-resident-v1';
const OFFLINE_URL = '/resident.php?action=login';
self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.add(OFFLINE_URL))
    );
    self.skipWaiting();
});
self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(k => k !== CACHE).map(k => caches.delete(k))
            )
        )
    );
    self.clients.claim();
});
self.addEventListener('fetch', e => {
    // Never intercept non-GET requests (form POSTs, etc.) — let the
    // browser handle them natively. Re-fetching a POST request inside
    // a service worker can fail because the request body cannot be
    // reliably re-read, causing form submissions to silently fail.
    if (e.request.method !== 'GET') {
        return;
    }

    if (e.request.mode === 'navigate') {
        e.respondWith(
            fetch(e.request).catch(() => caches.match(OFFLINE_URL))
        );
    }
});
