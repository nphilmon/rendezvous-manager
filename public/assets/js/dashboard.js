// Tableau de bord : une seule requête GET /api/v1/dashboard, rendu 100 % DOM (textContent, aucun innerHTML), graphiques en HTML/CSS.
(function () {
  'use strict';
  var $ = function (s) { return document.querySelector(s); };
  var sel = $('#dash-period');
  if (!sel) return;
  var PALETTE = ['var(--dv-1)', 'var(--dv-2)', 'var(--dv-3)', 'var(--dv-4)', 'var(--dv-5)', 'var(--dv-6)', 'var(--dv-7)', 'var(--dv-8)'];
  var STATUS_COLOR = { scheduled: 'var(--dv-1)', confirmed: 'var(--dv-2)', tentative: 'var(--dv-3)', completed: 'var(--dv-4)', cancelled: 'var(--dv-none)' };
  var KEY = 'rvm.dashboard.period';
  try { var saved = localStorage.getItem(KEY); if (saved && sel.querySelector('option[value="' + saved + '"]')) sel.value = saved; } catch (e) { /* stockage indisponible */ }
  var TZ = (document.querySelector('meta[name="user-tz"]') || {}).content || 'UTC';
  var req = 0;

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function clear(n) { n.textContent = ''; }
  function fmtDay(iso) { return new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(iso + 'T00:00:00Z')); }

  function bars(host, items, unit) {
    clear(host);
    var total = items.reduce(function (s, i) { return s + i.count; }, 0), max = Math.max.apply(null, items.map(function (i) { return i.count; }).concat([1]));
    if (total === 0) { host.appendChild(el('p', 'muted', 'Aucun rendez-vous sur cette période.')); return; }
    var stack = el('div', 'dv-stack'); stack.setAttribute('aria-hidden', 'true');
    items.forEach(function (i) { if (i.count > 0) { var s = el('i'); s.style.flex = String(i.count); s.style.background = i.color; s.title = i.label + ' : ' + i.count; stack.appendChild(s); } });
    host.appendChild(stack);
    items.forEach(function (i) {
      var row = el('div', 'dv-row'), l = el('span', 'dv-lbl'), sw = el('span', 'dv-sw'), t = el('span', '', i.label);
      sw.style.background = i.color; l.title = i.label; l.append(sw, t);
      var track = el('div', 'dv-track'), bar = el('div', 'dv-bar'); bar.style.width = i.count ? Math.max(i.count / max * 100, 1.5) + '%' : '0'; bar.style.background = i.color; track.appendChild(bar);
      track.title = i.label + ' : ' + i.count + ' ' + unit;
      row.append(l, track, el('span', 'dv-num', String(i.count))); host.appendChild(row);
    });
    var d = el('details', 'dv-details'), tb = el('table'), cap = el('caption', 'sr-only', 'Données du graphique');
    d.appendChild(el('summary', '', 'Voir sous forme de tableau'));
    var hr = el('tr'); hr.append(el('th', '', 'Libellé'), el('th', '', 'Nombre')); tb.append(cap, hr);
    items.forEach(function (i) { var r = el('tr'); r.append(el('td', '', i.label), el('td', '', String(i.count))); tb.appendChild(r); });
    d.appendChild(tb); host.appendChild(d);
  }

  function days(host, list, today) {
    clear(host);
    var max = Math.max.apply(null, list.map(function (i) { return i.count; }).concat([1]));
    var cols = el('div', 'dv-cols'), xs = el('div', 'dv-xs');
    list.forEach(function (d) {
      var c = el('div', 'dv-col' + (d.date === today ? ' today' : '')), b = el('div', 'b');
      b.style.height = d.count ? Math.max(d.count / max * 80, 2) + '%' : '0'; c.title = fmtDay(d.date) + ' : ' + d.count;
      c.append(el('span', 'v', d.count ? String(d.count) : ''), b); cols.appendChild(c);
      xs.appendChild(el('span', '', fmtDay(d.date).replace('.', '')));
    });
    host.append(cols, xs);
    var det = el('details', 'dv-details'), tb = el('table'), hr = el('tr');
    det.appendChild(el('summary', '', 'Voir sous forme de tableau'));
    hr.append(el('th', '', 'Jour'), el('th', '', 'Rendez-vous')); tb.appendChild(hr);
    list.forEach(function (d) { var r = el('tr'); r.append(el('td', '', fmtDay(d.date)), el('td', '', String(d.count))); tb.appendChild(r); });
    det.appendChild(tb); host.appendChild(det);
  }

  function next(host, a) {
    clear(host);
    if (!a) { host.appendChild(el('p', 'muted', 'Aucun rendez-vous à venir dans les 30 prochains jours.')); return; }
    var card = el('div', 'next-card'), b = el('button', 'nt', a.title); b.type = 'button';
    b.addEventListener('click', function () { if (window.RVM.appointments) window.RVM.appointments.openDetails(a, null, b); });
    var when = a.all_day
      ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'full', timeZone: 'UTC' }).format(new Date(a.start)) + ' (journée entière)'
      : new Intl.DateTimeFormat('fr-FR', { dateStyle: 'full', timeStyle: 'short', timeZone: TZ }).format(new Date(a.start));
    card.append(b, el('span', '', when));
    if (a.location) card.appendChild(el('span', 'muted', a.location));
    if (a.category) card.appendChild(el('span', 'muted small', 'Catégorie : ' + a.category.name));
    host.appendChild(card);
  }

  function render(d) {
    var c = d.counts, set = function (k, v) { var n = document.querySelector('[data-k="' + k + '"]'); if (n) n.textContent = String(v); };
    set('total', c.total); set('today', c.today); set('week', c.week); set('month', c.month); set('upcoming', c.upcoming);
    set('completed', d.period.completed); set('cancelled', d.period.cancelled); set('reminders', d.notifications.reminders_24h);
    document.querySelectorAll('[data-period-label]').forEach(function (n) { n.textContent = '· ' + d.period.label.toLowerCase(); });
    var u = $('#dash-unread'); if (u) u.textContent = d.notifications.unread > 0 ? '(' + d.notifications.unread + ' non lues)' : '';
    var w = $('#dash-warn'); w.hidden = !d.truncated; if (d.truncated) w.textContent = 'Beaucoup de rendez-vous : les chiffres et graphiques sont partiels (limite d\'affichage atteinte).';
    next($('#dash-next'), d.next);
    bars($('#dash-status'), d.by_status.map(function (s) { return { label: s.label, count: s.count, color: STATUS_COLOR[s.status] }; }), 'rendez-vous');
    bars($('#dash-cat'), d.by_category.map(function (k, i) { return { label: k.name, count: k.count, color: k.color && /^#[0-9a-fA-F]{6}$/.test(k.color) ? k.color : (k.id === null ? 'var(--dv-none)' : PALETTE[i % PALETTE.length]) }; }), 'rendez-vous');
    days($('#dash-day'), d.by_day, d.by_day[0] ? d.by_day[0].date : '');
  }

  function load() {
    var n = ++req, err = $('#dash-error');
    return window.RVM.api('GET', '/api/v1/dashboard?period=' + encodeURIComponent(sel.value)).then(function (r) {
      if (n !== req) return;
      if (!r.ok) { err.hidden = false; err.textContent = (r.data && r.data.error && r.data.error.message) || 'Impossible de charger le tableau de bord.'; return; }
      err.hidden = true; render(r.data.data || r.data);
    });
  }

  sel.addEventListener('change', function () { try { localStorage.setItem(KEY, sel.value); } catch (e) { /* ignoré */ } load(); });
  document.addEventListener('rvm:sync', function (e) { if (window.RVM.sync.touches(e.detail, ['appointment', 'calendar', 'notification'])) load(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load); else load();
})();
