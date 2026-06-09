// MBGE Guard Portal — Service Worker
// Network-first for PHP (needs live DB), cache-first for static assets.

const CACHE = 'mbge-guard-v1';
const STATIC = [
    'logo.png',
    'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js',
];

self.addEventListener('install', evt => {
    evt.waitUntil(
        caches.open(CACHE)
            .then(c => c.addAll(STATIC))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', evt => {
    evt.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(k => k !== CACHE).map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', evt => {
    const req = evt.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);

    // PHP pages: network-first, fall back to cache for offline display
    if (url.pathname.endsWith('.php')) {
        evt.respondWith(
            fetch(req)
                .then(res => {
                    if (res.ok) {
                        caches.open(CACHE).then(c => c.put(req, res.clone()));
                    }
                    return res;
                })
                .catch(() => caches.match(req))
        );
        return;
    }

    // Static assets: cache-first
    evt.respondWith(
        caches.match(req).then(cached => {
            if (cached) return cached;
            return fetch(req).then(res => {
                if (res.ok) {
                    caches.open(CACHE).then(c => c.put(req, res.clone()));
                }
                return res;
            });
        })
    );
});
