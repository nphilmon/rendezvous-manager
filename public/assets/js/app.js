/* RendezVous Manager – comportements communs de l'interface. */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };

  // ---- API (Fetch) avec jeton CSRF -------------------------------------
  var meta = $('meta[name="csrf-token"]');
  window.RVM = window.RVM || {};
  window.RVM.api = function (method, path, body) {
    var headers = { 'Accept': 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (meta && method !== 'GET') headers['X-CSRF-Token'] = meta.content;
    return fetch(path, { method: method, headers: headers, credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body) })
      .then(function (r) {
        return r.text().then(function (t) {
          var data = null; try { data = t ? JSON.parse(t) : null; } catch (e) {}
          if (r.status === 401 && !/^\/login/.test(location.pathname)) location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
          return { ok: r.ok, status: r.status, data: data, retryAfter: r.headers.get('Retry-After') };
        });
      });
  };

  // ---- Préférences d'affichage : source = serveur (meta ui-settings), partagées par tous les appareils ----------
  var serverUi = document.documentElement.getAttribute('data-theme-server') === '1';
  var ui = null; try { ui = JSON.parse(($('meta[name="ui-settings"]') || {}).content); } catch (e) { ui = null; }
  window.RVM.ui = ui;
  /** Enregistre un sous-ensemble de réglages sur le serveur et met à jour window.RVM.ui. */
  window.RVM.saveUi = function (patch) {
    Object.assign(window.RVM.ui || (window.RVM.ui = {}), patch);
    return window.RVM.api('PUT', '/api/v1/settings', { ui: patch }).then(function (r) {
      if (r.ok) { Object.assign(window.RVM.ui, r.data.ui, { stored: true }); document.dispatchEvent(new CustomEvent('rvm:ui')); }
      return r;
    });
  };
  // Reprise unique des anciens réglages du navigateur (phases 6 à 20) tant que le serveur n'en a aucun
  if (serverUi && ui && ui.stored === false) {
    var legacy = {}, ls = null;
    try { ls = JSON.parse(localStorage.getItem('rvm_cal_settings')); } catch (e) { ls = null; }
    if (ls && typeof ls === 'object') {
      var map = { startHour: 'start_hour', endHour: 'end_hour', step: 'step', weekStart: 'week_start', weekend: 'weekend', hour12: 'hour12' };
      Object.keys(map).forEach(function (k) { if (ls[k] !== undefined) legacy[map[k]] = ls[k]; });
    }
    try { var lt = localStorage.getItem('rvm_theme'); if (lt === 'light' || lt === 'dark') legacy.theme = lt; } catch (e) {}
    if (Object.keys(legacy).length) {
      Object.assign(ui, legacy);
      window.RVM.saveUi(legacy).then(function (r) {
        if (r.ok) { try { localStorage.removeItem('rvm_cal_settings'); } catch (e) {} }
        else Object.keys(legacy).forEach(function () { /* conservé dans le navigateur : nouvel essai à la prochaine page */ });
      });
    } else ui.stored = true;
  }

  // ---- Thème -----------------------------------------------------------
  function getTheme() {
    if (serverUi && ui) return ui.theme || 'auto';
    try { return localStorage.getItem('rvm_theme') || 'auto'; } catch (e) { return 'auto'; }
  }
  function setTheme(t, persist) {
    try { localStorage.setItem('rvm_theme', t); } catch (e) {}
    if (persist && serverUi && ui && ui.theme !== t) window.RVM.saveUi({ theme: t });
    if (t === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', t);
    var l = $('#theme-label'); if (l) l.textContent = { auto: 'auto', light: 'clair', dark: 'sombre' }[t];
    var s = $('#theme-select'); if (s) s.value = t;
  }
  setTheme(getTheme());
  var tt = $('#theme-toggle');
  if (tt) tt.addEventListener('click', function () {
    var order = ['auto', 'light', 'dark']; setTheme(order[(order.indexOf(getTheme()) + 1) % 3], true);
  });
  var ts = $('#theme-select');
  if (ts) ts.addEventListener('change', function () { setTheme(ts.value, true); });

  // ---- Menu utilisateur --------------------------------------------------
  var mb = $('#user-menu-btn'), menu = $('#user-menu');
  function closeMenu(focus) { if (!menu) return; menu.hidden = true; mb.setAttribute('aria-expanded', 'false'); if (focus) mb.focus(); }
  if (mb && menu) {
    mb.addEventListener('click', function () {
      var open = menu.hidden; menu.hidden = !open; mb.setAttribute('aria-expanded', String(open));
      if (open) { var f = menu.querySelector('a,button'); if (f) f.focus(); }
    });
    document.addEventListener('click', function (e) { if (!menu.hidden && !menu.contains(e.target) && e.target !== mb) closeMenu(false); });
    menu.addEventListener('keydown', function (e) {
      var items = Array.prototype.slice.call(menu.querySelectorAll('a,button')), i = items.indexOf(document.activeElement);
      if (e.key === 'Escape') { closeMenu(true); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); items[(i + 1) % items.length].focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); items[(i - 1 + items.length) % items.length].focus(); }
    });
  }

  // ---- Déconnexion -------------------------------------------------------
  var lo = $('#logout-btn');
  if (lo) lo.addEventListener('click', function () {
    lo.disabled = true;
    window.RVM.api('POST', '/api/v1/auth/logout', {}).then(function () { location.href = '/login'; });
  });

  // ---- Barre latérale mobile -----------------------------------------------
  var sb = $('#sidebar'), st = $('#sidebar-toggle'), scrim = $('#scrim');
  function sidebar(open) {
    if (!sb) return;
    sb.classList.toggle('open', open); scrim.hidden = !open; st.setAttribute('aria-expanded', String(open));
    st.setAttribute('aria-label', open ? 'Fermer le menu' : 'Ouvrir le menu');
  }
  window.RVM.sidebarClose = function () { sidebar(false); };
  if (st) {
    st.addEventListener('click', function () { sidebar(!sb.classList.contains('open')); });
    scrim.addEventListener('click', function () { sidebar(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sb.classList.contains('open')) { sidebar(false); st.focus(); } });
  }

  // ---- Mini-calendrier (fuseau de l'utilisateur, premier jour de semaine du calendrier) ----------------------
  var mc = $('#mini-cal');
  if (mc) {
    var tz = mc.dataset.tz || 'UTC';
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var iso = function (d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); };
    var parse = function (s) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : null; };
    var nowInTz = function () {
      var o = {}; new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).forEach(function (p) { o[p.type] = +p.value; });
      return new Date(Date.UTC(o.year, o.month - 1, o.day));
    };
    var weekStart = function () { return window.RVM.ui && window.RVM.ui.week_start === 0 ? 0 : 1; };
    var selected = parse(mc.dataset.date), today = nowInTz();
    var cursor = new Date(Date.UTC((selected || today).getUTCFullYear(), (selected || today).getUTCMonth(), 1));
    var view = mc.dataset.view || 'week';
    var fmt = function (o) { o.timeZone = 'UTC'; return new Intl.DateTimeFormat('fr-FR', o); };
    var monthFmt = fmt({ month: 'long', year: 'numeric' }), dayFmt = fmt({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    var DOW = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    var draw = function () {
      mc.textContent = '';
      var ws = weekStart();
      var head = document.createElement('div'); head.className = 'mc-head';
      var mk = function (label, aria, delta) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'icon-btn'; b.setAttribute('aria-label', aria); b.textContent = label;
        b.addEventListener('click', function () { cursor = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() + delta, 1)); draw(); mc.querySelector('.mc-head button' + (delta < 0 ? ':first-child' : ':last-child')).focus(); });
        return b;
      };
      var title = document.createElement('strong'); title.textContent = monthFmt.format(cursor); title.setAttribute('aria-live', 'polite');
      head.append(mk('‹', 'Mois précédent', -1), title, mk('›', 'Mois suivant', 1));
      mc.appendChild(head);
      var grid = document.createElement('div'); grid.className = 'mc-grid'; grid.setAttribute('role', 'grid');
      for (var k = 0; k < 7; k++) {
        var dw = (ws + k) % 7, c = document.createElement('span'); c.className = 'mc-dow'; c.textContent = DOW[dw].charAt(0).toUpperCase(); c.setAttribute('aria-label', DOW[dw]); grid.appendChild(c);
      }
      var offset = (cursor.getUTCDay() - ws + 7) % 7, start = Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth(), 1 - offset);
      for (var i = 0; i < 42; i++) {
        var d = new Date(start + i * 86400000);
        var b = document.createElement('button'); b.type = 'button'; b.className = 'mc-day';
        if (d.getUTCMonth() !== cursor.getUTCMonth()) b.classList.add('other');
        if (iso(d) === iso(today)) b.classList.add('today');
        if (selected && iso(d) === iso(selected)) { b.classList.add('selected'); b.setAttribute('aria-current', 'date'); }
        b.textContent = d.getUTCDate(); b.setAttribute('aria-label', dayFmt.format(d)); b.dataset.date = iso(d);
        b.addEventListener('click', function (e) {
          var date = e.currentTarget.dataset.date;
          if (window.RVM.calendarGo) { window.RVM.calendarGo(date); if (window.RVM.sidebarClose) window.RVM.sidebarClose(); }
          else location.href = '/calendar?view=' + encodeURIComponent(view) + '&date=' + date;
        });
        grid.appendChild(b);
      }
      mc.appendChild(grid);
    };
    draw();
    window.RVM.miniCal = {
      select: function (isoDate) {
        selected = parse(isoDate);
        if (selected) cursor = new Date(Date.UTC(selected.getUTCFullYear(), selected.getUTCMonth(), 1));
        draw();
      },
      redraw: draw
    };
  }

  // ---- Changement de mot de passe (paramètres) ------------------------------
  var pf = $('#password-form');
  if (pf) pf.addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = $('#password-msg'), cur = $('#current_password').value, n = $('#new_password').value, c = $('#confirm_password').value;
    var show = function (text, ok) { msg.hidden = false; msg.className = 'alert ' + (ok ? 'alert-ok' : 'alert-error'); msg.textContent = text; };
    if (!cur || !n) return show('Renseignez tous les champs.', false);
    if (n !== c) return show('La confirmation ne correspond pas.', false);
    window.RVM.api('POST', '/api/v1/auth/password', { current_password: cur, new_password: n }).then(function (r) {
      if (r.ok && /must_change=1/.test(location.search)) { location.href = '/'; return; }
      if (r.ok) { pf.reset(); return show('Mot de passe mis à jour. Les autres sessions ont été déconnectées.', true); }
      var d = r.data && r.data.error ? r.data.error : {}, extra = d.details && d.details.password ? ' ' + d.details.password.join(' ') : '';
      show((d.message || 'Échec de la mise à jour.') + extra, false);
    });
  });

  // ---- Page de connexion -------------------------------------------------------
  var lf = $('#login-form');
  if (lf) {
    var err = $('#login-error'), pw = $('#password'), tp = $('#toggle-password'), btn = $('#login-submit');
    tp.addEventListener('click', function () {
      var show = pw.type === 'password'; pw.type = show ? 'text' : 'password';
      tp.textContent = show ? 'Masquer' : 'Afficher'; tp.setAttribute('aria-pressed', String(show));
    });
    var safeNext = function (n) { return n && n.charAt(0) === '/' && n.charAt(1) !== '/' && n.indexOf('\\') < 0 ? n : '/'; };
    lf.addEventListener('submit', function (e) {
      e.preventDefault();
      err.hidden = true;
      var id = $('#identifier').value.trim(), p = pw.value;
      if (!id || !p) { err.textContent = 'Saisissez votre identifiant et votre mot de passe.'; err.hidden = false; return; }
      btn.disabled = true;
      window.RVM.api('POST', '/api/v1/auth/login', { identifier: id, password: p }).then(function (r) {
        if (r.ok) { location.href = safeNext(lf.dataset.next); return; }
        btn.disabled = false;
        var m = r.data && r.data.error ? r.data.error.message : 'Connexion impossible.';
        if (r.status === 429 && r.retryAfter) m += ' (environ ' + Math.ceil(+r.retryAfter / 60) + ' min)';
        err.textContent = m; err.hidden = false; pw.value = ''; pw.focus();
      }).catch(function () { btn.disabled = false; err.textContent = 'Serveur injoignable.'; err.hidden = false; });
    });
  }
})();
