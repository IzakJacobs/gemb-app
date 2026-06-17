/*
 * MBGE Gate — Service Worker
 * Caches the verify page and assets so the app works
 * even when the Pi itself is temporarily unreachable.
 */

const CACHE_NAME   = 'mbge-gate-v1';
const CACHE_ASSETS = [
    '/mbge/verify.php',
    '/mbge/manifest.json',
    '/mbge/icons/icon-192.png',
    '/mbge/icons/icon-512.png',
];

// ── Install: cache core assets ─────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(CACHE_ASSETS);
        })
    );
    self.skipWaiting();
});

// ── Activate: clean up old caches ─────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(
                keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
            )
        )
    );
    self.clients.claim();
});

// ── Fetch: network first, fall back to cache ───────────────
// For the verify page: always try network first (Pi is local LAN,
// so network is fast). Fall back to cache if Pi is unreachable.
self.addEventListener('fetch', event => {
    event.respondWith(
        fetch(event.request)
            .then(response => {
                // Cache a fresh copy on success
                if (response && response.status === 200) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, copy);
                    });
                }
                return response;
            })
            .catch(() => {
                // Network failed — serve from cache
                return caches.match(event.request);
            })
    );
});
