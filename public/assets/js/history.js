// Journal d'audit : filtres, pagination, détails lisibles (rendu avec textContent uniquement).
(function () {
  'use strict';
  var root = document.getElementById('history');
  if (!root) return;
  var api = window.RVM.api, results = document.getElementById('h-results'), pager = document.getElementById('h-pager');
  var TZ = (document.querySelector('meta[name="user-tz"]') || {}).content || 'UTC';
  var fmt = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'medium', timeZone: TZ });
  var state = { page: 1 };
  var FIELD = { title: 'Titre', location: 'Lieu', address: 'Adresse', status: 'Statut', priority: 'Priorité', start_utc: 'Début (UTC)', end_utc: 'Fin (UTC)', all_day: 'Journée entière', timezone: 'Fuseau',
    calendar_id: 'Calendrier', category_id: 'Catégorie', contact_id: 'Contact', color: 'Couleur', description: 'Description', notes: 'Notes' };
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }

  function detail(h) {
    var d = h.details; if (!d) return '';
    var parts = [];
    Object.keys(d).forEach(function (k) {
      var v = d[k];
      if (k === 'changes' && v && typeof v === 'object') { Object.keys(v).forEach(function (f) { parts.push((FIELD[f] || f) + ' : ' + (Array.isArray(v[f]) ? (v[f][0] || '∅') + ' → ' + (v[f][1] || '∅') : v[f])); }); return; }
      parts.push(k + ' : ' + (v !== null && typeof v === 'object' ? JSON.stringify(v) : String(v)));
    });
    return parts.join(' · ');
  }

  function render(rows, meta) {
    results.textContent = '';
    if (!rows.length) { results.appendChild(el('p', 'empty', 'Aucune entrée ne correspond.')); pager.textContent = ''; return; }
    var wrap = el('div', 'table-wrap'), t = el('table', 'tbl'), th = el('thead'), hr = el('tr');
    ['Date', 'Utilisateur', 'Action', 'Élément', 'Détails', 'Adresse IP'].forEach(function (c) { var h = el('th', '', c); h.scope = 'col'; hr.appendChild(h); });
    th.appendChild(hr); t.appendChild(th);
    var tb = el('tbody');
    rows.forEach(function (h) {
      var tr = el('tr');
      tr.appendChild(el('td', 'nowrap', fmt.format(new Date(h.at))));
      tr.appendChild(el('td', '', h.user ? h.user.name : '—'));
      var a = el('td', '', h.label); a.title = h.action; tr.appendChild(a);
      tr.appendChild(el('td', '', h.entity_type ? h.entity_type + (h.entity_id ? ' #' + h.entity_id : '') : ''));
      tr.appendChild(el('td', 'small', detail(h)));
      tr.appendChild(el('td', 'small muted', h.ip || ''));
      tb.appendChild(tr);
    });
    t.appendChild(tb); wrap.appendChild(t); results.appendChild(wrap);
    pager.textContent = '';
    pager.appendChild(el('span', 'muted', meta.total + ' entrée' + (meta.total > 1 ? 's' : '') + ' — page ' + meta.page + '/' + meta.pages));
    var prev = el('button', 'btn btn-sm', 'Précédent'), next = el('button', 'btn btn-sm', 'Suivant'); prev.type = next.type = 'button';
    prev.disabled = meta.page <= 1; next.disabled = meta.page >= meta.pages;
    prev.addEventListener('click', function () { state.page--; load(); }); next.addEventListener('click', function () { state.page++; load(); });
    pager.append(prev, next);
  }

  var actionsLoaded = false;
  function load() {
    var q = ['page=' + state.page];
    [['q', 'h-q'], ['action', 'h-action'], ['from', 'h-from'], ['to', 'h-to']].forEach(function (p) { var v = document.getElementById(p[1]).value.trim(); if (v) q.push(p[0] + '=' + encodeURIComponent(v)); });
    return api('GET', '/api/v1/audit?' + q.join('&')).then(function (r) {
      if (!r.ok) { results.textContent = ''; results.appendChild(el('p', 'alert alert-error', (r.data && r.data.error && r.data.error.message) || 'Chargement impossible.')); return; }
      if (!actionsLoaded) {
        actionsLoaded = true; var sel = document.getElementById('h-action');
        r.data.meta.actions.forEach(function (a) { var o = el('option', '', a); o.value = a; sel.appendChild(o); });
      }
      render(r.data.data, r.data.meta);
    });
  }
  document.getElementById('h-filter').addEventListener('submit', function (e) { e.preventDefault(); state.page = 1; load(); });
  document.getElementById('h-reset').addEventListener('click', function () { document.getElementById('h-filter').reset(); state.page = 1; load(); });
  load();
})();
