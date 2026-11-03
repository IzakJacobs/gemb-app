<?php
header('Content-Type: application/javascript');
header('Cache-Control: no-cache');
?>
// GEMB Comms Service Worker
const CACHE = 'gemb-comms-v1';
const OFFLINE_URL = '/comms/comms_login.php';
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
    if (e.request.mode === 'navigate') {
        e.respondWith(
            fetch(e.request).catch(() => caches.match(OFFLINE_URL))
        );
    }
});
