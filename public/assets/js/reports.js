// Rapports d'activité : une requête GET /api/v1/reports, rendu 100 % DOM (textContent), graphiques HTML/CSS avec alternative « tableau ».
(function () {
  'use strict';
  var root = document.getElementById('reports');
  if (!root) return;
  var $ = function (id) { return document.getElementById(id); };
  var TZ = (document.querySelector('meta[name="user-tz"]') || {}).content || 'UTC';
  var PALETTE = ['var(--dv-1)', 'var(--dv-2)', 'var(--dv-3)', 'var(--dv-4)', 'var(--dv-5)', 'var(--dv-6)', 'var(--dv-7)', 'var(--dv-8)'];
  var STATUS = { scheduled: ['Planifié', 'var(--dv-1)'], confirmed: ['Confirmé', 'var(--dv-2)'], tentative: ['Provisoire', 'var(--dv-3)'], completed: ['Terminé', 'var(--dv-4)'], cancelled: ['Annulé', 'var(--dv-none)'] };
  var PRIO = { low: 'Basse', normal: 'Normale', high: 'Haute', urgent: 'Urgente' };
  var req = 0;
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function today() {
    var o = {}; new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()).forEach(function (p) { o[p.type] = +p.value; });
    return new Date(Date.UTC(o.year, o.month - 1, o.day));
  }
  function iso(d) { return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()); }
  function preset(k) {
    var t = today(), a, b;
    if (k === 'month') { a = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth(), 1)); b = new Date(Date.UTC(t.getUTCFullYear(), t.getUTCMonth() + 1, 0)); }
    else if (k === '30d') { a = new Date(t.getTime() - 29 * 86400000); b = t; }
    else { a = new Date(Date.UTC(t.getUTCFullYear(), 0, 1)); b = new Date(Date.UTC(t.getUTCFullYear(), 11, 31)); }
    $('rp-from').value = iso(a); $('rp-to').value = iso(b);
  }

  function table(items) {
    var d = el('details', 'dv-details'), tb = el('table'), hr = el('tr');
    d.appendChild(el('summary', '', 'Voir sous forme de tableau'));
    hr.append(el('th', '', 'Libellé'), el('th', '', 'Nombre')); tb.appendChild(hr);
    items.forEach(function (i) { var r = el('tr'); r.append(el('td', '', i.label), el('td', '', String(i.count))); tb.appendChild(r); });
    d.appendChild(tb); return d;
  }
  function bars(items) {
    var box = el('div'), total = items.reduce(function (s, i) { return s + i.count; }, 0), max = Math.max.apply(null, items.map(function (i) { return i.count; }).concat([1]));
    if (total === 0) { box.appendChild(el('p', 'muted', 'Aucun rendez-vous sur cette période.')); return box; }
    items.forEach(function (i, n) {
      var row = el('div', 'dv-row'), l = el('span', 'dv-lbl'), sw = el('span', 'dv-sw'), t = el('span', '', i.label), color = i.color || PALETTE[n % PALETTE.length];
      sw.style.background = color; l.title = i.label; l.append(sw, t);
      var track = el('div', 'dv-track'), bar = el('div', 'dv-bar'); bar.style.width = i.count ? Math.max(i.count / max * 100, 1.5) + '%' : '0'; bar.style.background = color; track.appendChild(bar);
      row.append(l, track, el('span', 'dv-num', String(i.count))); box.appendChild(row);
    });
    box.appendChild(table(items)); return box;
  }
  function columns(items) {
    var box = el('div'), max = Math.max.apply(null, items.map(function (i) { return i.count; }).concat([1]));
    if (items.every(function (i) { return i.count === 0; })) { box.appendChild(el('p', 'muted', 'Aucun rendez-vous sur cette période.')); return box; }
    var cols = el('div', 'dv-cols'), xs = el('div', 'dv-xs');
    items.forEach(function (i) {
      var c = el('div', 'dv-col'), b = el('div', 'b'); b.style.height = i.count ? Math.max(i.count / max * 80, 2) + '%' : '0'; c.title = i.label + ' : ' + i.count;
      c.append(el('span', 'v', i.count ? String(i.count) : ''), b); cols.appendChild(c); xs.appendChild(el('span', '', i.label.length > 7 ? i.label.slice(0, 3) : i.label));
    });
    box.append(cols, xs, table(items)); return box;
  }
  function panel(title, content, wide) {
    var s = el('section', 'panel' + (wide ? ' panel-wide' : '')); s.appendChild(el('h2', '', title)); s.appendChild(content); return s;
  }
  function render(d) {
    var t = d.totals, cards = $('rp-cards'); cards.textContent = '';
    [[t.appointments, 'Rendez-vous'], [t.completed + (t.completed_rate !== null ? ' (' + t.completed_rate + ' %)' : ''), 'Terminés'], [t.cancelled + (t.cancelled_rate !== null ? ' (' + t.cancelled_rate + ' %)' : ''), 'Annulés'],
      [t.active, 'À venir ou en cours'], [t.hours + ' h', 'Heures planifiées'], [t.all_day, 'Journées entières']].forEach(function (c) {
      var a = el('article', 'stat'); a.append(el('span', 'stat-n', String(c[0])), el('span', 'stat-l', c[1])); cards.appendChild(a);
    });
    var warn = $('rp-warn'); warn.hidden = !d.truncated; if (d.truncated) warn.textContent = 'Beaucoup de rendez-vous : les chiffres sont partiels (limite d\'affichage atteinte). Réduisez la période.';
    var g = $('rp-grid'); g.textContent = '';
    g.appendChild(panel('Par statut', bars(Object.keys(d.by_status).map(function (k) { return { label: STATUS[k][0], count: d.by_status[k], color: STATUS[k][1] }; }))));
    g.appendChild(panel('Par priorité', bars(Object.keys(d.by_priority).map(function (k) { return { label: PRIO[k], count: d.by_priority[k] }; }))));
    g.appendChild(panel('Par catégorie', bars(d.by_category)));
    g.appendChild(panel('Par calendrier', bars(d.by_calendar)));
    g.appendChild(panel('Par créateur', bars(d.by_creator)));
    g.appendChild(panel('Principaux contacts', bars(d.top_contacts)));
    g.appendChild(panel('Par jour de la semaine', columns(d.by_weekday)));
    g.appendChild(panel('Par heure de début (' + d.period.timezone + ')', columns(d.by_hour), true));
    g.appendChild(panel('Par mois', columns(d.by_month.length ? d.by_month : [{ label: '—', count: 0 }])));
  }
  function query() { return '?from=' + encodeURIComponent($('rp-from').value) + '&to=' + encodeURIComponent($('rp-to').value); }
  function load() {
    var n = ++req, err = $('rp-err'); err.hidden = true;
    return window.RVM.api('GET', '/api/v1/reports' + query()).then(function (r) {
      if (n !== req) return;
      if (!r.ok) { err.hidden = false; err.textContent = (r.data && r.data.error && r.data.error.message) || 'Chargement impossible.'; return; }
      render(r.data.data);
    });
  }

  document.querySelectorAll('[data-preset]').forEach(function (b) { b.addEventListener('click', function () { preset(b.dataset.preset); load(); }); });
  $('rp-form').addEventListener('submit', function (e) { e.preventDefault(); load(); });
  var csv = $('rp-csv');
  if (csv) csv.addEventListener('click', function () {
    csv.disabled = true; $('rp-err').hidden = true;
    fetch('/api/v1/reports' + query() + '&format=csv', { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) return r.json().catch(function () { return {}; }).then(function (j) { throw new Error((j.error && j.error.message) || 'Export impossible.'); });
      return r.blob();
    }).then(function (b) {
      var a = document.createElement('a'), url = URL.createObjectURL(b); a.href = url; a.download = 'rapport-' + $('rp-from').value + '_' + $('rp-to').value + '.csv'; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }).catch(function (e) { $('rp-err').hidden = false; $('rp-err').textContent = e.message; }).then(function () { csv.disabled = false; });
  });
  document.addEventListener('rvm:sync', function (e) { if (window.RVM.sync.touches(e.detail, ['appointment', 'calendar'])) load(); });
  preset('month'); load();
})();
