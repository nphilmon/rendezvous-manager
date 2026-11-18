/* RendezVous Manager – calendrier en grille (jour, semaine, mois, planning).
   Implémentation native, sans dépendance. Toute valeur affichée est insérée via textContent. */
(function () {
  'use strict';

  var root = document.getElementById('calendar-root');
  var nav = document.getElementById('cal-nav');
  if (!root || !nav) return;

  var TZ = root.dataset.tz || 'UTC';
  var DAY = 86400000;
  var VIEWS = { day: 'Jour', week: 'Semaine', month: 'Mois', agenda: 'Planning' };
  var STATUS = { scheduled: 'Planifié', confirmed: 'Confirmé', tentative: 'Provisoire', cancelled: 'Annulé', completed: 'Terminé' };
  var PRIORITY = { low: 'Basse', normal: 'Normale', high: 'Haute', urgent: 'Urgente' };
  var DEFAULTS = { startHour: 0, endHour: 24, step: 30, weekStart: 1, weekend: true, hour12: false };

  // ---- Réglages d'affichage (navigateur ; l'enregistrement serveur viendra avec la phase 21) -------------
  function load(key, fallback) { try { var v = JSON.parse(localStorage.getItem(key)); return v === null ? fallback : v; } catch (e) { return fallback; } }
  function save(key, v) { try { localStorage.setItem(key, JSON.stringify(v)); } catch (e) {} }
  var settings = Object.assign({}, DEFAULTS, load('rvm_cal_settings', {}));
  function sanitize() {
    settings.startHour = Math.min(23, Math.max(0, parseInt(settings.startHour, 10) || 0));
    settings.endHour = Math.min(24, Math.max(settings.startHour + 1, parseInt(settings.endHour, 10) || 24));
    settings.step = [5, 10, 15, 30, 60].indexOf(+settings.step) >= 0 ? +settings.step : 30;
    settings.weekStart = +settings.weekStart === 0 ? 0 : 1;
    settings.weekend = settings.weekend !== false;
    settings.hour12 = settings.hour12 === true;
  }
  sanitize();
  var hidden = load('rvm_cal_hidden', []); // calendriers masqués

  // ---- Fuseau horaire : "heure murale" = ms UTC dont les composantes UTC sont l'heure locale du fuseau ----------
  var partsFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  function wall(ms) {
    var o = {}; partsFmt.formatToParts(new Date(ms)).forEach(function (p) { o[p.type] = +p.value; });
    return Date.UTC(o.year, o.month - 1, o.day, o.hour, o.minute, o.second);
  }
  function unwall(w) { var g = w; for (var i = 0; i < 3; i++) g = w - (wall(g) - g); return g; }
  function dayStart(w) { return Math.floor(w / DAY) * DAY; }
  function isoDate(w) { return new Date(w).toISOString().slice(0, 10); }
  function parseIso(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : null; }
  function today() { return dayStart(wall(Date.now())); }
  function weekStartOf(w) { return w - ((new Date(w).getUTCDay() - settings.weekStart + 7) % 7) * DAY; }
  function monthStart(w) { var d = new Date(w); return Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), 1); }

  var F = function (o) { o.timeZone = 'UTC'; return new Intl.DateTimeFormat('fr-FR', o); };
  function fmtTime(w) { return F({ hour: settings.hour12 ? 'numeric' : '2-digit', minute: '2-digit', hour12: settings.hour12 }).format(w); }
  function fmtDay(w, o) { return F(o).format(w); }

  // ---- État ---------------------------------------------------------------------------------------------------
  var state = { view: VIEWS[nav.dataset.view] ? nav.dataset.view : 'week', date: parseIso(nav.dataset.date) || today() };
  var calendars = [], events = [], loadId = 0, ctrl = null;

  function range() {
    var d = state.date, s, e;
    if (state.view === 'day') { s = d; e = d + DAY; }
    else if (state.view === 'week') { s = weekStartOf(d); e = s + 7 * DAY; }
    else if (state.view === 'month') {
      var first = monthStart(d), dim = new Date(Date.UTC(new Date(d).getUTCFullYear(), new Date(d).getUTCMonth() + 1, 0)).getUTCDate();
      s = weekStartOf(first); var weeks = Math.ceil(((first - s) / DAY + dim) / 7); e = s + weeks * 7 * DAY;
    } else { s = d; e = d + 30 * DAY; }
    return { s: s, e: e };
  }

  // ---- Navigation ---------------------------------------------------------------------------------------------
  function url() { return '/calendar?view=' + state.view + '&date=' + isoDate(state.date); }
  function go(view, date, push) {
    state.view = view || state.view; state.date = date === undefined ? state.date : date;
    if (push !== false) history.pushState({}, '', url());
    refresh();
  }
  function shift(dir) {
    var d = state.date;
    if (state.view === 'day') return d + dir * DAY;
    if (state.view === 'month') { var x = new Date(d); return Date.UTC(x.getUTCFullYear(), x.getUTCMonth() + dir, 1); }
    return d + dir * (state.view === 'agenda' ? 30 : 7) * DAY;
  }
  document.getElementById('cal-today').addEventListener('click', function () { go(null, today()); });
  document.getElementById('cal-prev').addEventListener('click', function () { go(null, shift(-1)); });
  document.getElementById('cal-next').addEventListener('click', function () { go(null, shift(1)); });
  document.querySelectorAll('.view-switch a').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var m = /view=(\w+)/.exec(a.getAttribute('href'));
      if (!m || !VIEWS[m[1]] || e.ctrlKey || e.metaKey || e.shiftKey) return;
      e.preventDefault(); go(m[1], state.date);
    });
  });
  window.addEventListener('popstate', function () {
    var q = new URLSearchParams(location.search), v = q.get('view');
    state.view = VIEWS[v] ? v : 'week'; state.date = parseIso(q.get('date')) || today(); refresh();
  });
  window.RVM = window.RVM || {};
  window.RVM.calendarGo = function (iso) { go(null, parseIso(iso)); };
  document.addEventListener('keydown', function (e) {
    var t = e.target, tag = t && t.tagName;
    if (e.ctrlKey || e.metaKey || e.altKey || tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || document.querySelector('dialog[open]')) return;
    var map = { t: function () { go(null, today()); }, j: function () { go(null, shift(1)); }, k: function () { go(null, shift(-1)); },
      d: function () { go('day'); }, w: function () { go('week'); }, m: function () { go('month'); }, a: function () { go('agenda'); } };
    if (map[e.key]) { e.preventDefault(); map[e.key](); }
  });

  function updateChrome() {
    var r = range(), d = state.date, label;
    if (state.view === 'day') label = fmtDay(d, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    else if (state.view === 'month') label = fmtDay(d, { month: 'long', year: 'numeric' });
    else if (state.view === 'agenda') label = 'À partir du ' + fmtDay(d, { day: 'numeric', month: 'long', year: 'numeric' });
    else label = fmtDay(r.s, { day: 'numeric', month: 'short' }) + ' – ' + fmtDay(r.e - DAY, { day: 'numeric', month: 'short', year: 'numeric' });
    document.getElementById('cal-period').textContent = label;
    document.title = label + ' – RendezVous Manager';
    document.querySelectorAll('.view-switch a').forEach(function (a) {
      var m = /view=(\w+)/.exec(a.getAttribute('href')), v = m && m[1];
      a.setAttribute('href', '/calendar?view=' + v + '&date=' + isoDate(state.date));
      a.classList.toggle('active', v === state.view);
      if (v === state.view) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    root.dataset.view = state.view;
    var title = document.getElementById('cal-h1'); if (title) title.textContent = 'Calendrier – vue ' + VIEWS[state.view];
    if (window.RVM.miniCal) window.RVM.miniCal.select(isoDate(state.date));
  }

  // ---- Données ------------------------------------------------------------------------------------------------
  function calById(id) { for (var i = 0; i < calendars.length; i++) if (calendars[i].id === id) return calendars[i]; return null; }
  function status(msg, isError) {
    var s = document.getElementById('cal-status'); s.textContent = msg || ''; s.classList.toggle('error', !!isError);
  }
  function refresh() {
    updateChrome();
    var r = range(), id = ++loadId;
    if (ctrl) ctrl.abort();
    ctrl = window.AbortController ? new AbortController() : null;
    status('Chargement…');
    var q = '/api/v1/appointments?start=' + encodeURIComponent(new Date(unwall(r.s)).toISOString()) + '&end=' + encodeURIComponent(new Date(unwall(r.e)).toISOString());
    fetch(q, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: ctrl ? ctrl.signal : undefined })
      .then(function (res) {
        if (res.status === 401) { location.href = '/login?next=' + encodeURIComponent(url()); return null; }
        return res.json().then(function (j) { return { ok: res.ok, j: j }; });
      })
      .then(function (r2) {
        if (!r2 || id !== loadId) return;
        if (!r2.ok) { status((r2.j && r2.j.error && r2.j.error.message) || 'Chargement impossible.', true); return; }
        events = r2.j.data.map(normalize);
        status(r2.j.meta.truncated ? 'Trop de rendez-vous : affichage limité aux ' + r2.j.meta.count + ' premiers.' : '', r2.j.meta.truncated);
        render();
      })
      .catch(function (e) { if (e && e.name === 'AbortError') return; if (id === loadId) status('Serveur injoignable.', true); });
  }

  // Rendez-vous « journée entière » : dates flottantes (minuit UTC, fin exclusive) ; autres : instants convertis en heure murale.
  function normalize(a) {
    var s, e;
    if (a.all_day) { s = parseIso(a.start); e = Math.max(parseIso(a.end), s + DAY); }
    else { s = wall(Date.parse(a.start)); e = Math.max(wall(Date.parse(a.end)), s); }
    var cal = calById(a.calendar_id);
    return { raw: a, s: s, e: e, allDay: !!a.all_day, id: a.id, calendar: cal,
      color: a.color || (a.category && a.category.color) || (cal && cal.color) || '#2563eb' };
  }

  function visibleEvents() { return events.filter(function (ev) { return hidden.indexOf(ev.raw.calendar_id) < 0; }); }
  function dayList(r) {
    var days = [];
    for (var d = r.s; d < r.e; d += DAY) {
      var wd = new Date(d).getUTCDay();
      if (!settings.weekend && (wd === 0 || wd === 6) && state.view !== 'day') continue;
      days.push(d);
    }
    return days;
  }

  // ---- Construction DOM -----------------------------------------------------------------------------------------
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function setColor(n, c) { n.style.setProperty('--c', /^#[0-9a-fA-F]{3,8}$/.test(c) ? c : '#2563eb'); }
  function ppm() { return Math.max(0.8, 20 / settings.step); }

  function timeRange(ev) {
    if (ev.allDay) return 'Journée entière';
    var sameDay = dayStart(ev.s) === dayStart(Math.max(ev.s, ev.e - 1));
    return fmtTime(ev.s) + ' – ' + (sameDay ? '' : fmtDay(ev.e, { day: 'numeric', month: 'short' }) + ' ') + fmtTime(ev.e);
  }
  function label(ev) {
    return ev.raw.title + ', ' + fmtDay(ev.s, { weekday: 'long', day: 'numeric', month: 'long' }) + ', ' + timeRange(ev) +
      (ev.calendar ? ', ' + ev.calendar.name : '') + (ev.raw.status === 'cancelled' ? ', annulé' : '');
  }
  function eventButton(ev, cls) {
    var b = el('button', 'ev ' + cls + (ev.raw.status === 'cancelled' ? ' cancelled' : '') + (ev.raw.status === 'tentative' ? ' tentative' : ''));
    b.type = 'button'; setColor(b, ev.color); b.setAttribute('aria-label', label(ev));
    b.addEventListener('click', function () { openDialog(ev, b); });
    return b;
  }

  var scrollTop = null;
  function render() {
    var box = document.getElementById('cal-grid'), prev = box.querySelector('.tg-scroll');
    if (prev) scrollTop = prev.scrollTop;
    box.textContent = '';
    var vis = visibleEvents(), r = range(), hiddenCount = 0;
    if (state.view === 'month') hiddenCount = renderMonth(box, vis, r);
    else if (state.view === 'agenda') renderAgenda(box, vis, r);
    else hiddenCount = renderTimeGrid(box, vis, r);
    var h = document.getElementById('cal-hidden');
    h.textContent = hiddenCount > 0 ? hiddenCount + ' rendez-vous non affiché' + (hiddenCount > 1 ? 's' : '') + ' (hors plage horaire ou week-end masqué : voir Réglages).' : '';
  }

  function segmentsFor(ev, d) {
    var a = Math.max(ev.s, d), b = Math.min(ev.e, d + DAY);
    if (b > a) return [a, b];
    if (ev.s === ev.e && ev.s >= d && ev.s < d + DAY) return [ev.s, ev.s];
    return null;
  }

  function renderTimeGrid(box, vis, r) {
    var days = dayList(r), n = days.length, p = ppm(), hours = settings.endHour - settings.startHour, height = hours * 60 * p;
    var scroll = el('div', 'tg-scroll'); scroll.tabIndex = -1;
    var tg = el('div', 'tg'); tg.style.setProperty('--cols', n);
    // En-tête + journée entière (collant)
    var top = el('div', 'tg-top'), head = el('div', 'tg-row tg-head'), allday = el('div', 'tg-row tg-allday');
    head.appendChild(el('div', 'tg-gutter')); var ad = el('div', 'tg-gutter tg-allday-label', 'Journée'); allday.appendChild(ad);
    var t0 = today(), hiddenCount = 0, shownIds = {};
    days.forEach(function (d) {
      var a = el('a', 'day-head' + (d === t0 ? ' today' : ''));
      a.href = '/calendar?view=day&date=' + isoDate(d);
      a.appendChild(el('span', 'dow', fmtDay(d, { weekday: 'short' })));
      a.appendChild(el('span', 'dnum', fmtDay(d, { day: 'numeric' })));
      a.setAttribute('aria-label', fmtDay(d, { weekday: 'long', day: 'numeric', month: 'long' }));
      a.addEventListener('click', function (e) { if (state.view !== 'day' && !e.ctrlKey && !e.metaKey) { e.preventDefault(); go('day', d); } });
      head.appendChild(a);
      var cell = el('div', 'allday-cell' + (d === t0 ? ' today' : ''));
      vis.filter(function (ev) { return ev.allDay && ev.s < d + DAY && ev.e > d; }).forEach(function (ev) {
        shownIds[ev.id] = 1; cell.appendChild(eventButton(ev, 'chip')).appendChild(el('span', 't', ev.raw.title));
      });
      allday.appendChild(cell);
    });
    top.appendChild(head); top.appendChild(allday); tg.appendChild(top);
    // Corps horaire
    var body = el('div', 'tg-row tg-body'), gut = el('div', 'tg-gutter tg-hours'); gut.style.height = height + 'px';
    for (var h = settings.startHour; h < settings.endHour; h++) {
      var lab = el('span', 'hour', h === settings.startHour ? '' : fmtTime(Date.UTC(2000, 0, 1, h)));
      lab.style.top = ((h - settings.startHour) * 60 * p) + 'px'; gut.appendChild(lab);
    }
    body.appendChild(gut);
    days.forEach(function (d) {
      var col = el('div', 'tg-col' + (d === t0 ? ' today' : '')); col.style.height = height + 'px';
      col.style.setProperty('--slot', (settings.step * p) + 'px'); col.style.setProperty('--hour', (60 * p) + 'px');
      var items = [];
      vis.filter(function (ev) { return !ev.allDay; }).forEach(function (ev) {
        var seg = segmentsFor(ev, d); if (!seg) return;
        var s = (seg[0] - d) / 60000, e = (seg[1] - d) / 60000, lo = settings.startHour * 60, hi = settings.endHour * 60;
        if (e < lo || s >= hi || (e === s && (s < lo || s >= hi))) return; // hors plage
        items.push({ ev: ev, s: Math.max(s, lo), e: Math.min(Math.max(e, s + 15), hi) });
        shownIds[ev.id] = 1;
      });
      layoutColumns(items);
      items.forEach(function (it) {
        var b = eventButton(it.ev, 'blk'), lo = settings.startHour * 60;
        b.style.top = ((it.s - lo) * p) + 'px'; b.style.height = Math.max(18, (it.e - it.s) * p - 1) + 'px';
        b.style.left = (it.col / it.cols * 100) + '%'; b.style.width = ((1 / it.cols) * 100) + '%';
        b.appendChild(el('span', 'tt', it.ev.raw.title));
        b.appendChild(el('span', 'tm', fmtTime(it.ev.s) + (it.ev.e > it.ev.s ? ' – ' + fmtTime(it.ev.e) : '')));
        col.appendChild(b);
      });
      if (d === t0) { var nl = el('div', 'now-line'); nl.dataset.base = String(d); col.appendChild(nl); positionNow(nl); }
      body.appendChild(col);
    });
    tg.appendChild(body); scroll.appendChild(tg); box.appendChild(scroll);
    vis.forEach(function (ev) { if (!shownIds[ev.id] && range().s < ev.e && ev.s < range().e) hiddenCount++; });
    // Position de défilement : conserver, sinon 8 h ou l'heure courante
    var firstHour = Math.max(settings.startHour, 8);
    if (days.indexOf(t0) >= 0) { var nowMin = (wall(Date.now()) - t0) / 60000; firstHour = Math.max(settings.startHour, Math.floor(nowMin / 60) - 1); }
    scroll.scrollTop = scrollTop !== null && box.dataset.keep === '1' ? scrollTop : (firstHour - settings.startHour) * 60 * p;
    box.dataset.keep = '1';
    return hiddenCount;
  }

  function positionNow(nl) {
    var min = (wall(Date.now()) - +nl.dataset.base) / 60000, lo = settings.startHour * 60;
    nl.hidden = min < lo || min > settings.endHour * 60; nl.style.top = ((min - lo) * ppm()) + 'px';
  }
  setInterval(function () { document.querySelectorAll('.now-line').forEach(positionNow); }, 60000);

  /** Répartition en colonnes des rendez-vous qui se chevauchent (regroupés par amas). */
  function layoutColumns(items) {
    items.sort(function (a, b) { return a.s - b.s || b.e - a.e; });
    var cluster = [], end = -1;
    function flush() {
      var cols = [];
      cluster.forEach(function (it) {
        var i = 0; while (i < cols.length && cols[i] > it.s) i++;
        it.col = i; cols[i] = it.e;
      });
      cluster.forEach(function (it) { it.cols = cols.length; });
      cluster = []; end = -1;
    }
    items.forEach(function (it) { if (cluster.length && it.s >= end) flush(); cluster.push(it); end = Math.max(end, it.e); });
    if (cluster.length) flush();
  }

  function renderMonth(box, vis, r) {
    var days = dayList({ s: r.s, e: r.e }), cols = settings.weekend ? 7 : 5, first = monthStart(state.date), month = new Date(first).getUTCMonth();
    var wrap = el('div', 'tg-scroll month'), grid = el('div', 'mg'); grid.style.setProperty('--cols', cols);
    for (var i = 0; i < cols; i++) {
      var d0 = days[i]; grid.appendChild(el('div', 'mg-dow', fmtDay(d0, { weekday: 'short' })));
    }
    var t0 = today(), shown = {}, hiddenCount = 0, MAX = 3;
    days.forEach(function (d) {
      var cell = el('div', 'mg-cell' + (new Date(d).getUTCMonth() !== month ? ' other' : '') + (d === t0 ? ' today' : ''));
      var num = el('a', 'mg-num', fmtDay(d, { day: 'numeric' }) + (new Date(d).getUTCDate() === 1 ? ' ' + fmtDay(d, { month: 'short' }) : ''));
      num.href = '/calendar?view=day&date=' + isoDate(d); num.setAttribute('aria-label', fmtDay(d, { weekday: 'long', day: 'numeric', month: 'long' }));
      num.addEventListener('click', function (e) { if (!e.ctrlKey && !e.metaKey) { e.preventDefault(); go('day', d); } });
      cell.appendChild(num);
      var list = vis.filter(function (ev) { return ev.s < d + DAY && (ev.e > d || (ev.s === ev.e && ev.s >= d)); })
        .sort(function (a, b) { return (b.allDay - a.allDay) || (a.s - b.s); });
      list.slice(0, MAX).forEach(function (ev) {
        shown[ev.id] = 1;
        var b = eventButton(ev, ev.allDay ? 'chip' : 'line');
        if (!ev.allDay) b.appendChild(el('span', 'dot'));
        if (!ev.allDay) b.appendChild(el('span', 'tm', fmtTime(ev.s)));
        b.appendChild(el('span', 'tt', ev.raw.title)); cell.appendChild(b);
      });
      if (list.length > MAX) {
        var more = el('a', 'more', '+ ' + (list.length - MAX) + ' autre' + (list.length - MAX > 1 ? 's' : ''));
        more.href = '/calendar?view=day&date=' + isoDate(d); more.addEventListener('click', function (e) { e.preventDefault(); go('day', d); });
        list.slice(MAX).forEach(function (ev) { shown[ev.id] = 1; }); cell.appendChild(more);
      }
      grid.appendChild(cell);
    });
    vis.forEach(function (ev) { if (!shown[ev.id]) hiddenCount++; });
    wrap.appendChild(grid); box.appendChild(wrap); return hiddenCount;
  }

  function renderAgenda(box, vis, r) {
    var wrap = el('div', 'tg-scroll agenda'), any = false;
    for (var d = r.s; d < r.e; d += DAY) {
      var list = vis.filter(function (ev) { return ev.s < d + DAY && (ev.e > d || (ev.s === ev.e && ev.s >= d)); })
        .sort(function (a, b) { return (b.allDay - a.allDay) || (a.s - b.s); });
      if (!list.length) continue; any = true;
      var sec = el('section', 'ag-day'), h = el('h3', d === today() ? 'today' : '', fmtDay(d, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
      sec.appendChild(h);
      list.forEach(function (ev) {
        var row = eventButton(ev, 'row'); row.appendChild(el('span', 'dot'));
        row.appendChild(el('span', 'tm', ev.allDay ? 'Journée entière' : fmtTime(Math.max(ev.s, d)) + (ev.e > ev.s ? ' – ' + fmtTime(Math.min(ev.e, d + DAY)) : '')));
        var main = el('span', 'main'); main.appendChild(el('span', 'tt', ev.raw.title));
        var sub = [ev.calendar && ev.calendar.name, ev.raw.location].filter(Boolean).join(' · ');
        if (sub) main.appendChild(el('span', 'sub', sub)); row.appendChild(main); sec.appendChild(row);
      });
      wrap.appendChild(sec);
    }
    if (!any) wrap.appendChild(el('p', 'empty', 'Aucun rendez-vous sur cette période.'));
    box.appendChild(wrap);
  }

  // ---- Détails d'un rendez-vous (lecture seule ; la modification arrive en phase 7) -------------------------------------
  var dlg = document.createElement('dialog'); dlg.className = 'ev-dialog'; dlg.setAttribute('aria-labelledby', 'ev-title');
  document.body.appendChild(dlg);
  var opener = null;
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  dlg.addEventListener('close', function () { if (opener) opener.focus(); });
  function openDialog(ev, from) {
    opener = from; dlg.textContent = '';
    var a = ev.raw, head = el('div', 'dlg-head'), bar = el('span', 'bar'); setColor(bar, ev.color);
    var h = el('h2', '', a.title); h.id = 'ev-title';
    var x = el('button', 'icon-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); x.addEventListener('click', function () { dlg.close(); });
    head.append(bar, h, x); dlg.appendChild(head);
    var dl = el('dl', 'kv');
    function row(k, v) { if (v === null || v === undefined || v === '') return; dl.appendChild(el('dt', '', k)); dl.appendChild(el('dd', '', v)); }
    row('Date', ev.allDay ? fmtDay(ev.s, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + (ev.e - ev.s > DAY ? ' → ' + fmtDay(ev.e - DAY, { day: 'numeric', month: 'long' }) : '')
      : fmtDay(ev.s, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }));
    row('Horaire', timeRange(ev) + (ev.allDay ? '' : ' (' + TZ + ')'));
    row('Statut', STATUS[a.status] || a.status); row('Priorité', PRIORITY[a.priority] || a.priority);
    row('Calendrier', ev.calendar ? ev.calendar.name : ''); row('Catégorie', a.category && a.category.name);
    row('Contact', a.contact); row('Lieu', a.location); row('Adresse', a.address);
    if (a.recurring) row('Récurrence', 'Série récurrente (les occurrences ne sont pas encore dépliées)');
    dlg.appendChild(dl);
    if (a.description) dlg.appendChild(el('p', 'desc', a.description));
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    x.focus();
  }

  // ---- Barre d'outils, calendriers, réglages -------------------------------------------------------------------------
  function buildToolbar() {
    var bar = document.getElementById('cal-toolbar');
    bar.appendChild(el('p', 'cal-hidden', '')).id = 'cal-hidden';
    var st = el('p', 'cal-status', ''); st.id = 'cal-status'; st.setAttribute('role', 'status'); bar.appendChild(st);
    var btn = el('button', 'btn', 'Réglages'); btn.type = 'button'; btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-controls', 'cal-settings');
    var pop = el('form', 'pop'); pop.id = 'cal-settings'; pop.hidden = true; pop.setAttribute('aria-label', 'Réglages du calendrier');
    function field(labelText, control) { var l = el('label', '', labelText); l.appendChild(control); pop.appendChild(l); return control; }
    function select(opts, val) { var s = el('select'); opts.forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; if (String(o[0]) === String(val)) op.selected = true; s.appendChild(op); }); return s; }
    var hrs = function (from, to) { var a = []; for (var i = from; i <= to; i++) a.push([i, (i < 10 ? '0' : '') + i + ':00']); return a; };
    var fStart = field('Début de la grille', select(hrs(0, 23), settings.startHour));
    var fEnd = field('Fin de la grille', select(hrs(1, 24), settings.endHour));
    var fStep = field('Pas horaire', select([[5, '5 minutes'], [10, '10 minutes'], [15, '15 minutes'], [30, '30 minutes'], [60, '60 minutes']], settings.step));
    var fWs = field('Premier jour de la semaine', select([[1, 'Lundi'], [0, 'Dimanche']], settings.weekStart));
    var fFmt = field('Format de l\'heure', select([['24', '24 heures'], ['12', '12 heures']], settings.hour12 ? '12' : '24'));
    var wl = el('label', 'check'), cb = el('input'); cb.type = 'checkbox'; cb.checked = settings.weekend; wl.append(cb, document.createTextNode(' Afficher le week-end')); pop.appendChild(wl);
    pop.appendChild(el('p', 'muted small', 'Fuseau horaire : ' + TZ + '. Ces réglages sont enregistrés dans ce navigateur. Raccourcis : t aujourd\'hui, j/k suivant/précédent, d/w/m/a vues.'));
    var msg = el('p', 'pop-msg'); msg.setAttribute('role', 'alert'); pop.appendChild(msg);
    function apply() {
      msg.textContent = '';
      if (+fEnd.value <= +fStart.value) { msg.textContent = 'La fin doit être postérieure au début.'; return; }
      settings = { startHour: +fStart.value, endHour: +fEnd.value, step: +fStep.value, weekStart: +fWs.value, weekend: cb.checked, hour12: fFmt.value === '12' };
      sanitize(); save('rvm_cal_settings', settings); document.getElementById('cal-grid').dataset.keep = '0';
      if (window.RVM.miniCal) window.RVM.miniCal.redraw();
      refresh();
    }
    [fStart, fEnd, fStep, fWs, fFmt, cb].forEach(function (c) { c.addEventListener('change', apply); });
    pop.addEventListener('submit', function (e) { e.preventDefault(); });
    btn.addEventListener('click', function () { pop.hidden = !pop.hidden; btn.setAttribute('aria-expanded', String(!pop.hidden)); });
    pop.addEventListener('keydown', function (e) { if (e.key === 'Escape') { pop.hidden = true; btn.setAttribute('aria-expanded', 'false'); btn.focus(); } });
    var wrap = el('div', 'pop-wrap'); wrap.append(btn, pop); bar.appendChild(wrap);
  }

  function buildCalendarList() {
    var box = document.getElementById('cal-list'); if (!box) return;
    box.textContent = '';
    box.appendChild(el('p', 'nav-group', 'Mes calendriers'));
    if (!calendars.length) { box.appendChild(el('p', 'muted small pad', 'Aucun calendrier accessible.')); return; }
    calendars.forEach(function (c) {
      var l = el('label', 'cal-item'), cb = el('input'); cb.type = 'checkbox'; cb.checked = hidden.indexOf(c.id) < 0;
      var sw = el('span', 'swatch'); setColor(sw, c.color);
      l.append(cb, sw, el('span', 'name', c.name + (c.mine ? '' : ' (' + c.owner + ')')));
      cb.addEventListener('change', function () {
        hidden = hidden.filter(function (x) { return x !== c.id; }); if (!cb.checked) hidden.push(c.id);
        save('rvm_cal_hidden', hidden); render();
      });
      box.appendChild(l);
    });
  }

  function init() {
    var s = document.getElementById('cal-grid'); s.setAttribute('aria-busy', 'true');
    buildToolbar();
    fetch('/api/v1/calendars', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (r.status === 401) { location.href = '/login?next=' + encodeURIComponent(url()); return null; } return r.json(); })
      .then(function (j) { calendars = (j && j.data) || []; buildCalendarList(); })
      .catch(function () { status('Liste des calendriers indisponible.', true); })
      .then(function () { s.removeAttribute('aria-busy'); refresh(); });
  }
  init();
})();
