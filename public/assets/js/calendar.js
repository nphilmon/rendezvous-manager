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

  // ---- Réglages d'affichage : enregistrés sur le serveur (window.RVM.ui), identiques sur tous les appareils -------------
  function load(key, fallback) { try { var v = JSON.parse(localStorage.getItem(key)); return v === null ? fallback : v; } catch (e) { return fallback; } }
  function save(key, v) { try { localStorage.setItem(key, JSON.stringify(v)); } catch (e) {} }
  function fromServer() {
    var u = window.RVM.ui || {};
    return { startHour: u.start_hour, endHour: u.end_hour, step: u.step, weekStart: u.week_start, weekend: u.weekend, hour12: u.hour12 };
  }
  var settings = Object.assign({}, DEFAULTS, fromServer());
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
    return { raw: a, s: s, e: e, allDay: !!a.all_day, id: a.id, key: a.id + '@' + (a.occurrence_start || ''), calendar: cal,
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
      (ev.calendar ? ', ' + ev.calendar.name : '') + (ev.raw.status === 'cancelled' ? ', annulé' : '') + (ev.raw.recurring ? ', série récurrente' : '');
  }
  function eventButton(ev, cls) {
    var b = el('button', 'ev ' + cls + (ev.raw.status === 'cancelled' ? ' cancelled' : '') + (ev.raw.status === 'tentative' ? ' tentative' : ''));
    b.type = 'button'; setColor(b, ev.color); b.setAttribute('aria-label', label(ev)); b.dataset.recurring = ev.raw.recurring ? '1' : '0';
    b.addEventListener('click', function () { window.RVM.appointments.openDetails(ev.raw, ev.calendar, b); });
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
      var cell = el('div', 'allday-cell' + (d === t0 ? ' today' : '')); cell.dataset.day = d;
      cell.addEventListener('click', function (e) { if (canCreate() && !e.target.closest('.ev')) openCreate(d, d, true); });
      vis.filter(function (ev) { return ev.allDay && ev.s < d + DAY && ev.e > d; }).forEach(function (ev) {
        shownIds[ev.key] = 1; var chip = cell.appendChild(eventButton(ev, 'chip')); chip.appendChild(el('span', 't', ev.raw.title));
        if (editable(ev)) makeDayDraggable(chip, ev, d);
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
      var col = el('div', 'tg-col' + (d === t0 ? ' today' : '')); col.style.height = height + 'px'; col.dataset.day = d; attachCreate(col, d);
      col.style.setProperty('--slot', (settings.step * p) + 'px'); col.style.setProperty('--hour', (60 * p) + 'px');
      var items = [];
      vis.filter(function (ev) { return !ev.allDay; }).forEach(function (ev) {
        var seg = segmentsFor(ev, d); if (!seg) return;
        var s = (seg[0] - d) / 60000, e = (seg[1] - d) / 60000, lo = settings.startHour * 60, hi = settings.endHour * 60;
        if (e < lo || s >= hi || (e === s && (s < lo || s >= hi))) return; // hors plage
        items.push({ ev: ev, s: Math.max(s, lo), e: Math.min(Math.max(e, s + 15), hi) });
        shownIds[ev.key] = 1;
      });
      layoutColumns(items);
      items.forEach(function (it) {
        var b = eventButton(it.ev, 'blk'), lo = settings.startHour * 60;
        b.style.top = ((it.s - lo) * p) + 'px'; b.style.height = Math.max(18, (it.e - it.s) * p - 1) + 'px';
        b.style.left = (it.col / it.cols * 100) + '%'; b.style.width = ((1 / it.cols) * 100) + '%';
        b.appendChild(el('span', 'tt', it.ev.raw.title));
        b.appendChild(el('span', 'tm', fmtTime(it.ev.s) + (it.ev.e > it.ev.s ? ' – ' + fmtTime(it.ev.e) : '')));
        col.appendChild(b);
        if (editable(it.ev)) makeBlockEditable(b, it.ev, d, col);
      });
      if (d === t0) { var nl = el('div', 'now-line'); nl.dataset.base = String(d); col.appendChild(nl); positionNow(nl); }
      body.appendChild(col);
    });
    tg.appendChild(body); scroll.appendChild(tg); box.appendChild(scroll);
    vis.forEach(function (ev) { if (!shownIds[ev.key] && range().s < ev.e && ev.s < range().e) hiddenCount++; });
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
      var cell = el('div', 'mg-cell' + (new Date(d).getUTCMonth() !== month ? ' other' : '') + (d === t0 ? ' today' : '')); cell.dataset.day = d;
      var num = el('a', 'mg-num', fmtDay(d, { day: 'numeric' }) + (new Date(d).getUTCDate() === 1 ? ' ' + fmtDay(d, { month: 'short' }) : ''));
      num.href = '/calendar?view=day&date=' + isoDate(d); num.setAttribute('aria-label', fmtDay(d, { weekday: 'long', day: 'numeric', month: 'long' }));
      num.addEventListener('click', function (e) { if (!e.ctrlKey && !e.metaKey) { e.preventDefault(); go('day', d); } });
      cell.appendChild(num);
      cell.addEventListener('click', function (e) { if (canCreate() && e.target === cell) openCreate(d + 9 * 3600000, d + 10 * 3600000, false); });
      var list = vis.filter(function (ev) { return ev.s < d + DAY && (ev.e > d || (ev.s === ev.e && ev.s >= d)); })
        .sort(function (a, b) { return (b.allDay - a.allDay) || (a.s - b.s); });
      list.slice(0, MAX).forEach(function (ev) {
        shown[ev.key] = 1;
        var b = eventButton(ev, ev.allDay ? 'chip' : 'line');
        if (!ev.allDay) b.appendChild(el('span', 'dot'));
        if (!ev.allDay) b.appendChild(el('span', 'tm', fmtTime(ev.s)));
        b.appendChild(el('span', 'tt', ev.raw.title)); cell.appendChild(b);
        if (editable(ev)) makeDayDraggable(b, ev, d);
      });
      if (list.length > MAX) {
        var more = el('a', 'more', '+ ' + (list.length - MAX) + ' autre' + (list.length - MAX > 1 ? 's' : ''));
        more.href = '/calendar?view=day&date=' + isoDate(d); more.addEventListener('click', function (e) { e.preventDefault(); go('day', d); });
        list.slice(MAX).forEach(function (ev) { shown[ev.key] = 1; }); cell.appendChild(more);
      }
      grid.appendChild(cell);
    });
    vis.forEach(function (ev) { if (!shown[ev.key]) hiddenCount++; });
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


  // ---- Déplacement et redimensionnement (souris, appui long au toucher, clavier) ----------------------------------------------
  // Les séries récurrentes ne sont pas modifiables ici (phase 9). Chaque changement passe par l'API « reschedule » (verrou optimiste).
  var dragging = false;
  function editable(ev) { return !!(ev.calendar && ev.calendar.can && ev.calendar.can.update); }
  function iso19(ms) { return new Date(ms).toISOString().slice(0, 19) + 'Z'; }
  function say(msg, err) { window.RVM.appointments.toast(msg, err); }

  /** Envoie le nouvel horaire (heure murale). En cas de conflit horaire, demande confirmation puis réessaie. */
  function commit(ev, s, e, okMsg, force, scope) {
    if (ev.raw.recurring && !scope) { // série : portée de la modification demandée avant l'envoi
      return window.RVM.appointments.askScope('Modifier').then(function (sc) { if (!sc) { refresh(); return; } return commit(ev, s, e, okMsg, force, sc); });
    }
    var body = { version: ev.raw.version, start: ev.allDay ? isoDate(s) : iso19(unwall(s)), end: ev.allDay ? isoDate(e) : iso19(unwall(e)) };
    if (ev.raw.recurring) { body.scope = scope; body.occurrence = ev.raw.occurrence_start || ev.raw.start; }
    if (force) body.ignore_conflicts = true;
    return window.RVM.api('POST', '/api/v1/appointments/' + ev.id + '/reschedule', body).then(function (r) {
      if (r.ok) { say(okMsg); refresh(); return; }
      var d = r.data && r.data.error && r.data.error.details;
      if (r.status === 409 && d && d.code === 'time_conflict' && !force &&
          window.confirm('Conflit horaire avec « ' + d.conflicts[0].title + ' ». Enregistrer quand même ?')) return commit(ev, s, e, okMsg, true, scope);
      var m = r.data && r.data.error ? r.data.error.message : 'Modification impossible.';
      if (d && d.code === 'version_conflict') m = 'Ce rendez-vous a été modifié entre-temps : affichage rechargé.';
      say(m, true); refresh();
    });
  }

  document.addEventListener('touchmove', function (e) { if (dragging) e.preventDefault(); }, { passive: false });
  /** Après un glisser, le navigateur envoie un « click » à l'ancêtre commun (colonne, cellule…) : on l'ignore. */
  function suppressClick() {
    var h = function (e) {
      document.removeEventListener('click', h, true); // une seule fois : ne jamais avaler un vrai clic ultérieur
      if (e.target && e.target.closest && e.target.closest('dialog')) return;
      e.stopImmediatePropagation(); e.preventDefault();
    };
    document.addEventListener('click', h, true); setTimeout(function () { document.removeEventListener('click', h, true); }, 80);
  }

  /** Contrôleur de glisser commun : seuil de 5 px (souris) ou appui long 450 ms (toucher), Échap pour annuler, défilement automatique. */
  function pointerDrag(down, btn, cfg) {
    if ((down.pointerType !== 'touch' && down.button !== 0) || dragging) return;
    var touch = down.pointerType === 'touch', id = down.pointerId, x0 = down.clientX, y0 = down.clientY, started = false, timer = null, last = down, scroller = btn.closest('.tg-scroll'), auto = null;
    function begin() {
      started = true; dragging = true; btn.classList.add('dragging'); document.body.classList.add('is-dragging');
      if (touch && navigator.vibrate) navigator.vibrate(12);
      if (cfg.onStart) cfg.onStart(down);
    }
    function cleanup() {
      clearTimeout(timer); clearInterval(auto);
      document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up);
      document.removeEventListener('pointercancel', cancel); document.removeEventListener('keydown', key, true);
      if (started) { dragging = false; btn.classList.remove('dragging'); document.body.classList.remove('is-dragging'); }
    }
    function move(e) {
      if (e.pointerId !== id) return;
      last = e;
      if (!started) {
        var moved = Math.hypot(e.clientX - x0, e.clientY - y0);
        if (touch) { if (moved > 8) cleanup(); return; }
        if (moved < 5) return;
        begin();
      }
      e.preventDefault(); cfg.onMove(e);
      if (scroller && !auto) {
        auto = setInterval(function () {
          var r = scroller.getBoundingClientRect(), y = last.clientY, dy = y < r.top + 50 ? -14 : (y > r.bottom - 50 ? 14 : 0);
          if (dy) { scroller.scrollTop += dy; cfg.onMove(last); }
        }, 40);
      }
    }
    function up(e) { if (e.pointerId !== id) return; var was = started; cleanup(); if (was) { suppressClick(); cfg.onDrop(e); } }
    function cancel(e) { if (e.pointerId !== id) return; var was = started; cleanup(); if (was) cfg.onCancel(); }
    function key(e) { if (e.key === 'Escape') { e.stopPropagation(); var was = started; cleanup(); if (was) { suppressClick(); cfg.onCancel(); } } }
    document.addEventListener('pointermove', move); document.addEventListener('pointerup', up);
    document.addEventListener('pointercancel', cancel); document.addEventListener('keydown', key, true);
    if (touch) timer = setTimeout(begin, 450);
  }

  function snap(min) { return Math.round(min / settings.step) * settings.step; }
  function rawMin(col, y) { return settings.startHour * 60 + (y - col.getBoundingClientRect().top) / ppm(); }
  function columnAt(cols, x) {
    var best = cols[0], bd = Infinity;
    cols.forEach(function (c) { var r = c.getBoundingClientRect(), d = x < r.left ? r.left - x : (x > r.right ? x - r.right : 0); if (d < bd) { bd = d; best = c; } });
    return best;
  }
  function labelRange(s, e) { return fmtTime(s) + ' – ' + fmtTime(e); }

  /** Bloc de la grille horaire : déplacement (corps) et redimensionnement (bords haut et bas). */
  function makeBlockEditable(b, ev, day, col) {
    b.classList.add('editable');
    var startsHere = ev.s >= day, endsHere = ev.e <= day + DAY, dur = ev.e - ev.s;
    var topH, botH;
    if (startsHere) { topH = el('span', 'rs rs-top'); topH.setAttribute('aria-hidden', 'true'); b.appendChild(topH); }
    if (endsHere) { botH = el('span', 'rs rs-bot'); botH.setAttribute('aria-hidden', 'true'); b.appendChild(botH); }
    var tm = b.querySelector('.tm');

    function resize(down, edge) {
      down.stopPropagation(); down.preventDefault();
      var anchor = rawMin(col, down.clientY), res = { s: ev.s, e: ev.e }, topPx = b.style.top, hPx = b.style.height, lo = settings.startHour * 60;
      pointerDrag(down, b, {
        onMove: function (e) {
          var d = snap(rawMin(col, e.clientY) - anchor) * 60000;
          if (edge === 'bot') res.e = Math.min(day + settings.endHour * 60 * 60000, Math.max(ev.s + settings.step * 60000, ev.e + d));
          else res.s = Math.max(day + lo * 60000, Math.min(ev.e - settings.step * 60000, ev.s + d));
          var from = Math.max(res.s, day), to = Math.min(res.e, day + DAY);
          b.style.top = (((from - day) / 60000 - lo) * ppm()) + 'px'; b.style.height = Math.max(18, (to - from) / 60000 * ppm() - 1) + 'px';
          if (tm) tm.textContent = labelRange(res.s, res.e);
        },
        onDrop: function () { if (res.s === ev.s && res.e === ev.e) { b.style.top = topPx; b.style.height = hPx; if (tm) tm.textContent = labelRange(ev.s, ev.e); return; } commit(ev, res.s, res.e, 'Durée modifiée.'); },
        onCancel: function () { b.style.top = topPx; b.style.height = hPx; if (tm) tm.textContent = labelRange(ev.s, ev.e); }
      });
    }
    if (topH) topH.addEventListener('pointerdown', function (e) { resize(e, 'top'); });
    if (botH) botH.addEventListener('pointerdown', function (e) { resize(e, 'bot'); });

    b.addEventListener('pointerdown', function (down) {
      if (down.target.classList.contains('rs')) return;
      var cols = Array.prototype.slice.call(col.parentNode.querySelectorAll('.tg-col')), anchor = rawMin(col, down.clientY), res = { s: ev.s, e: ev.e }, ghost = null;
      pointerDrag(down, b, {
        onMove: function (e) {
          var target = columnAt(cols, e.clientX), dd = +target.dataset.day - day, dm = snap(rawMin(col, e.clientY) - anchor);
          res.s = ev.s + dd + dm * 60000; res.e = res.s + dur;
          var d0 = +target.dataset.day, from = Math.max(res.s, d0), to = Math.min(res.e, d0 + DAY), lo = settings.startHour * 60;
          if (!ghost || ghost.parentNode !== target) { if (ghost) ghost.remove(); ghost = el('div', 'drag-ghost'); setColor(ghost, ev.color); target.appendChild(ghost); }
          var top = Math.max(0, ((from - d0) / 60000 - lo) * ppm());
          ghost.style.top = top + 'px'; ghost.style.height = Math.max(18, Math.max(0, to - from) / 60000 * ppm()) + 'px';
          ghost.textContent = labelRange(res.s, res.e);
        },
        onDrop: function () { if (ghost) ghost.remove(); if (res.s === ev.s) return; commit(ev, res.s, res.e, 'Rendez-vous déplacé.'); },
        onCancel: function () { if (ghost) ghost.remove(); }
      });
    });

    b.addEventListener('keydown', keyNudge(ev, true));
  }

  /** Chips « journée entière » et lignes du mois : déplacement par jours (la durée et l'heure sont conservées). */
  function makeDayDraggable(b, ev, day) {
    b.classList.add('editable');
    b.addEventListener('pointerdown', function (down) {
      var res = { s: ev.s, e: ev.e }, hl = null;
      pointerDrag(down, b, {
        onMove: function (e) {
          var el2 = document.elementFromPoint(e.clientX, e.clientY), cell = el2 && el2.closest('[data-day]');
          if (hl) hl.classList.remove('drop-target');
          if (!cell || cell.classList.contains('tg-col')) { hl = null; res.s = ev.s; res.e = ev.e; return; }
          hl = cell; cell.classList.add('drop-target');
          var dd = +cell.dataset.day - day; res.s = ev.s + dd; res.e = ev.e + dd;
        },
        onDrop: function () { if (hl) hl.classList.remove('drop-target'); if (res.s === ev.s) return; commit(ev, res.s, res.e, 'Rendez-vous déplacé.'); },
        onCancel: function () { if (hl) hl.classList.remove('drop-target'); }
      });
    });
    b.addEventListener('keydown', keyNudge(ev, false));
  }

  /** Alternative clavier : Alt+↑/↓ déplace d'un pas, Alt+Maj+↑/↓ change la fin, Alt+←/→ déplace d'un jour. */
  function keyNudge(ev, timed) {
    return function (e) {
      if (!e.altKey || e.ctrlKey || e.metaKey) return;
      var k = e.key, step = settings.step * 60000, s = ev.s, en = ev.e, msg = 'Rendez-vous déplacé.';
      if (k === 'ArrowLeft' || k === 'ArrowRight') { var d = (k === 'ArrowRight' ? 1 : -1) * DAY; s += d; en += d; }
      else if ((k === 'ArrowUp' || k === 'ArrowDown') && timed && !ev.allDay) {
        var dir = k === 'ArrowDown' ? 1 : -1;
        if (e.shiftKey) { en = Math.max(ev.s + step, ev.e + dir * step); msg = 'Durée modifiée.'; } else { s += dir * step; en += dir * step; }
      } else return;
      e.preventDefault();
      if (busy) return; busy = true;
      commit(ev, s, en, msg).then(function () { busy = false; }, function () { busy = false; });
    };
  }
  var busy = false;

  // ---- Création : clic, sélection (glisser) et clavier -------------------------------------------------------------------------
  function canCreate() { return root.dataset.canCreate === '1' && calendars.some(function (c) { return c.can && c.can.create; }); }
  function calendarForNew() { var c = calendars.filter(function (x) { return x.can && x.can.create && hidden.indexOf(x.id) < 0; })[0]; return c ? c.id : undefined; }
  function openCreate(startWall, endWall, allDay) {
    window.RVM.appointments.openForm({ start: startWall, end: endWall, allDay: !!allDay, calendarId: calendarForNew() });
  }
  window.RVM.calendarDate = function () { return state.date; };
  document.addEventListener('rvm:appointments-changed', function () { refresh(); });

  /** Convertit une ordonnée dans la colonne en minutes (alignées sur le pas), à partir de minuit. */
  function minutesAt(col, clientY, ceil) {
    var y = clientY - col.getBoundingClientRect().top, m = settings.startHour * 60 + y / ppm();
    var q = ceil ? Math.ceil(m / settings.step) : Math.floor(m / settings.step);
    return Math.min(settings.endHour * 60, Math.max(settings.startHour * 60, q * settings.step));
  }
  function attachCreate(col, day) {
    col.addEventListener('pointerdown', function (e) {
      if (e.button !== 0 || !canCreate() || e.target.closest('.ev')) return;
      var touch = e.pointerType === 'touch', y0 = e.clientY, a = minutesAt(col, y0, false), ghost = null, moved = false;
      if (touch) return; // au toucher : simple appui (événement click ci-dessous), le glissement fait défiler la page
      function move(ev) {
        if (!moved && Math.abs(ev.clientY - y0) < 6) return;
        moved = true;
        var b = minutesAt(col, ev.clientY, true), lo = Math.min(a, minutesAt(col, ev.clientY, false)), hi = Math.max(a + settings.step, b);
        if (!ghost) { ghost = el('div', 'ghost'); col.appendChild(ghost); }
        ghost.style.top = ((lo - settings.startHour * 60) * ppm()) + 'px'; ghost.style.height = ((hi - lo) * ppm()) + 'px';
        ghost.dataset.lo = lo; ghost.dataset.hi = hi;
        ghost.textContent = fmtTime(day + lo * 60000) + ' – ' + fmtTime(day + hi * 60000);
      }
      function up() {
        document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); document.removeEventListener('keydown', esc);
        if (moved && ghost) {
          var lo = +ghost.dataset.lo, hi = +ghost.dataset.hi; ghost.remove();
          col.dataset.dragged = '1'; setTimeout(function () { col.dataset.dragged = '0'; }, 0); // le « click » qui suit le relâchement est ignoré
          openCreate(day + lo * 60000, day + hi * 60000, false);
        }
      }
      function esc(ev) { if (ev.key === 'Escape') { moved = false; if (ghost) ghost.remove(); ghost = null; up(); } }
      document.addEventListener('pointermove', move); document.addEventListener('pointerup', up); document.addEventListener('keydown', esc);
    });
    col.addEventListener('click', function (e) {
      if (!canCreate() || e.target.closest('.ev') || e.detail === 0) return;
      if (col.dataset.dragged === '1') return;
      var m = minutesAt(col, e.clientY, false);
      openCreate(day + m * 60000, day + Math.min(m + ((window.RVM.ui && window.RVM.ui.default_duration) || 60), settings.endHour * 60) * 60000, false);
    });
  }
  document.addEventListener('keydown', function (e) {
    var t = e.target, tag = t && t.tagName;
    if (e.key !== 'c' || e.ctrlKey || e.metaKey || e.altKey || tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA' || document.querySelector('dialog[open]')) return;
    if (!canCreate()) return;
    e.preventDefault(); window.RVM.appointments.openNew({ calendarId: calendarForNew() });
  });

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
    pop.appendChild(el('p', 'muted small', 'Fuseau horaire : ' + TZ + '. Ces réglages sont enregistrés sur le serveur (tous vos appareils) ; autres préférences : page Paramètres. Raccourcis : c nouveau, t aujourd\'hui, j/k suivant/précédent, d/w/m/a vues.'));
    var msg = el('p', 'pop-msg'); msg.setAttribute('role', 'alert'); pop.appendChild(msg);
    function apply() {
      msg.textContent = '';
      if (+fEnd.value <= +fStart.value) { msg.textContent = 'La fin doit être postérieure au début.'; return; }
      settings = { startHour: +fStart.value, endHour: +fEnd.value, step: +fStep.value, weekStart: +fWs.value, weekend: cb.checked, hour12: fFmt.value === '12' };
      sanitize();
      window.RVM.saveUi({ start_hour: settings.startHour, end_hour: settings.endHour, step: settings.step, week_start: settings.weekStart, weekend: settings.weekend, hour12: settings.hour12 }).then(function (r) { if (!r.ok) msg.textContent = 'Enregistrement impossible sur le serveur.'; }); document.getElementById('cal-grid').dataset.keep = '0';
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

  /** (Re)charge la liste des calendriers visibles et les droits associés. */
  function loadCalendars() {
    return fetch('/api/v1/calendars', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) { if (r.status === 401) { location.href = '/login?next=' + encodeURIComponent(url()); return null; } return r.json(); })
      .then(function (j) { if (j && j.data) { calendars = j.data; hidden = hidden.filter(function (id) { return calendars.some(function (c) { return c.id === id; }); }); buildCalendarList(); } })
      .catch(function () { status('Liste des calendriers indisponible.', true); });
  }

  function init() {
    var s = document.getElementById('cal-grid'); s.setAttribute('aria-busy', 'true');
    buildToolbar();
    loadCalendars().then(function () { s.removeAttribute('aria-busy'); refresh(); });
  }

  // Synchronisation : un autre utilisateur ou un autre onglet a modifié des données visibles. Pendant un glisser, on attend la fin.
  var syncTries = 0;
  document.addEventListener('rvm:sync', function onSync(e) {
    var d = e.detail;
    if (dragging && syncTries++ < 20) { setTimeout(function () { onSync(e); }, 500); return; }
    syncTries = 0;
    if (window.RVM.sync.touches({ reset: d.reset, accessChanged: d.accessChanged, changes: d.changes }, ['calendar']) && (d.reset || d.accessChanged || d.changes.some(function (c) { return c.entity === 'calendar'; }))) {
      loadCalendars().then(refresh);
    } else if (d.changes.some(function (c) { return c.entity === 'appointment'; })) {
      refresh();
    }
  });
  init();
})();
