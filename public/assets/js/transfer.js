// Import / export : téléchargement via fetch (erreurs JSON lisibles), import en deux temps (simulation puis import réel). Tout est rendu avec textContent.
(function () {
  'use strict';
  var $ = function (s) { return document.querySelector(s); };
  if (!$('#export-form')) return;
  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;
  var FORMATS = { appointments: [['csv', 'CSV (tableur)'], ['xlsx', 'Excel (XLSX)'], ['pdf', 'PDF (impression)'], ['ics', 'iCalendar (.ics)']], contacts: [['csv', 'CSV (tableur)'], ['xlsx', 'Excel (XLSX)']] };

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function show(n, msg) { n.hidden = !msg; n.textContent = msg || ''; }

  // ---- export -------------------------------------------------------------
  var what = $('#ex-what'), format = $('#ex-format');
  function formats() {
    var cur = format.value; format.textContent = '';
    FORMATS[what.value].forEach(function (f) { var o = el('option', '', f[1]); o.value = f[0]; format.appendChild(o); });
    if (cur && format.querySelector('option[value="' + cur + '"]')) format.value = cur;
    $('#ex-appt').hidden = what.value !== 'appointments';
  }
  what.addEventListener('change', formats); formats();

  $('#export-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var err = $('#ex-err'), ok = $('#ex-ok'), btn = $('#ex-go');
    show(err, ''); show(ok, '');
    var q = ['format=' + encodeURIComponent(format.value)];
    if (what.value === 'appointments') {
      [['from', '#ex-from'], ['to', '#ex-to'], ['calendars', '#ex-cal'], ['status', '#ex-status']].forEach(function (p) { var v = $(p[1]).value; if (v) q.push(p[0] + '=' + encodeURIComponent(v)); });
    }
    btn.disabled = true;
    fetch('/api/v1/export/' + what.value + '?' + q.join('&'), { credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401) { location.href = '/login?next=' + encodeURIComponent(location.pathname); return null; }
      if (!r.ok) return r.json().catch(function () { return {}; }).then(function (j) { throw new Error((j.error && j.error.message) || 'Export impossible (' + r.status + ').'); });
      var name = ((r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'export';
      var count = r.headers.get('X-Export-Count');
      return r.blob().then(function (b) {
        var a = document.createElement('a'), url = URL.createObjectURL(b);
        a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
        show(ok, (count !== null ? count + ' ligne(s) exportée(s) — ' : '') + name);
      });
    }).catch(function (x) { show(err, x.message); }).then(function () { btn.disabled = false; });
  });

  // ---- calendriers (filtres et import) ---------------------------------------
  window.RVM.api('GET', '/api/v1/calendars').then(function (r) {
    if (!r.ok) return;
    var list = r.data.data;
    list.forEach(function (c) { var o = el('option', '', c.name); o.value = c.id; $('#ex-cal').appendChild(o); });
    var im = $('#im-cal'); if (!im) return;
    var any = false;
    list.filter(function (c) { return c.can.create; }).forEach(function (c) { var o = el('option', '', c.name); o.value = c.id; im.appendChild(o); any = true; });
    if (!any) { var o = el('option', '', 'Aucun calendrier où créer'); o.value = ''; im.appendChild(o); }
  });

  // ---- import ---------------------------------------------------------------
  var form = $('#import-form');
  if (!form) return;
  var kind = $('#im-kind');
  function kindChanged() { $('#im-cal-wrap').hidden = kind.value.indexOf('appointments') !== 0; }
  kind.addEventListener('change', kindChanged); kindChanged();

  function result(d) {
    var box = $('#im-result'); box.textContent = ''; box.hidden = false; box.className = 'tr-result';
    var created = d.dry_run ? d.would_create : d.created;
    box.appendChild(el('h3', '', d.dry_run ? 'Simulation (rien n\'a été modifié)' : 'Import terminé'));
    var dl = el('dl', 'kv');
    [['Lignes lues', d.total], [d.dry_run ? 'Seraient créées' : 'Créées', created], ['Doublons ignorés', d.duplicates], ['Ignorées', d.ignored], ['En erreur', d.error_count]].forEach(function (p) {
      dl.append(el('dt', '', p[0]), el('dd', '', String(p[1])));
    });
    box.appendChild(dl);
    [['Erreurs', d.errors, d.error_count], ['Avertissements', d.warnings, d.warnings.length]].forEach(function (g) {
      if (!g[1].length) return;
      box.appendChild(el('h4', '', g[0]));
      var ul = el('ul', 'tr-list');
      g[1].forEach(function (x) { ul.appendChild(el('li', '', 'Ligne ' + x.row + ' : ' + x.message)); });
      if (g[2] > g[1].length) ul.appendChild(el('li', 'muted', '… et ' + (g[2] - g[1].length) + ' autre(s)'));
      box.appendChild(ul);
    });
    if (d.dry_run && created > 0) box.appendChild(el('p', 'muted small', 'Si le résultat vous convient, cliquez sur « Importer ».'));
  }

  function run(dry) {
    var err = $('#im-err'), f = $('#im-file').files[0];
    show(err, ''); $('#im-result').hidden = true;
    if (!f) return show(err, 'Choisissez un fichier.');
    if (f.size > 2000000) return show(err, 'Fichier trop volumineux (2 Mo maximum).');
    var parts = kind.value.split(':'), what = parts[0], fmt = parts[1];
    var buttons = [$('#im-dry'), $('#im-go')]; buttons.forEach(function (b) { b.disabled = true; });
    f.text().then(function (text) {
      var body = { content: text, dry_run: dry };
      if (what === 'appointments') { body.format = fmt; if ($('#im-cal').value) body.calendar_id = parseInt($('#im-cal').value, 10); } else body.format = 'csv';
      return window.RVM.api('POST', '/api/v1/import/' + what, body);
    }).then(function (r) {
      if (!r.ok) return show(err, (r.data && r.data.error && r.data.error.message) || 'Import impossible (' + r.status + ').');
      result(r.data.data);
      if (!dry && window.RVM.appointments) window.RVM.appointments.invalidate();
    }).catch(function () { show(err, 'Lecture du fichier impossible.'); }).then(function () { buttons.forEach(function (b) { b.disabled = false; }); });
  }
  $('#im-dry').addEventListener('click', function () { run(true); });
  form.addEventListener('submit', function (e) { e.preventDefault(); run(false); });
})();
