'use strict';
/* Hostkeep service worker: installable app (PWA), and a page when the server is unreachable.
 *
 * Network first, always: every request goes to the server, bypassing the browser's HTTP cache
 * (cache: 'no-cache' = revalidate), so a Hostkeep update shows up at the very next load.
 * The cache is only a fallback for static files when the network fails.
 * Never cached: the API, the logged-in pages (they hold the CSRF token), the login and 2FA forms.
 *
 * Bump VERSION when this file changes: the browser installs the new worker, which takes over at once
 * (skipWaiting + clients.claim) and empties the old caches; app.js then tells the user.
 */
const VERSION = 'v4';
const CACHE = `hostkeep-${VERSION}`;
const OFFLINE = '/offline.html';
const PRECACHE = [OFFLINE, '/assets/app.css', '/assets/favicon.svg', '/assets/icons/icon-192.png', '/assets/manifest.json'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE)
    .then(c => c.addAll(PRECACHE.map(u => new Request(u, { cache: 'reload' }))))
    .then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys()
    .then(keys => Promise.all(keys.filter(k => k.startsWith('hostkeep-') && k !== CACHE).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});

/* Lets a page ask "which version runs?" (shown nowhere, useful when debugging). */
self.addEventListener('message', e => {
  if (e.data === 'version') e.source?.postMessage({ sw: VERSION });
});

const isStatic = url => url.pathname.startsWith('/assets/') || url.pathname === OFFLINE;

self.addEventListener('fetch', e => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;

  // Pages: network (fresh), offline page if unreachable. Never stored: they carry the CSRF token.
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req, { cache: 'no-cache' }).catch(() => caches.match(OFFLINE)));
    return;
  }
  // Static files: network first (revalidated), the copy is kept for offline use.
  if (isStatic(url)) {
    e.respondWith(fetch(req, { cache: 'no-cache' }).then(res => {
      if (res.ok && res.type === 'basic') {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(url.pathname, copy));   // one entry per file, whatever its ?v=
      }
      return res;
    }).catch(() => caches.match(url.pathname, { ignoreSearch: true })));
  }
  // Everything else (api.php, downloads…): straight to the network, never cached.
});
