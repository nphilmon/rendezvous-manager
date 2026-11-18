/* RendezVous Manager – abonnement Web Push de cet appareil (Push API, Service Worker, Notifications API). */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var panel = document.getElementById('push-panel');
    if (!panel || !window.RVM || !window.RVM.api) return;
    var $ = function (id) { return document.getElementById(id); }, api = window.RVM.api;
    var status = $('push-status'), bEnable = $('push-enable'), bDisable = $('push-disable'), bTest = $('push-test');
    var cfg = null;

    function el(t, c, x) { var n = document.createElement(t); if (c) n.className = c; if (x !== undefined) n.textContent = x; return n; }
    var sticky = false; // un message d'erreur reste affiché tant que l'utilisateur n'a pas réessayé
    function say(text, cls) { status.textContent = text; status.className = cls || 'muted'; }
    function info(text, cls) { if (!sticky) say(text, cls); }
    function b64(s) { var p = (s + '='.repeat((4 - s.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'), r = atob(p), o = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) o[i] = r.charCodeAt(i); return o; }
    var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    /** Un service push injoignable peut laisser subscribe() en suspens très longtemps : on abandonne au bout de 12 s. */
    function withTimeout(p, ms) { return Promise.race([p, new Promise(function (_, rej) { setTimeout(function () { rej({ name: 'Timeout' }); }, ms); })]); }

    function registration() {
      return navigator.serviceWorker.register('/service-worker.js', { scope: '/' }).then(function () { return navigator.serviceWorker.ready; });
    }

    function refresh() {
      bEnable.hidden = bDisable.hidden = bTest.hidden = true;
      if (!supported) { info('Ce navigateur ou cet appareil ne prend pas en charge les notifications push (sur iPhone et iPad, ajoutez d\'abord le site à l\'écran d\'accueil).', 'muted'); return list(); }
      if (!window.isSecureContext) { info('Les notifications push exigent une connexion HTTPS.', 'alert alert-error'); return list(); }
      if (!cfg || !cfg.enabled) { info('Les notifications push ne sont pas configurées sur ce serveur.', 'muted'); return list(); }
      if (Notification.permission === 'denied') { info('Les notifications sont bloquées pour ce site dans votre navigateur : autorisez-les dans ses réglages, puis rechargez la page.', 'alert alert-error'); return list(); }
      return navigator.serviceWorker.getRegistration('/').then(function (reg) { return reg ? reg.pushManager.getSubscription() : null; }).then(function (sub) {
        if (sub) { info('Les notifications push sont activées sur cet appareil.', 'alert alert-ok'); bDisable.hidden = false; bTest.hidden = false; }
        else { info(Notification.permission === 'granted' ? 'Autorisées dans le navigateur, mais cet appareil n\'est pas abonné.' : 'Non activées sur cet appareil.', 'muted'); bEnable.hidden = false; }
      }).catch(function () { info('État de l\'abonnement indisponible.', 'muted'); bEnable.hidden = false; }).then(list);
    }

    function list() {
      return Promise.all([api('GET', '/api/v1/push/subscriptions'), api('GET', '/api/v1/push/deliveries')]).then(function (res) {
        var d = $('push-devices'), h = $('push-history'); d.textContent = ''; h.textContent = '';
        var subs = res[0].ok ? res[0].data.data : [], hist = res[1].ok ? res[1].data.data : [];
        if (!subs.length) d.appendChild(el('p', 'muted small', 'Aucun appareil abonné.'));
        subs.forEach(function (s) {
          var row = el('div', 'dev'); row.appendChild(el('span', '', (s.user_agent ? s.user_agent.slice(0, 60) : 'Appareil') + ' — ' + s.service + (s.failed_count ? ' (' + s.failed_count + ' échec' + (s.failed_count > 1 ? 's' : '') + ')' : '')));
          d.appendChild(row);
        });
        if (!hist.length) h.appendChild(el('p', 'muted small', 'Aucun envoi pour l\'instant.'));
        hist.forEach(function (x) {
          var l = { sent: 'remis au service push', failed: 'échec', pending: 'en attente', cancelled: 'annulé', processing: 'en cours' }[x.status] || x.status;
          h.appendChild(el('p', 'small', (x.sent_at || x.scheduled_at).replace('T', ' ').slice(0, 16) + ' · ' + x.title + ' · ' + l + (x.error ? ' (' + x.error + ')' : '')));
        });
      });
    }

    bEnable.addEventListener('click', function () {
      bEnable.disabled = true; sticky = false; say('Demande de permission…');
      var perm = Notification.permission === 'granted' ? Promise.resolve('granted') : Promise.resolve(Notification.requestPermission());
      perm.then(function (p) {
        if (p !== 'granted') { sticky = true; say('Permission refusée : les notifications push restent désactivées.', 'alert alert-error'); return; }
        return registration().then(function (reg) {
          return withTimeout(reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(cfg.public_key) }), 12000);
        }).then(function (sub) {
          return api('POST', '/api/v1/push/subscribe', sub.toJSON()).then(function (r) {
            if (!r.ok) { return sub.unsubscribe().then(function () { sticky = true; say('Enregistrement refusé par le serveur : ' + ((r.data && r.data.error && r.data.error.message) || 'erreur') + '.', 'alert alert-error'); }); }
            window.RVM.appointments.toast('Notifications push activées sur cet appareil.');
          });
        });
      }).catch(function (e) {
        sticky = true;
        say('Impossible de s\'abonner' + (e && e.name ? ' (' + e.name + ')' : '') + ' : ' + (e && e.name === 'Timeout' ? 'le service push du navigateur ne répond pas' : 'le service push du navigateur est peut-être injoignable') + '. Réessayez plus tard.', 'alert alert-error');
      }).then(function () { bEnable.disabled = false; return refresh(); });
    });

    bDisable.addEventListener('click', function () {
      bDisable.disabled = true; sticky = false;
      navigator.serviceWorker.getRegistration('/').then(function (reg) { return reg ? reg.pushManager.getSubscription() : null; }).then(function (sub) {
        if (!sub) return;
        var ep = sub.endpoint;
        return sub.unsubscribe().then(function () { return api('POST', '/api/v1/push/unsubscribe', { endpoint: ep }); });
      }).catch(function () {}).then(function () { bDisable.disabled = false; window.RVM.appointments.toast('Notifications push désactivées sur cet appareil.'); return refresh(); });
    });

    bTest.addEventListener('click', function () {
      bTest.disabled = true;
      api('POST', '/api/v1/push/test').then(function (r) {
        var T = window.RVM.appointments.toast;
        if (!r.ok) { T((r.data && r.data.error && r.data.error.message) || 'Test impossible.', true); return; }
        var j = r.data;
        if (j.sent > 0) T('Notification envoyée à ' + j.sent + ' appareil' + (j.sent > 1 ? 's' : '') + ' (la réception dépend du navigateur et du système).');
        else T(j.total === 0 ? 'Aucun appareil abonné.' : 'Aucun appareil joignable : ' + (j.errors || []).join(' ; '), true);
      }).then(function () { bTest.disabled = false; return refresh(); });
    });

    var et = $('email-test');
    if (et) et.addEventListener('click', function () {
      et.disabled = true; var s = $('email-status'); s.textContent = 'Envoi…'; s.className = 'small';
      api('POST', '/api/v1/email/test').then(function (r) {
        s.textContent = r.ok ? 'E-mail de test envoyé à ' + r.data.to + '.' : ((r.data && r.data.error && r.data.error.message) || 'Envoi impossible.');
        s.className = 'small ' + (r.ok ? 'ok' : 'bad'); et.disabled = false;
      });
    });

    api('GET', '/api/v1/push/config').then(function (r) { cfg = r.ok ? r.data : null; return refresh(); });
  });
})();
