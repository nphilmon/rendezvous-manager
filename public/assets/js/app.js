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

  // ---- Thème -----------------------------------------------------------
  function getTheme() { try { return localStorage.getItem('rvm_theme') || 'auto'; } catch (e) { return 'auto'; } }
  function setTheme(t) {
    try { localStorage.setItem('rvm_theme', t); } catch (e) {}
    if (t === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', t);
    var l = $('#theme-label'); if (l) l.textContent = { auto: 'auto', light: 'clair', dark: 'sombre' }[t];
    var s = $('#theme-select'); if (s) s.value = t;
  }
  setTheme(getTheme());
  var tt = $('#theme-toggle');
  if (tt) tt.addEventListener('click', function () {
    var order = ['auto', 'light', 'dark']; setTheme(order[(order.indexOf(getTheme()) + 1) % 3]);
  });
  var ts = $('#theme-select');
  if (ts) ts.addEventListener('change', function () { setTheme(ts.value); });

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
  if (st) {
    st.addEventListener('click', function () { sidebar(!sb.classList.contains('open')); });
    scrim.addEventListener('click', function () { sidebar(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && sb.classList.contains('open')) { sidebar(false); st.focus(); } });
  }

  // ---- État de connexion ---------------------------------------------------
  var sync = $('#sync-state'), syncLabel = $('#sync-label');
  function online() {
    if (!sync) return;
    sync.classList.toggle('offline', !navigator.onLine);
    if (syncLabel) syncLabel.textContent = navigator.onLine ? 'En ligne' : 'Hors ligne';
  }
  window.addEventListener('online', online); window.addEventListener('offline', online); online();

  // ---- Mini-calendrier -----------------------------------------------------
  var mc = $('#mini-cal');
  if (mc) {
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var iso = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
    var parse = function (s) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
    var selected = parse(mc.dataset.date), today = new Date();
    var cursor = new Date((selected || today).getFullYear(), (selected || today).getMonth(), 1);
    var view = mc.dataset.view || 'week';
    var monthFmt = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' });
    var dayFmt = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    var draw = function () {
      mc.textContent = '';
      var head = document.createElement('div'); head.className = 'mc-head';
      var mk = function (label, aria, delta) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'icon-btn'; b.setAttribute('aria-label', aria); b.textContent = label;
        b.addEventListener('click', function () { cursor = new Date(cursor.getFullYear(), cursor.getMonth() + delta, 1); draw(); mc.querySelector('.mc-head button' + (delta < 0 ? ':first-child' : ':last-child')).focus(); });
        return b;
      };
      var title = document.createElement('strong'); title.textContent = monthFmt.format(cursor); title.setAttribute('aria-live', 'polite');
      head.append(mk('‹', 'Mois précédent', -1), title, mk('›', 'Mois suivant', 1));
      mc.appendChild(head);
      var grid = document.createElement('div'); grid.className = 'mc-grid'; grid.setAttribute('role', 'grid');
      ['L', 'M', 'M', 'J', 'V', 'S', 'D'].forEach(function (d, i) {
        var c = document.createElement('span'); c.className = 'mc-dow'; c.textContent = d;
        c.setAttribute('aria-label', ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][i]); grid.appendChild(c);
      });
      var offset = (cursor.getDay() + 6) % 7, start = new Date(cursor.getFullYear(), cursor.getMonth(), 1 - offset);
      for (var i = 0; i < 42; i++) {
        var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        var b = document.createElement('button'); b.type = 'button'; b.className = 'mc-day';
        if (d.getMonth() !== cursor.getMonth()) b.classList.add('other');
        if (iso(d) === iso(today)) b.classList.add('today');
        if (selected && iso(d) === iso(selected)) { b.classList.add('selected'); b.setAttribute('aria-current', 'date'); }
        b.textContent = d.getDate(); b.setAttribute('aria-label', dayFmt.format(d)); b.dataset.date = iso(d);
        b.addEventListener('click', function (e) { location.href = '/calendar?view=' + encodeURIComponent(view) + '&date=' + e.currentTarget.dataset.date; });
        grid.appendChild(b);
      }
      mc.appendChild(grid);
    };
    draw();
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
