'use strict';
/* Hostkeep service worker: makes the panel installable and shows a page when the server is unreachable.
   Network first for everything. Only static files are cached (fallback when offline):
   never the API, the logged-in pages (they hold the CSRF token) or the login form. */
const CACHE = 'hostkeep-v2';
const OFFLINE = '/offline.html';
const PRECACHE = [OFFLINE, '/assets/app.css', '/assets/favicon.svg', '/assets/icons/icon-192.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

self.addEventListener('fetch', e => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;

  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
    return;
  }
  if (url.pathname.startsWith('/assets/') || url.pathname === OFFLINE) {
    e.respondWith(fetch(req).then(res => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req)));
  }
  /* everything else (api.php…): straight to the network, no cache */
});
