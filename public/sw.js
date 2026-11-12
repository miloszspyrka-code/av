/* Alpha Vault — service worker: powłoka offline, powiadomienia, deep link do szybkiego dodania.
   Po zmianie plików JS podnieś VERSION, żeby wyczyścić stare cache. */
'use strict';

const VERSION = 'av-2026-11-12-2';
const SHELL = [
  '/manifest.webmanifest',
  '/assets/pwa/icon-192.png',
  '/assets/pwa/icon-512.png',
  '/assets/pwa/icon-maskable-512.png',
  '/assets/pwa/apple-touch-icon.png',
  '/js/core.js',
  '/js/data.js',
  '/js/state.js',
  '/js/render1.js',
  '/js/render2.js',
  '/js/pwa.js',
  '/js/actions.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(VERSION);
    await Promise.allSettled(SHELL.map(async (url) => {
      try {
        const res = await fetch(url, { cache: 'reload' });
        if (res.ok) await cache.put(url, res);
      } catch {}
    }));
    try {
      const res = await fetch('/', { cache: 'reload' });
      if (res.ok) await cache.put('/', res);
    } catch {}
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/auth/')) return;
  if (url.pathname === '/sw.js') return;

  if (request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const response = await fetch(request);
        if (response.ok) {
          const cache = await caches.open(VERSION);
          await cache.put('/', response.clone());
        }
        return response;
      } catch {
        const cached = await caches.match('/');
        return cached || Response.error();
      }
    })());
    return;
  }

  event.respondWith((async () => {
    const cached = await caches.match(request);
    const network = fetch(request).then(async (response) => {
      if (response.ok) {
        const cache = await caches.open(VERSION);
        await cache.put(request, response.clone());
      }
      return response;
    }).catch(() => null);
    return cached || (await network) || Response.error();
  })());
});

function parsePush(event) {
  if (!event.data) return {};
  try {
    return event.data.json() || {};
  } catch {
    try {
      return { body: event.data.text() };
    } catch {
      return {};
    }
  }
}

async function broadcast(payload) {
  const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  clients.forEach((client) => client.postMessage({ type: 'av:push', payload }));
}

self.addEventListener('push', (event) => {
  const data = parsePush(event);
  const title = data.title || 'Alpha Vault';
  const options = {
    body: data.body || '',
    icon: data.icon || '/assets/pwa/icon-192.png',
    badge: data.badge || '/assets/pwa/icon-192.png',
    tag: data.tag || 'av-push',
    renotify: true,
    data: { url: data.url || '/?qa=1' }
  };
  event.waitUntil(Promise.all([
    self.registration.showNotification(title, options),
    broadcast({ ...data, receivedAt: Date.now() })
  ]));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = new URL(
    (event.notification.data && event.notification.data.url) || '/?qa=1',
    self.location.origin
  ).href;

  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of clients) {
      if (client.url.startsWith(self.location.origin)) {
        await client.focus();
        if ('navigate' in client) {
          try { await client.navigate(target); } catch {}
        }
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});

self.addEventListener('message', (event) => {
  const message = event.data || {};
  if (message.type === 'av:skip-waiting') self.skipWaiting();
});
