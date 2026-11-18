/* RendezVous Manager – synchronisation multi-utilisateurs.
   Interrogation périodique d'un curseur serveur (GET /api/v1/sync/changes) : le serveur ne renvoie que la nature des changements
   visibles par l'utilisateur ; chaque page recharge alors ses propres données. Pas de polling en arrière-plan, repli exponentiel
   en cas d'erreur, actualisation manuelle depuis l'indicateur de la barre supérieure. */
(function () {
  'use strict';

  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ''; };
  var btn = document.getElementById('sync-state'), label = document.getElementById('sync-label'), live = document.getElementById('sync-live');
  if (!btn || !window.RVM || !window.RVM.api) return;

  var cursor = parseInt(meta('sync-cursor'), 10) || 0, access = meta('sync-access'), interval = Math.max(2, parseInt(meta('sync-interval'), 10) || 30) * 1000;
  var timer = null, running = false, fails = 0, lastOk = null, state = '';
  var LABELS = { ok: 'À jour', busy: 'Synchronisation…', offline: 'Hors ligne', error: 'Erreur de synchronisation' };

  function setState(s) {
    if (s === state) return;
    var before = state; state = s;
    btn.classList.remove('is-ok', 'is-busy', 'is-offline', 'is-error'); btn.classList.add('is-' + s);
    if (label) label.textContent = LABELS[s];
    if (live && ((s === 'offline' || s === 'error') || (s === 'ok' && (before === 'offline' || before === 'error')))) live.textContent = s === 'ok' ? 'Synchronisation rétablie.' : LABELS[s] + '.';
  }
  function stamp() {
    var t = lastOk ? ' Dernière synchronisation : ' + new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(lastOk) + '.' : '';
    btn.title = 'Actualiser maintenant.' + t;
  }

  function schedule(ms) {
    clearTimeout(timer); timer = null;
    if (document.hidden) return; // en arrière-plan : aucune requête
    var delay = ms !== undefined ? ms : Math.min(300000, interval * Math.pow(2, fails));
    timer = setTimeout(function () { poll(false); }, delay + Math.random() * delay * 0.1);
  }

  /** manual : action de l'utilisateur ; depth : nombre de pages enchaînées (has_more). */
  function poll(manual, depth) {
    depth = depth || 0;
    if (running) return Promise.resolve();
    if (!navigator.onLine) { setState('offline'); schedule(); return Promise.resolve(); }
    running = true; if (manual || state !== 'ok') setState('busy');
    return window.RVM.api('GET', '/api/v1/sync/changes?cursor=' + cursor).then(function (r) {
      running = false;
      if (!r.ok || !r.data) { fails++; setState('error'); schedule(); return; }
      var j = r.data, changes = j.changes || [], accessChanged = !!access && j.access !== access;
      cursor = j.cursor; access = j.access; fails = 0; lastOk = new Date(); stamp(); setState('ok');
      if (j.poll_interval) interval = Math.max(2, j.poll_interval) * 1000;
      if (changes.length || j.reset || accessChanged) {
        document.dispatchEvent(new CustomEvent('rvm:sync', { detail: { changes: changes, reset: !!j.reset, accessChanged: accessChanged } }));
      }
      if (j.has_more && depth < 20) return poll(false, depth + 1);
      schedule();
    }).catch(function () { running = false; fails++; setState(navigator.onLine ? 'error' : 'offline'); schedule(); });
  }

  btn.addEventListener('click', function () { clearTimeout(timer); poll(true); });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { clearTimeout(timer); return; }
    if (!lastOk || Date.now() - lastOk.getTime() > 5000) poll(false); else schedule();
  });
  window.addEventListener('online', function () { fails = 0; poll(false); });
  window.addEventListener('offline', function () { clearTimeout(timer); setState('offline'); });
  // une action de l'utilisateur ne change pas le curseur côté client : le prochain cycle la verra (et les autres onglets aussi)

  /** Pastille de la cloche : nombre de notifications non lues (créée, mise à jour ou retirée). */
  window.RVM.setBadge = function (n) {
    var bell = document.getElementById('bell'); if (!bell) return;
    var b = bell.querySelector('.badge');
    if (n > 0) { if (!b) { b = document.createElement('span'); b.className = 'badge'; bell.appendChild(b); } b.textContent = String(n); bell.setAttribute('aria-label', 'Notifications (' + n + ' non lues)'); }
    else { if (b) b.remove(); bell.setAttribute('aria-label', 'Notifications'); }
  };
  document.addEventListener('rvm:sync', function (e) {
    if (e.detail.reset || e.detail.changes.some(function (c) { return c.entity === 'notification'; })) {
      window.RVM.api('GET', '/api/v1/notifications/count').then(function (r) { if (r.ok) window.RVM.setBadge(r.data.unread); });
    }
  });

  /** Aides pour les pages : le changement concerne-t-il une entité (ou un rechargement complet est-il demandé) ? */
  window.RVM.sync = {
    poll: function () { return poll(true); },
    state: function () { return state; },
    touches: function (d, entities) { return !!(d.reset || d.accessChanged || d.changes.some(function (c) { return entities.indexOf(c.entity) >= 0; })); }
  };

  // Bannière sur les pages non dynamiques (tableau de bord)
  var banner = document.querySelector('[data-sync-banner]');
  if (banner) document.addEventListener('rvm:sync', function (e) {
    if (!window.RVM.sync.touches(e.detail, ['appointment', 'calendar']) || !banner.hidden) return;
    banner.hidden = false; banner.className = 'notice sync-banner'; banner.setAttribute('role', 'status');
    banner.appendChild(document.createTextNode('Des modifications ont été effectuées par d\'autres utilisateurs. '));
    var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = 'Actualiser la page'; b.addEventListener('click', function () { location.reload(); });
    banner.appendChild(b);
  });

  setState(navigator.onLine ? 'ok' : 'offline'); lastOk = new Date(); stamp(); schedule();
})();
