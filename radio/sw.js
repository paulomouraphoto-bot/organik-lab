// Offline shell for Tuner.
// The page is network-first (so updates arrive); icons are cache-first;
// station lists are network-first with the last good copy as a fallback.
// Audio streams and now-playing info are never cached.
const CACHE = 'tuner-v1';
const CORE = ['./', './manifest.webmanifest', './icons/icon-192.png', './icons/icon-512.png', './icons/apple-touch-icon.png', './icons/organik-mark.png'];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k.startsWith('tuner-') && k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;            // streams, artwork, fonts: straight to the network

  if (req.mode === 'navigate') {
    e.respondWith(
      fetch(req)
        .then(res => { const copy = res.clone(); caches.open(CACHE).then(c => c.put('./', copy)); return res; })
        .catch(() => caches.match('./'))
    );
    return;
  }

  if (url.pathname.endsWith('/api.php')) {
    const a = url.searchParams.get('a');
    if (a !== 'band' && a !== 'countries' && a !== 'search') return;   // live data: network only
    e.respondWith(
      fetch(req)
        .then(res => { if (res.ok) { const copy = res.clone(); caches.open(CACHE).then(c => c.put(req, copy)); } return res; })
        .catch(() => caches.match(req).then(r => r || Response.error()))
    );
    return;
  }

  e.respondWith(caches.match(req).then(hit => hit || fetch(req)));
});
