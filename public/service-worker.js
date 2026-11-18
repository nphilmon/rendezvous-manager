/* RendezVous Manager – service worker : réception des notifications Web Push (aucune mise en cache hors ligne). */
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

/** Seuls les chemins du même site sont ouverts (jamais d'URL arbitraire venue de la charge utile). */
function safePath(u) { return typeof u === 'string' && u.charAt(0) === '/' && u.charAt(1) !== '/' && u.indexOf('\\') < 0 ? u : '/notifications'; }

self.addEventListener('push', function (event) {
  var data = {};
  try { data = event.data ? event.data.json() : {}; } catch (e) { data = { body: event.data ? event.data.text() : '' }; }
  var title = typeof data.title === 'string' && data.title ? data.title.slice(0, 200) : 'RendezVous Manager';
  event.waitUntil(self.registration.showNotification(title, {
    body: typeof data.body === 'string' ? data.body.slice(0, 500) : '',
    icon: '/assets/icons/icon-192.png', badge: '/assets/icons/badge-72.png',
    tag: typeof data.tag === 'string' ? data.tag.slice(0, 100) : undefined, data: { url: safePath(data.url) }
  }));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = new URL(safePath(event.notification.data && event.notification.data.url), self.location.origin).href;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c.url.indexOf(self.location.origin) === 0 && 'focus' in c) {
        return (c.navigate ? c.navigate(url) : Promise.resolve()).then(function () { return c.focus(); });
      }
    }
    return self.clients.openWindow(url);
  }));
});

function b64ToBytes(s) {
  var p = (s + '='.repeat((4 - s.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'), r = atob(p), o = new Uint8Array(r.length);
  for (var i = 0; i < r.length; i++) o[i] = r.charCodeAt(i);
  return o;
}

/** Le service push a renouvelé l'abonnement : on s'abonne de nouveau et on prévient le serveur. */
self.addEventListener('pushsubscriptionchange', function (event) {
  event.waitUntil((async function () {
    var cfg = await (await fetch('/api/v1/push/config', { credentials: 'same-origin' })).json();
    if (!cfg.enabled) return;
    var sub = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(cfg.public_key) });
    var me = await (await fetch('/api/v1/auth/me', { credentials: 'same-origin' })).json();
    await fetch('/api/v1/push/subscribe', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf_token }, body: JSON.stringify(sub.toJSON()) });
  })().catch(function () {}));
});
