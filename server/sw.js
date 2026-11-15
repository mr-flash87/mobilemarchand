/* Service worker : rend l'admin installable et affiche les notifications de paiement. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
// Pas de mise en cache : les données de paiement doivent toujours être fraîches.
self.addEventListener('fetch', () => {});

self.addEventListener('push', (event) => {
  let d = {};
  try { d = event.data ? event.data.json() : {}; } catch (e) { d = { title: 'Notification', body: event.data ? event.data.text() : '' }; }
  const base = self.registration.scope;
  event.waitUntil(self.registration.showNotification(d.title || 'Notification', {
    body: d.body || '',
    tag: d.tag || undefined,
    renotify: !!d.tag,
    icon: base + 'assets/icon-192.png',
    badge: base + 'assets/badge-96.png',
    vibrate: [120, 60, 120],
    data: { url: d.url || base + 'admin' },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = event.notification.data && event.notification.data.url;
  event.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const c of all) {
      if (c.url.startsWith(self.registration.scope) && 'focus' in c) {
        await c.focus();
        if (url && 'navigate' in c) { try { await c.navigate(url); } catch (e) {} }
        return;
      }
    }
    if (url) await self.clients.openWindow(url);
  })());
});
