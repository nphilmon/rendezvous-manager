/* RendezVous Manager – formulaire, détails, actions et listes de rendez-vous.
   Toute donnée affichée est insérée via textContent (aucun innerHTML). */
(function () {
  'use strict';

  window.RVM = window.RVM || {};
  var api = function () { return window.RVM.api.apply(null, arguments); };
  var tzMeta = document.querySelector('meta[name="user-tz"]');
  var TZ = tzMeta ? tzMeta.content : 'UTC';
  var DAY = 86400000;
  var STATUS = { scheduled: 'Planifié', confirmed: 'Confirmé', tentative: 'Provisoire', cancelled: 'Annulé', completed: 'Terminé' };
  var PRIORITY = { low: 'Basse', normal: 'Normale', high: 'Haute', urgent: 'Urgente' };
  var COLORS = [['', 'Par défaut (catégorie ou calendrier)'], ['#2563eb', 'Bleu'], ['#16a34a', 'Vert'], ['#dc2626', 'Rouge'], ['#f59e0b', 'Orange'],
    ['#9333ea', 'Violet'], ['#0891b2', 'Cyan'], ['#db2777', 'Rose'], ['#64748b', 'Gris']];
  var REMINDERS = [[null, 'Aucun'], [0, 'À l\'heure du rendez-vous'], [5, '5 minutes avant'], [10, '10 minutes avant'], [15, '15 minutes avant'],
    [30, '30 minutes avant'], [60, '1 heure avant'], [120, '2 heures avant'], [1440, '1 jour avant']];

  // ---- Fuseau horaire (heure « murale » : ms UTC dont les composantes UTC sont l'heure locale) ------------------------
  var partsFmt = new Intl.DateTimeFormat('en-CA', { timeZone: TZ, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  function wall(ms) {
    var o = {}; partsFmt.formatToParts(new Date(ms)).forEach(function (p) { o[p.type] = +p.value; });
    return Date.UTC(o.year, o.month - 1, o.day, o.hour, o.minute, o.second);
  }
  function unwall(w) { var g = w; for (var i = 0; i < 3; i++) g = w - (wall(g) - g); return g; }
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  function dateStr(w) { return new Date(w).toISOString().slice(0, 10); }
  function timeStr(w) { var d = new Date(w); return pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()); }
  function parseWall(date, time) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date || ''), t = /^(\d{2}):(\d{2})$/.exec(time || '00:00');
    return m && t ? Date.UTC(+m[1], +m[2] - 1, +m[3], +t[1], +t[2]) : null;
  }
  function isoUtc(ms) { return new Date(ms).toISOString().slice(0, 19) + 'Z'; }
  function fmt(o, tz) { o.timeZone = tz || TZ; return new Intl.DateTimeFormat('fr-FR', o); }

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function setColor(n, c) { n.style.setProperty('--c', /^#[0-9a-fA-F]{3,8}$/.test(c || '') ? c : '#2563eb'); }

  // ---- Notifications éphémères ------------------------------------------------------------------------------------
  var toastBox = el('div', 'toast-box'); toastBox.setAttribute('role', 'status'); toastBox.setAttribute('aria-live', 'polite');
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(toastBox); });
  if (document.body) document.body.appendChild(toastBox);
  function toast(msg, isError) {
    var t = el('div', 'toast' + (isError ? ' error' : ''), msg); toastBox.appendChild(t);
    setTimeout(function () { t.remove(); }, isError ? 7000 : 3500);
  }
  function changed() { document.dispatchEvent(new CustomEvent('rvm:appointments-changed')); }

  // ---- Séries récurrentes : portée d'une modification ---------------------------------------------------------------------------
  var scopeDlg = null;
  /** Demande « cet événement / cet événement et les suivants / tous » ; résout 'single' | 'following' | 'all' ou null (annulé). */
  function askScope(title, opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      if (!scopeDlg) { scopeDlg = el('dialog', 'scope-dialog'); document.body.appendChild(scopeDlg); }
      var d = scopeDlg, choice = null; d.textContent = '';
      var f = el('form', 'scope-form'); f.method = 'dialog';
      var h = el('h2', '', title); h.id = 'scope-title'; d.setAttribute('aria-labelledby', 'scope-title');
      var fs = el('fieldset'), lg = el('legend', 'sr-only', 'Portée'); fs.appendChild(lg);
      [['single', 'Cet événement'], ['following', 'Cet événement et les suivants'], ['all', 'Tous les événements']].forEach(function (o, i) {
        if (opts.exclude && opts.exclude.indexOf(o[0]) >= 0) return;
        var l = el('label', 'radio'), r = input('radio', { name: 'scope', value: o[0] }); if (i === 0 || (opts.exclude && !fs.querySelector('input'))) r.checked = true;
        l.append(r, document.createTextNode(' ' + o[1])); fs.appendChild(l);
      });
      var bar = el('div', 'af-actions'), ok = el('button', 'btn btn-primary', 'OK'), no = el('button', 'btn', 'Annuler'); ok.type = 'submit'; no.type = 'button';
      no.addEventListener('click', function () { d.close(); });
      bar.append(ok, no); f.append(h, fs, bar); d.appendChild(f);
      f.addEventListener('submit', function (e) { e.preventDefault(); var c = f.querySelector('input:checked'); choice = c ? c.value : null; d.close(); });
      d.addEventListener('close', function onClose() { d.removeEventListener('close', onClose); resolve(choice); });
      if (d.showModal) d.showModal(); else d.setAttribute('open', '');
      var first = f.querySelector('input:checked'); if (first) first.focus();
    });
  }
  /** Corps de requête pour une série (portée + début d'origine) ; inchangé pour un rendez-vous simple. */
  function seriesBody(raw, scope, extra) {
    var b = Object.assign({ version: raw.version }, extra || {});
    if (raw.recurring) { b.scope = scope; b.occurrence = raw.occurrence_start || raw.start; }
    return b;
  }
  var DOW = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'], CODES = { MO: 'lundi', TU: 'mardi', WE: 'mercredi', TH: 'jeudi', FR: 'vendredi', SA: 'samedi', SU: 'dimanche' };
  var UNIT = { daily: ['jour', 'jours'], weekly: ['semaine', 'semaines'], monthly: ['mois', 'mois'], yearly: ['an', 'ans'] };
  function describeRule(rec) {
    if (!rec) return '';
    var n = rec.interval || 1, u = UNIT[rec.freq] || ['', ''], t = n === 1 ? ({ daily: 'Tous les jours', weekly: 'Toutes les semaines', monthly: 'Tous les mois', yearly: 'Tous les ans' }[rec.freq]) : 'Tous les ' + n + ' ' + u[1];
    if (rec.freq === 'weekly' && n > 1) t = 'Toutes les ' + n + ' semaines';
    var days = (rec.byday || []).map(function (d) { var m = /^([+-]?\d+)?(MO|TU|WE|TH|FR|SA|SU)$/.exec(d), nth = m[1] ? (+m[1] === -1 ? 'dernier ' : m[1] + 'ᵉ ') : ''; return nth + CODES[m[2]]; });
    if (days.length) t += ' (' + days.join(', ') + ')';
    if (rec.bymonthday && rec.bymonthday.length) t += ' le ' + rec.bymonthday.join(', ');
    var e = rec.end || {};
    if (e.type === 'count') t += ', ' + e.count + ' fois';
    if (e.type === 'until') t += ', jusqu\'au ' + fmt({ day: 'numeric', month: 'long', year: 'numeric' }, 'UTC').format(Date.parse(e.until + 'T00:00:00Z'));
    return t;
  }

  // ---- Données de référence (mises en cache) ------------------------------------------------------------------------
  var cache = {};
  function ref(name, path) {
    if (!cache[name]) cache[name] = api('GET', path).then(function (r) { return r.ok && r.data ? r.data.data : []; }).catch(function () { return []; });
    return cache[name];
  }
  var calendars = function () { return ref('cal', '/api/v1/calendars'); };
  var categories = function () { return ref('cat', '/api/v1/categories'); };
  var contacts = function () { return ref('con', '/api/v1/contacts?per_page=500'); };
  function calendarOf(list, id) { for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return null; }

  // ---- Synchronisation : invalidation des listes de référence et avertissement d'édition concurrente -------------------
  document.addEventListener('rvm:sync', function (e) {
    var d = e.detail;
    if (d.reset || d.accessChanged || d.changes.some(function (c) { return c.entity === 'calendar'; })) delete cache.cal;
    if (d.reset || d.changes.some(function (c) { return c.entity === 'category'; })) delete cache.cat;
    if (d.reset || d.changes.some(function (c) { return c.entity === 'contact'; })) delete cache.con;
    if (formDlg && formDlg.open && state && state.id) {
      var hit = d.changes.filter(function (c) { return c.entity === 'appointment' && c.entity_id === state.id; }).pop(); // y compris un autre onglet du même utilisateur : le formulaire est modal, donc ce n'est pas lui
      if (hit) showRemoteChange(hit.change === 'deleted');
    }
  });

  // ---- Détails d'un rendez-vous ---------------------------------------------------------------------------------------
  var dlg = el('dialog', 'ev-dialog'); dlg.setAttribute('aria-labelledby', 'ev-title');
  var detailsOpener = null;
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  dlg.addEventListener('close', function () { if (detailsOpener && document.contains(detailsOpener)) detailsOpener.focus(); });
  document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(dlg); });
  if (document.body) document.body.appendChild(dlg);

  function describeTime(a) {
    var s = Date.parse(a.start), e = Date.parse(a.end);
    if (a.all_day) {
      var last = e - DAY;
      return fmt({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }, 'UTC').format(s) + (last > s ? ' → ' + fmt({ weekday: 'long', day: 'numeric', month: 'long' }, 'UTC').format(last) : '') + ' (journée entière)';
    }
    var d = fmt({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }), t = fmt({ hour: '2-digit', minute: '2-digit' });
    var sameDay = dateStr(wall(s)) === dateStr(wall(e - 1));
    return d.format(s) + ', ' + t.format(s) + ' – ' + (sameDay ? '' : d.format(e) + ', ') + t.format(e) + ' (' + TZ + ')';
  }

  function openDetails(raw, cal, opener) {
    detailsOpener = opener || null; dlg.textContent = '';
    var color = raw.color || (raw.category && raw.category.color) || (cal && cal.color) || '#2563eb';
    var head = el('div', 'dlg-head'), bar = el('span', 'bar'); setColor(bar, color);
    var h = el('h2', '', raw.title); h.id = 'ev-title';
    var x = el('button', 'icon-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); x.addEventListener('click', function () { dlg.close(); });
    head.append(bar, h, x); dlg.appendChild(head);
    var dl = el('dl', 'kv');
    function row(k, v) { if (v === null || v === undefined || v === '') return; dl.appendChild(el('dt', '', k)); dl.appendChild(el('dd', '', v)); }
    row('Quand', describeTime(raw)); row('Statut', STATUS[raw.status] || raw.status); row('Priorité', PRIORITY[raw.priority] || raw.priority);
    row('Calendrier', cal ? cal.name : ''); row('Catégorie', raw.category && raw.category.name); row('Contact', raw.contact);
    row('Lieu', raw.location); row('Adresse', raw.address);
    if (raw.recurring) {
      dl.appendChild(el('dt', '', 'Répétition')); var rd = el('dd', '', 'Série récurrente'); dl.appendChild(rd);
      api('GET', '/api/v1/appointments/' + raw.id + '?occurrence=' + encodeURIComponent(raw.occurrence_start || raw.start)).then(function (r) {
        if (r.ok && r.data.data.recurrence) rd.textContent = describeRule(r.data.data.recurrence);
      });
    }
    if (raw.deleted) row('État', 'Dans la corbeille');
    dlg.appendChild(dl);
    if (raw.description) dlg.appendChild(el('p', 'desc', raw.description));
    dlg.appendChild(historySection(raw.id));
    dlg.appendChild(actions(raw, cal, function () { dlg.close(); }));
    if (dlg.showModal) dlg.showModal(); else dlg.setAttribute('open', '');
    x.focus();
  }

  // ---- Historique d'un rendez-vous (journal d'audit, chargé à l'ouverture de la section) ---------------------------------------
  var FIELD = { title: 'Titre', location: 'Lieu', address: 'Adresse', status: 'Statut', priority: 'Priorité', start_utc: 'Début (UTC)', end_utc: 'Fin (UTC)', all_day: 'Journée entière', timezone: 'Fuseau',
    calendar_id: 'Calendrier', category_id: 'Catégorie', contact_id: 'Contact', color: 'Couleur', description: 'Description', notes: 'Notes' };
  function historySection(id) {
    var box = el('details', 'hist'), sum = el('summary', '', 'Historique'), body = el('div', 'hist-body'), loaded = false;
    box.append(sum, body);
    box.addEventListener('toggle', function () {
      if (!box.open || loaded) return; loaded = true; body.textContent = 'Chargement…';
      api('GET', '/api/v1/appointments/' + id + '/history').then(function (r) {
        body.textContent = '';
        if (!r.ok) { body.textContent = 'Historique indisponible.'; return; }
        if (!r.data.data.length) { body.appendChild(el('p', 'muted small', 'Aucune entrée.')); return; }
        var fmt = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short', timeZone: TZ }), ul = el('ul', 'hist-list');
        r.data.data.forEach(function (h) {
          var li = el('li'), t = el('div', '', fmt.format(new Date(h.at)) + ' — ' + h.label + (h.user ? ' par ' + h.user.name : ''));
          li.appendChild(t);
          var ch = h.details && h.details.changes;
          if (ch && typeof ch === 'object') Object.keys(ch).forEach(function (k) {
            li.appendChild(el('div', 'muted small', (FIELD[k] || k) + ' : ' + (Array.isArray(ch[k]) ? (ch[k][0] || '∅') + ' → ' + (ch[k][1] || '∅') : ch[k])));
          });
          ul.appendChild(li);
        });
        body.appendChild(ul);
      });
    });
    return box;
  }

  // ---- Actions (modifier, dupliquer, reporter, annuler, terminer, supprimer, restaurer) ---------------------------------------
  function errorMessage(r, fallback) {
    var d = r.data && r.data.error ? r.data.error : {};
    if (d.details && d.details.code === 'version_conflict') return 'Ce rendez-vous a été modifié entre-temps. Les données ont été rechargées.';
    return d.message || fallback;
  }

  function call(label, method, path, body, success) {
    return api(method, path, body).then(function (r) {
      if (r.ok) { toast(success); changed(); return r; }
      toast(errorMessage(r, label + ' impossible.'), true);
      if (r.status === 409) changed();
      return r;
    });
  }

  function shiftIso(raw, days) {
    if (raw.all_day) { return { start: dateStr(Date.parse(raw.start) + days * DAY), end: dateStr(Date.parse(raw.end) + days * DAY) }; }
    return { start: isoUtc(unwall(wall(Date.parse(raw.start)) + days * DAY)), end: isoUtc(unwall(wall(Date.parse(raw.end)) + days * DAY)) };
  }

  /** Pour une série : demande la portée (null = abandon) ; pour un rendez-vous simple : 'all' sans question. */
  function scopeFor(raw, title, opts) { return raw.recurring ? askScope(title, opts) : Promise.resolve('all'); }

  function actions(raw, cal, done) {
    var box = el('div', 'dlg-actions'), can = (cal && cal.can) || {}, base = '/api/v1/appointments/' + raw.id;
    function btn(text, cls, fn) {
      var b = el('button', 'btn ' + (cls || ''), text); b.type = 'button';
      b.addEventListener('click', function () { b.disabled = true; Promise.resolve(fn()).then(function () { b.disabled = false; }); });
      box.appendChild(b); return b;
    }
    if (raw.deleted) {
      if (can.delete) btn('Restaurer', 'btn-primary', function () { return call('Restauration', 'POST', base + '/restore', undefined, 'Rendez-vous restauré.').then(done); });
      return box;
    }
    if (can.update) btn('Modifier', 'btn-primary', function () { done(); openForm({ id: raw.id, occurrence: raw.recurring ? (raw.occurrence_start || raw.start) : undefined }); return Promise.resolve(); });
    if (can.create) btn('Dupliquer', '', function () {
      return call('Duplication', 'POST', base + '/duplicate', raw.recurring ? { occurrence: raw.occurrence_start || raw.start } : undefined, 'Rendez-vous dupliqué.').then(done);
    });
    if (can.update) {
      [[1, 'Reporter de 1 jour'], [7, 'Reporter de 1 semaine']].forEach(function (p) {
        btn(p[1], '', function () {
          return scopeFor(raw, p[1]).then(function (scope) {
            if (!scope) return;
            var t = shiftIso(raw, p[0]), body = seriesBody(raw, scope, { start: t.start, end: t.end });
            return api('POST', base + '/reschedule', body).then(function (r) {
              var d = r.data && r.data.error && r.data.error.details;
              if (r.status === 409 && d && d.code === 'time_conflict' && window.confirm('Conflit horaire avec « ' + d.conflicts[0].title + ' ». Reporter quand même ?')) {
                body.ignore_conflicts = true;
                return call('Report', 'POST', base + '/reschedule', body, 'Rendez-vous reporté.').then(done);
              }
              if (r.ok) { toast('Rendez-vous reporté.'); changed(); done(); } else { toast(errorMessage(r, 'Report impossible.'), true); if (r.status === 409) changed(); }
            });
          });
        });
      });
      if (raw.status !== 'cancelled') btn('Annuler le rendez-vous', '', function () {
        return scopeFor(raw, 'Annuler').then(function (scope) { return scope && call('Annulation', 'POST', base + '/cancel', seriesBody(raw, scope), 'Rendez-vous annulé.').then(done); });
      });
      if (raw.status !== 'completed') btn('Marquer comme terminé', '', function () {
        return scopeFor(raw, 'Marquer comme terminé').then(function (scope) { return scope && call('Opération', 'POST', base + '/complete', seriesBody(raw, scope), 'Rendez-vous terminé.').then(done); });
      });
    }
    if (can.delete) btn('Supprimer', 'btn-danger', function () { return deleteWithScope(raw).then(function (ok) { if (ok) done(); }); });
    return box;
  }

  /** Suppression (avec choix de portée pour une série) ; résout true si effectuée. */
  function deleteWithScope(raw) {
    var go = raw.recurring ? askScope('Supprimer') : Promise.resolve(window.confirm('Supprimer « ' + raw.title + ' » ? Il pourra être restauré depuis la corbeille.') ? 'all' : null);
    return go.then(function (scope) {
      if (!scope) return false;
      return api('DELETE', '/api/v1/appointments/' + raw.id, seriesBody(raw, scope)).then(function (r) {
        if (r.ok) { toast(scope === 'all' ? 'Rendez-vous supprimé (restaurable depuis la corbeille).' : 'Suppression effectuée.'); changed(); return true; }
        toast(errorMessage(r, 'Suppression impossible.'), true); if (r.status === 409) changed(); return false;
      });
    });
  }

  // ---- Formulaire ---------------------------------------------------------------------------------------------------
  var form = null, formDlg = null, formOpener = null, state = null;

  function field(labelText, control, id, hint) {
    var wrap = el('div', 'fld'), l = el('label', '', labelText); l.setAttribute('for', id); control.id = id;
    var err = el('p', 'fld-err'); err.id = id + '-err'; err.hidden = true;
    control.setAttribute('aria-describedby', id + '-err');
    wrap.append(l, control); if (hint) wrap.appendChild(el('p', 'muted small', hint)); wrap.appendChild(err);
    wrap._ctl = control; return wrap;
  }
  function select(options, value) {
    var s = el('select');
    options.forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0] === null ? '' : o[0]; if (String(o[0] === null ? '' : o[0]) === String(value === null || value === undefined ? '' : value)) op.selected = true; s.appendChild(op); });
    return s;
  }
  function input(type, attrs) { var i = el('input'); i.type = type; Object.keys(attrs || {}).forEach(function (k) { i.setAttribute(k, attrs[k]); }); return i; }

  function buildForm() {
    formDlg = el('dialog', 'form-dialog'); formDlg.setAttribute('aria-labelledby', 'af-title');
    form = el('form', 'af'); form.noValidate = true;
    formDlg.appendChild(form);
    formDlg.addEventListener('close', function () { if (formOpener && document.contains(formOpener)) formOpener.focus(); });
    formDlg.addEventListener('click', function (e) { if (e.target === formDlg) formDlg.close(); });
    document.body.appendChild(formDlg);
    form.addEventListener('submit', function (e) { e.preventDefault(); save('close'); });
  }

  /** Ouvre le formulaire. opts : { id } (modification) ou { start, end (heure murale, ms), allDay, calendarId } (création). */
  function openForm(opts) {
    opts = opts || {};
    if (!formDlg) buildForm();
    formOpener = document.activeElement;
    var q = opts.id && opts.occurrence ? '?occurrence=' + encodeURIComponent(opts.occurrence) : '';
    Promise.all([calendars(), categories(), contacts(), opts.id ? api('GET', '/api/v1/appointments/' + opts.id + q) : Promise.resolve(null)]).then(function (res) {
      var cals = res[0], cats = res[1], cons = res[2], r = res[3];
      if (r && !r.ok) { toast(errorMessage(r, 'Rendez-vous introuvable.'), true); changed(); return; }
      var existing = r ? r.data.data : null;
      if (existing && existing.recurring && !opts.scope) {
        return askScope('Modifier').then(function (scope) { if (scope) { opts.scope = scope; proceed(); } });
      }
      proceed();
      function proceed() {
      var writable = cals.filter(function (c) { return existing ? (c.can.update || c.id === existing.calendar_id) : c.can.create; });
      if (!writable.length) { toast('Vous n\'avez accès en création à aucun calendrier.', true); return; }
      render(existing, opts, writable, cats, cons);
      }
    });
  }

  function render(a, opts, cals, cats, cons) {
    form.textContent = '';
    var edit = !!a, allDay = a ? a.all_day : !!opts.allDay;
    var sW, eW;
    if (a) {
      if (a.all_day) { sW = Date.parse(a.start); eW = Date.parse(a.end) - DAY; } else { sW = wall(Date.parse(a.start)); eW = wall(Date.parse(a.end)); }
    } else {
      var now = wall(Date.now()), base = opts.start !== undefined ? opts.start : Math.ceil(now / 3600000) * 3600000;
      sW = base; eW = opts.end !== undefined ? opts.end : base + (((window.RVM.ui && window.RVM.ui.default_duration) || 60) * 60000);
      if (allDay) { sW = Math.floor(sW / DAY) * DAY; eW = sW; }
    }
    state = { id: a ? a.id : null, version: a ? a.version : null, duration: Math.max(eW - sW, 15 * 60000), allDay: allDay,
      series: !!(a && a.recurring), scope: a && a.recurring ? opts.scope : null, occurrence: a && a.recurring ? (a.occurrence_start || a.start) : null, raw: a };

    var scopeLabel = { single: ' (cet événement)', following: ' (cet événement et les suivants)', all: ' (tous les événements)' }[state.scope] || '';
    var head = el('div', 'dlg-head'), h = el('h2', '', (edit ? 'Modifier le rendez-vous' : 'Nouveau rendez-vous') + scopeLabel); h.id = 'af-title';
    var x = el('button', 'icon-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); x.addEventListener('click', function () { formDlg.close(); });
    head.append(h, x); form.appendChild(head);
    var summary = el('div', 'alert alert-error'); summary.id = 'af-summary'; summary.setAttribute('role', 'alert'); summary.hidden = true; form.appendChild(summary);
    var conflictBox = el('div', 'alert af-conflict'); conflictBox.id = 'af-conflict'; conflictBox.setAttribute('role', 'alert'); conflictBox.hidden = true; form.appendChild(conflictBox);

    var title = input('text', { maxlength: 200, required: 'required', autocomplete: 'off', 'data-lpignore': 'true', 'data-1p-ignore': 'true', 'data-bwignore': 'true' }); title.value = a ? a.title : ''; // les gestionnaires de mots de passe n'ont rien à proposer ici
    form.appendChild(field('Titre', title, 'af-title-in'));
    var desc = el('textarea'); desc.rows = 3; desc.maxLength = 10000; desc.value = a && a.description ? a.description : '';
    form.appendChild(field('Description', desc, 'af-desc'));

    var adLabel = el('label', 'check'), ad = input('checkbox'); ad.id = 'af-allday'; ad.checked = allDay; adLabel.setAttribute('for', 'af-allday');
    adLabel.append(ad, document.createTextNode(' Journée entière')); form.appendChild(adLabel);
    var row1 = el('div', 'row2'), row2 = el('div', 'row2');
    var sd = input('date', { required: 'required' }), st = input('time', { step: 300 }), ed = input('date', { required: 'required' }), et = input('time', { step: 300 });
    sd.value = dateStr(sW); st.value = allDay ? '09:00' : timeStr(sW); ed.value = dateStr(eW); et.value = allDay ? '10:00' : timeStr(eW);
    var fsd = field('Date de début', sd, 'af-sd'), fst = field('Heure de début', st, 'af-st'), fed = field('Date de fin', ed, 'af-ed'), fet = field('Heure de fin', et, 'af-et');
    row1.append(fsd, fst); row2.append(fed, fet); form.append(row1, row2);
    function toggleAllDay() { fst.hidden = fet.hidden = ad.checked; state.allDay = ad.checked; }
    ad.addEventListener('change', toggleAllDay); toggleAllDay();
    function recompute() { return parseWall(ed.value, ad.checked ? '00:00' : et.value) - parseWall(sd.value, ad.checked ? '00:00' : st.value); }
    function shiftEnd() { // garde la durée quand on change le début
      var s = parseWall(sd.value, ad.checked ? '00:00' : st.value); if (s === null) return;
      var e = s + (ad.checked ? Math.max(state.duration, 0) : state.duration);
      ed.value = dateStr(e); if (!ad.checked) et.value = timeStr(e);
    }
    [sd, st].forEach(function (c) { c.addEventListener('change', shiftEnd); });
    [ed, et].forEach(function (c) { c.addEventListener('change', function () { var d = recompute(); if (d > 0 || (ad.checked && d >= 0)) state.duration = d; }); });

        // ---- Répétition (masquée pour « cet événement » seul) ----
    var rep = null;
    if (state.scope !== 'single') {
      rep = buildRepeat(a && a.recurrence ? a.recurrence : null, sd, state.series ? state.scope : null);
      form.appendChild(rep.node);
    }
    if (state.series && state.scope === 'all') {
      form.appendChild(el('p', 'muted small', 'Pour « tous les événements », seuls l\'horaire et la durée s\'appliquent aux autres occurrences ; la date de début de la série ne change pas.'));
    }

    var cal = select(cals.map(function (c) { return [c.id, c.name]; }), a ? a.calendar_id : (opts.calendarId || lastCalendar(cals)));
    form.appendChild(field('Calendrier', cal, 'af-cal'));
    var cat = select([[null, 'Aucune']].concat(cats.map(function (c) { return [c.id, c.name]; })), a && a.category ? a.category.id : null);
    var con = select([[null, 'Aucun']].concat(cons.map(function (c) { return [c.id, c.name + (c.company ? ' (' + c.company + ')' : '')]; })), a ? a.contact_id : (opts.contactId || null));
    var rowC = el('div', 'row2'); rowC.append(field('Catégorie', cat, 'af-cat'), field('Contact', con, 'af-con')); form.appendChild(rowC);
    var loc = input('text', { maxlength: 190 }); loc.value = a && a.location ? a.location : '';
    var adr = input('text', { maxlength: 255 }); adr.value = a && a.address ? a.address : '';
    var rowL = el('div', 'row2'); rowL.append(field('Lieu', loc, 'af-loc'), field('Adresse', adr, 'af-adr')); form.appendChild(rowL);
    var stat = select(Object.keys(STATUS).map(function (k) { return [k, STATUS[k]]; }), a ? a.status : 'scheduled');
    var prio = select(Object.keys(PRIORITY).map(function (k) { return [k, PRIORITY[k]]; }), a ? a.priority : 'normal');
    var rowS = el('div', 'row2'); rowS.append(field('Statut', stat, 'af-stat'), field('Priorité', prio, 'af-prio')); form.appendChild(rowS);
    var color = select(COLORS, a ? a.color : '');
    var rv = a ? a.reminder_minutes : null, dm = document.querySelector('meta[name="default-reminder"]');
    if (!a && dm && dm.content !== '') rv = parseInt(dm.content, 10); // rappel proposé par défaut (réglage de l'utilisateur)
    var remOpts = REMINDERS.concat([['custom', 'Personnalisé…']]);
    var preset = rv === null || rv === undefined || REMINDERS.some(function (o) { return o[0] === rv; });
    var rem = select(remOpts, preset ? rv : 'custom');
    var customBox = el('div', 'rep-line'), cn = input('number', { min: 0, max: 40320, 'aria-label': 'Durée du rappel' }), cu = el('select'); cu.setAttribute('aria-label', 'Unité du rappel');
    [['1', 'minutes'], ['60', 'heures'], ['1440', 'jours']].forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; cu.appendChild(op); });
    customBox.append(cn, cu, document.createTextNode(' avant')); customBox.hidden = true;
    if (!preset) { var unit = rv % 1440 === 0 ? '1440' : (rv % 60 === 0 ? '60' : '1'); cu.value = unit; cn.value = String(rv / parseInt(unit, 10)); }
    function syncCustom() { customBox.hidden = rem.value !== 'custom'; }
    rem.addEventListener('change', syncCustom); syncCustom();
    state.remCustom = { box: customBox, n: cn, u: cu };
    var rowR = el('div', 'row2'); var remField = field('Rappel', rem, 'af-rem', 'Notification envoyée par le serveur, même si cette page est fermée.'); remField.insertBefore(customBox, remField.querySelector('.fld-err'));
    rowR.append(field('Couleur', color, 'af-color'), remField); form.appendChild(rowR);
    var notes = el('textarea'); notes.rows = 2; notes.maxLength = 10000; notes.value = a && a.notes ? a.notes : '';
    form.appendChild(field('Notes', notes, 'af-notes'));

    var bar = el('div', 'af-actions');
    function btn(text, cls, type, fn) { var b = el('button', 'btn ' + cls, text); b.type = type; if (fn) b.addEventListener('click', fn); bar.appendChild(b); return b; }
    btn('Enregistrer', 'btn-primary', 'submit');
    if (!edit) btn('Enregistrer et nouveau', '', 'button', function () { save('new'); });
    if (edit && cals.some(function (c) { return c.id === a.calendar_id && c.can.delete; }) && a.can && a.can.delete) {
      var del = btn('Supprimer', 'btn-danger', 'button', function () {
        deleteWithScope(Object.assign({}, a, { version: state.version })).then(function (ok) { if (ok) formDlg.close(); });
      });
      del.style.marginRight = 'auto';
    }
    btn('Annuler', '', 'button', function () { formDlg.close(); });
    form.appendChild(bar);

    state.rep = rep;
    state.ctl = { title: title, desc: desc, ad: ad, sd: sd, st: st, ed: ed, et: et, cal: cal, cat: cat, con: con, loc: loc, adr: adr, stat: stat, prio: prio, color: color, rem: rem, notes: notes };
    state.fields = { recurrence: rep ? rep.focusEl : null, title: title, description: desc, start: sd, end: ed, calendar_id: cal, category_id: cat, contact_id: con, location: loc, address: adr,
      status: stat, priority: prio, color: color, reminder_minutes: rem, notes: notes };
    state.edit = edit;
    if (formDlg.showModal && !formDlg.open) formDlg.showModal(); else formDlg.setAttribute('open', '');
    title.focus();
  }

  /** Section « Répétition » : préréglages + personnalisation + fin. value() : objet, null (aucune), undefined (inchangé) ou false (invalide). */
  function buildRepeat(existing, startDate, scope) {
    var node = el('fieldset', 'repeat'), lg = el('legend', '', 'Répétition'); node.appendChild(lg);
    function wd() { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(startDate.value); return m ? new Date(Date.UTC(+m[1], +m[2] - 1, +m[3])) : new Date(); }
    function code() { return ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'][wd().getUTCDay()]; }
    var sel = el('select'); sel.id = 'af-rep'; sel.setAttribute('aria-label', 'Répétition');
    var presets = [['none', 'Ne se répète pas'], ['daily', 'Tous les jours'], ['weekly', 'Toutes les semaines'], ['monthly', 'Tous les mois'], ['yearly', 'Tous les ans'], ['weekdays', 'Du lundi au vendredi'], ['custom', 'Personnalisée…']];
    presets.forEach(function (o) { if (o[0] === 'none' && scope && scope !== 'all') return; var op = el('option', '', o[1]); op.value = o[0]; sel.appendChild(op); });
    node.appendChild(sel);

    var custom = el('div', 'rep-custom'); custom.hidden = true;
    var every = el('div', 'rep-line'), nIn = input('number', { min: 1, max: 999, value: 1, 'aria-label': 'Intervalle' }), fSel = el('select'); fSel.setAttribute('aria-label', 'Unité');
    [['daily', 'jours'], ['weekly', 'semaines'], ['monthly', 'mois'], ['yearly', 'ans']].forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; fSel.appendChild(op); });
    every.append(document.createTextNode('Tous les '), nIn, fSel);
    var days = el('div', 'rep-days'), dayBoxes = {};
    [['MO', 'L'], ['TU', 'M'], ['WE', 'M'], ['TH', 'J'], ['FR', 'V'], ['SA', 'S'], ['SU', 'D']].forEach(function (d) {
      var l = el('label', 'day'), c = input('checkbox'); c.value = d[0]; l.setAttribute('title', CODES[d[0]]); l.append(c, document.createTextNode(d[1])); dayBoxes[d[0]] = c; days.appendChild(l);
    });
    var monthly = el('div', 'rep-month'), mDay = el('label', 'radio'), mNth = el('label', 'radio'), r1 = input('radio', { name: 'af-month' }), r2 = input('radio', { name: 'af-month' }), nthText = document.createTextNode('');
    r1.value = 'day'; r2.value = 'nth'; r1.checked = true; var dayText = document.createTextNode('');
    mDay.append(r1, dayText); mNth.append(r2, nthText); monthly.append(mDay, mNth);
    custom.append(every, days, monthly);
    node.appendChild(custom);

    var end = el('div', 'rep-end'), eSel = el('select'); eSel.setAttribute('aria-label', 'Fin de la répétition');
    [['never', 'Jamais'], ['until', 'Jusqu\'au'], ['count', 'Après un nombre d\'occurrences']].forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; eSel.appendChild(op); });
    var untilIn = input('date', { 'aria-label': 'Date de fin' }), countIn = input('number', { min: 1, max: 5000, value: 10, 'aria-label': 'Nombre d\'occurrences' });
    end.append(document.createTextNode('Fin : '), eSel, untilIn, countIn); node.appendChild(end);
    var err = el('p', 'fld-err'); err.id = 'af-rep-err'; err.hidden = true; node.appendChild(err);

    function nth() { var d = wd(), day = d.getUTCDate(), dim = new Date(Date.UTC(d.getUTCFullYear(), d.getUTCMonth() + 1, 0)).getUTCDate(); return day + 7 > dim ? -1 : Math.ceil(day / 7); }
    function refresh() {
      var f = fSel.value, isCustom = sel.value === 'custom';
      custom.hidden = !isCustom; days.hidden = f !== 'weekly'; monthly.hidden = f !== 'monthly';
      end.hidden = sel.value === 'none'; untilIn.hidden = eSel.value !== 'until'; countIn.hidden = eSel.value !== 'count';
      var d = wd(), n = nth();
      dayText.nodeValue = ' Le ' + d.getUTCDate() + ' du mois';
      nthText.nodeValue = ' Le ' + (n === -1 ? 'dernier' : n + 'ᵉ') + ' ' + DOW[d.getUTCDay()] + ' du mois';
      if (!untilIn.value) { var u = new Date(d.getTime() + 90 * DAY); untilIn.value = dateStr(u.getTime()); }
    }
    [sel, fSel, eSel].forEach(function (c) { c.addEventListener('change', refresh); });
    startDate.addEventListener('change', refresh);

    // état initial depuis une règle existante
    if (existing) {
      var simple = !existing.bymonth.length && existing.interval === 1;
      if (existing.freq === 'daily' && simple && !existing.byday.length) sel.value = 'daily';
      else if (existing.freq === 'weekly' && simple && existing.byday.length === 1 && existing.byday[0] === code()) sel.value = 'weekly';
      else if (existing.freq === 'weekly' && simple && existing.byday.join() === 'MO,TU,WE,TH,FR') sel.value = 'weekdays';
      else if (existing.freq === 'monthly' && simple && existing.bymonthday.length === 1 && existing.bymonthday[0] === wd().getUTCDate() && !existing.byday.length) sel.value = 'monthly';
      else if (existing.freq === 'yearly' && simple && !existing.byday.length && !existing.bymonthday.length) sel.value = 'yearly';
      else {
        sel.value = 'custom'; fSel.value = existing.freq; nIn.value = existing.interval;
        existing.byday.forEach(function (d) { if (dayBoxes[d]) dayBoxes[d].checked = true; });
        if (existing.freq === 'monthly' && existing.byday.length) r2.checked = true;
      }
      eSel.value = existing.end.type; if (existing.end.until) untilIn.value = existing.end.until; if (existing.end.count) countIn.value = existing.end.count;
    } else { sel.value = 'none'; }
    refresh();

    var api2 = { node: node, focusEl: sel, error: '' };
    api2.value = function () {
      err.hidden = true; var v = sel.value;
      if (v === 'none') return scope === 'all' ? null : undefined; // « aucune » : supprime la récurrence d'une série (portée « tous »)
      var r;
      if (v === 'daily') r = { freq: 'daily' };
      else if (v === 'weekly') r = { freq: 'weekly', byday: [code()] };
      else if (v === 'weekdays') r = { freq: 'weekly', byday: ['MO', 'TU', 'WE', 'TH', 'FR'] };
      else if (v === 'monthly') r = { freq: 'monthly', bymonthday: [wd().getUTCDate()] };
      else if (v === 'yearly') r = { freq: 'yearly' };
      else {
        var n = parseInt(nIn.value, 10);
        if (!(n >= 1 && n <= 999)) { api2.error = 'Intervalle invalide (1 à 999).'; return false; }
        r = { freq: fSel.value, interval: n };
        if (fSel.value === 'weekly') { r.byday = Object.keys(dayBoxes).filter(function (k) { return dayBoxes[k].checked; }); if (!r.byday.length) r.byday = [code()]; }
        if (fSel.value === 'monthly') { if (r2.checked) r.byday = [nth() + CODES_REV[wd().getUTCDay()]]; else r.bymonthday = [wd().getUTCDate()]; }
      }
      if (eSel.value === 'until') {
        if (!untilIn.value || untilIn.value < startDate.value) { api2.error = 'La date de fin doit être postérieure au début.'; return false; }
        r.end = { type: 'until', until: untilIn.value };
      } else if (eSel.value === 'count') {
        var c = parseInt(countIn.value, 10);
        if (!(c >= 1 && c <= 5000)) { api2.error = 'Nombre d\'occurrences : 1 à 5000.'; return false; }
        r.end = { type: 'count', count: c };
      } else r.end = { type: 'never' };
      if (existing && sameRule(r, existing)) return undefined; // inchangée : le serveur conserve la règle (et adapte COUNT lors d'une coupure)
      return r;
    };
    return api2;
  }
  function sameRule(a, b) {
    var c = function (r) { return JSON.stringify([r.freq, r.interval || 1, (r.byday || []).slice().sort(), r.bymonthday || [], r.bymonth || [], r.end && r.end.type, r.end && r.end.count || null, r.end && r.end.until || null]); };
    return c(a) === c(b);
  }
  var CODES_REV = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

  /** Minutes avant le rendez-vous : préréglage, valeur personnalisée (nombre × unité) ou null. */
  function reminderValue(c) {
    if (c.rem.value === '') return null;
    if (c.rem.value !== 'custom') return +c.rem.value;
    var n = parseInt(state.remCustom.n.value, 10), u = parseInt(state.remCustom.u.value, 10), m = n * u;
    return isFinite(m) && n >= 0 && m <= 40320 ? m : null;
  }

  function lastCalendar(cals) {
    var pref = window.RVM.ui && window.RVM.ui.default_calendar;
    if (pref && cals.some(function (c) { return c.id === pref; })) return pref;
    var last = null; try { last = +localStorage.getItem('rvm_last_calendar'); } catch (e) {}
    return cals.some(function (c) { return c.id === last; }) ? last : cals[0].id;
  }

  /** Un autre utilisateur a modifié (ou supprimé) le rendez-vous en cours d'édition : avertissement non bloquant. */
  function showRemoteChange(deleted) {
    var box = form.querySelector('#af-conflict'); if (!box) return;
    box.hidden = false; box.textContent = '';
    box.appendChild(el('strong', '', deleted ? 'Ce rendez-vous vient d\'être supprimé par un autre utilisateur. ' : 'Ce rendez-vous vient d\'être modifié par un autre utilisateur. '));
    if (!deleted) {
      var occ = state.occurrence, id = state.id;
      var b = el('button', 'btn', 'Recharger sa version'); b.type = 'button';
      b.addEventListener('click', function () { openForm({ id: id, occurrence: occ || undefined }); });
      box.append(b, document.createTextNode(' Vos modifications non enregistrées seraient perdues ; l\'enregistrement vous proposera aussi de choisir.'));
    }
  }

  function clearErrors() {
    form.querySelectorAll('.fld-err').forEach(function (p) { p.hidden = true; p.textContent = ''; });
    form.querySelectorAll('[aria-invalid]').forEach(function (c) { c.removeAttribute('aria-invalid'); });
    var s = form.querySelector('#af-summary'), c = form.querySelector('#af-conflict'); s.hidden = true; s.textContent = ''; c.hidden = true; c.textContent = '';
  }
  function fieldError(name, msgs) {
    var ctl = state.fields[name]; if (!ctl) return false;
    ctl.setAttribute('aria-invalid', 'true');
    var p = form.querySelector('#' + ctl.id + '-err'); p.textContent = msgs.join(' '); p.hidden = false; return true;
  }

  /** Construit la charge utile ; renvoie null (et affiche les erreurs) si la validation locale échoue. */
  function payload() {
    var c = state.ctl, errs = {};
    if (!c.title.value.trim()) errs.title = ['Le titre est requis.'];
    var allDay = c.ad.checked, s = parseWall(c.sd.value, allDay ? '00:00' : c.st.value), e = parseWall(c.ed.value, allDay ? '00:00' : c.et.value);
    if (c.rem.value === 'custom' && reminderValue(c) === null) errs.reminder_minutes = ['Durée de rappel invalide (4 semaines maximum).'];
    if (s === null) errs.start = ['Date de début requise.'];
    if (e === null) errs.end = ['Date de fin requise.'];
    if (s !== null && e !== null && (allDay ? e < s : e <= s)) errs.end = [allDay ? 'La fin ne peut pas précéder le début.' : 'La fin doit être postérieure au début.'];
    if (Object.keys(errs).length) { showFieldErrors(errs); return null; }
    var body = {
      calendar_id: +c.cal.value, title: c.title.value.trim(), description: c.desc.value.trim() || null, notes: c.notes.value.trim() || null,
      all_day: allDay, timezone: TZ, location: c.loc.value.trim() || null, address: c.adr.value.trim() || null,
      category_id: c.cat.value ? +c.cat.value : null, contact_id: c.con.value ? +c.con.value : null,
      status: c.stat.value, priority: c.prio.value, color: c.color.value || null, reminder_minutes: reminderValue(c)
    };
    if (allDay) { body.start = c.sd.value; body.end = dateStr(e + DAY); } // fin exclusive côté API
    else { body.start = isoUtc(unwall(s)); body.end = isoUtc(unwall(e)); }
    if (state.edit) body.version = state.version;
    if (state.series) { body.scope = state.scope; body.occurrence = state.occurrence; }
    if (state.rep) {
      var rc = state.rep.value();
      if (rc === false) { showFieldErrors({ recurrence: [state.rep.error] }); return null; }
      if (rc !== undefined) body.recurrence = rc;
    }
    return body;
  }

  function showFieldErrors(errs) {
    clearErrors();
    var first = null, general = [];
    Object.keys(errs).forEach(function (k) {
      var key = k === 'title' ? 'title' : k;
      if (fieldError(key, errs[k])) { if (!first) first = state.fields[key]; } else general.push(errs[k].join(' '));
    });
    var s = form.querySelector('#af-summary');
    s.textContent = 'Le formulaire contient des erreurs.' + (general.length ? ' ' + general.join(' ') : ''); s.hidden = false;
    if (first) first.focus();
  }

  function showError(r, retry) {
    var err = r.data && r.data.error ? r.data.error : {}, d = err.details || {};
    if (r.status === 422 && d && !d.code) { showFieldErrors(d); return; }
    clearErrors();
    var box = form.querySelector('#af-conflict'), s = form.querySelector('#af-summary');
    if (r.status === 409 && d.code === 'time_conflict') {
      box.hidden = false; box.appendChild(el('strong', '', 'Conflit horaire. '));
      box.appendChild(document.createTextNode('Ce créneau chevauche : ' + d.conflicts.map(function (c) {
        return '« ' + c.title + ' » (' + fmt({ day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(Date.parse(c.start)) + ')';
      }).join(', ') + '. '));
      if (retry) { var b = el('button', 'btn', 'Enregistrer quand même'); b.type = 'button'; b.addEventListener('click', function () { retry(true); }); box.appendChild(b); }
      return;
    }
    if (r.status === 409 && d.code === 'version_conflict') {
      box.hidden = false;
      box.appendChild(el('strong', '', d.current ? 'Modifié par quelqu\'un d\'autre. ' : 'Ce rendez-vous a été supprimé. '));
      if (d.current) {
        var reload = el('button', 'btn', 'Recharger sa version'); reload.type = 'button';
        reload.addEventListener('click', function () { cache = {}; calendars(); openForm({ id: d.current.id }); });
        var over = el('button', 'btn', 'Écraser avec mes modifications'); over.type = 'button';
        over.addEventListener('click', function () { state.version = d.current.version; save('close'); });
        box.append(reload, document.createTextNode(' '), over);
      }
      return;
    }
    s.textContent = err.message || 'Enregistrement impossible.'; s.hidden = false;
  }

  function save(mode, ignoreConflicts) {
    clearErrors();
    var body = payload(); if (!body) return;
    if (ignoreConflicts === true) body.ignore_conflicts = true;
    var buttons = form.querySelectorAll('.af-actions button'); buttons.forEach(function (b) { b.disabled = true; });
    var req = state.edit ? api('PUT', '/api/v1/appointments/' + state.id, body) : api('POST', '/api/v1/appointments', body);
    req.then(function (r) {
      buttons.forEach(function (b) { b.disabled = false; });
      if (!r.ok) { showError(r, function (ign) { save(mode, ign); }); if (r.status === 409) changed(); return; }
      try { localStorage.setItem('rvm_last_calendar', String(body.calendar_id)); } catch (e) {}
      toast(state.edit ? 'Rendez-vous enregistré.' : 'Rendez-vous créé.'); changed();
      if (mode === 'new') { var keep = { calendarId: body.calendar_id }; formDlg.close(); setTimeout(function () { openForm(keep); }, 0); }
      else formDlg.close();
    }).catch(function () { buttons.forEach(function (b) { b.disabled = false; }); toast('Serveur injoignable.', true); });
  }

  /** « Nouveau rendez-vous » : date courante du calendrier (ou aujourd'hui), prochaine heure pleine ou 9 h. */
  function openNew(extra) {
    var base = typeof window.RVM.calendarDate === 'function' ? window.RVM.calendarDate() : null, today = Math.floor(wall(Date.now()) / DAY) * DAY, start;
    if (base === null || base === today) start = Math.ceil(wall(Date.now()) / 3600000) * 3600000;
    else start = base + 9 * 3600000;
    openForm(Object.assign({ start: start, end: start + (((window.RVM.ui && window.RVM.ui.default_duration) || 60) * 60000) }, extra || {}));
  }

  window.RVM.appointments = { invalidate: function () { cache = {}; }, askScope: askScope, seriesBody: seriesBody, describeRule: describeRule, TZ: TZ, openForm: openForm, openNew: openNew, openDetails: openDetails, calendars: calendars, toast: toast,
    time: { wall: wall, unwall: unwall, dateStr: dateStr, timeStr: timeStr, isoUtc: isoUtc } };

  document.addEventListener('DOMContentLoaded', function () {
    var n = document.querySelector('.btn-new');
    if (n) n.addEventListener('click', function (e) { e.preventDefault(); openNew(); });
  });

  // ---- Page de liste (/appointments, /mine, /today, /upcoming) -----------------------------------------------------------------
  var page = document.getElementById('appt-list');
  if (!page) return;
  var scope = page.dataset.scope || 'all';
  var filters = document.getElementById('filters'), results = document.getElementById('list-results'), pager = document.getElementById('list-pager');
  var currentPage = 1, calList = [];

  function qsInit() {
    var q = new URLSearchParams(location.search).get('q');
    if (q) filters.elements.q.value = q;
  }

  function buildQuery() {
    var f = filters.elements, p = new URLSearchParams();
    if (f.q.value.trim()) p.set('q', f.q.value.trim());
    if (f.calendar.value) p.set('calendars', f.calendar.value);
    if (f.status.value) p.set('status', f.status.value);
    if (f.priority.value) p.set('priority', f.priority.value);
    if (f.category.value) p.set('category_id', f.category.value);
    if (f.contact.value) p.set('contact_id', f.contact.value);
    if (f.created_by.value) p.set('created_by', f.created_by.value);
    var from = f.from.value ? isoUtc(unwall(parseWall(f.from.value, '00:00'))) : null;
    var to = f.to.value ? isoUtc(unwall(parseWall(f.to.value, '00:00') + DAY)) : null;
    if (scope === 'mine') p.set('mine', '1');
    if (scope === 'today') {
      var d0 = Math.floor(wall(Date.now()) / DAY) * DAY; from = from || isoUtc(unwall(d0)); to = to || isoUtc(unwall(d0 + DAY));
    }
    if (scope === 'upcoming') { from = from || isoUtc(Date.now()); if (!f.status.value) p.set('status', 'scheduled,confirmed,tentative'); }
    if (from) p.set('from', from);
    if (to) p.set('to', to);
    if (f.trash.checked) p.set('deleted', '1');
    p.set('sort', f.sort.value); p.set('dir', f.dir.value); p.set('page', String(currentPage)); p.set('per_page', '25');
    return p.toString();
  }

  function load() {
    results.setAttribute('aria-busy', 'true');
    api('GET', '/api/v1/appointments?' + buildQuery()).then(function (r) {
      results.removeAttribute('aria-busy');
      if (!r.ok) { results.textContent = ''; results.appendChild(el('p', 'alert alert-error', (r.data && r.data.error && r.data.error.message) || 'Chargement impossible.')); pager.textContent = ''; return; }
      draw(r.data.data, r.data.meta);
    }).catch(function () { results.removeAttribute('aria-busy'); results.textContent = 'Serveur injoignable.'; });
  }

  function draw(rows, meta) {
    results.textContent = '';
    if (!rows.length) {
      results.appendChild(el('p', 'empty', filters.elements.trash.checked ? 'La corbeille est vide.' : 'Aucun rendez-vous ne correspond.'));
    } else {
      var wrap = el('div', 'table-wrap'), t = el('table', 'tbl'), thead = el('thead'), hr = el('tr');
      ['Quand', 'Titre', 'Calendrier', 'Statut', 'Priorité', 'Lieu'].forEach(function (h) { var th = el('th', '', h); th.scope = 'col'; hr.appendChild(th); });
      thead.appendChild(hr); t.appendChild(thead);
      var tb = el('tbody');
      rows.forEach(function (a) {
        var cal = calendarOf(calList, a.calendar_id), tr = el('tr', a.status === 'cancelled' ? 'cancelled' : '');
        var when = a.all_day ? fmt({ day: 'numeric', month: 'short', year: 'numeric' }, 'UTC').format(Date.parse(a.start)) + ' · journée'
          : fmt({ day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(Date.parse(a.start));
        tr.appendChild(el('td', 'nowrap', when));
        var td = el('td'), b = el('button', 'link', a.title); b.type = 'button';
        b.addEventListener('click', function () { openDetails(a, cal, b); });
        var dot = el('span', 'dot'); setColor(dot, a.color || (a.category && a.category.color) || (cal && cal.color));
        td.append(dot, b); if (a.recurring) { var rp = el('span', 'rec', ' ↻'); rp.title = 'Série récurrente'; td.appendChild(rp); } tr.appendChild(td);
        tr.appendChild(el('td', '', cal ? cal.name : ''));
        tr.appendChild(el('td', '', STATUS[a.status] || a.status));
        tr.appendChild(el('td', '', PRIORITY[a.priority] || a.priority));
        tr.appendChild(el('td', '', a.location || ''));
        tb.appendChild(tr);
      });
      t.appendChild(tb); wrap.appendChild(t); results.appendChild(wrap);
    }
    pager.textContent = '';
    pager.appendChild(el('span', 'muted', meta.total + ' résultat' + (meta.total > 1 ? 's' : '') + ' · page ' + meta.page + ' / ' + meta.pages));
    var prev = el('button', 'btn', 'Précédent'), next = el('button', 'btn', 'Suivant'); prev.type = next.type = 'button';
    prev.disabled = meta.page <= 1; next.disabled = meta.page >= meta.pages;
    prev.addEventListener('click', function () { currentPage--; load(); }); next.addEventListener('click', function () { currentPage++; load(); });
    pager.append(prev, next);
  }

  filters.addEventListener('submit', function (e) { e.preventDefault(); currentPage = 1; load(); });
  filters.addEventListener('reset', function () { setTimeout(function () { currentPage = 1; load(); }, 0); });
  ['status', 'priority', 'calendar', 'category', 'contact', 'created_by', 'sort', 'dir', 'trash'].forEach(function (n) { filters.elements[n].addEventListener('change', function () { currentPage = 1; load(); }); });
  document.addEventListener('rvm:appointments-changed', load);
  document.addEventListener('rvm:sync', function (e) { if (window.RVM.sync.touches(e.detail, ['appointment', 'calendar'])) load(); });
  function fillSelect(sel, items, labelKey) {
    items.forEach(function (x) { var o = el('option', '', x[labelKey]); o.value = x.id; sel.appendChild(o); });
  }
  // listes proposées par les filtres : catégories, contacts (les 100 premiers par nom) et créateurs des rendez-vous visibles
  api('GET', '/api/v1/categories').then(function (r) { if (r.ok) fillSelect(filters.elements.category, r.data.data, 'name'); });
  api('GET', '/api/v1/contacts?per_page=100&sort=name').then(function (r) { if (r.ok) fillSelect(filters.elements.contact, r.data.data.map(function (c) { return { id: c.id, name: c.name }; }), 'name'); });
  api('GET', '/api/v1/search/facets').then(function (r) { if (r.ok) fillSelect(filters.elements.created_by, r.data.data.creators, 'name'); });
  // recherche globale : les contacts qui correspondent sont signalés au-dessus des résultats
  (function () {
    var q = new URLSearchParams(location.search).get('q'), box = document.getElementById('search-contacts');
    if (!q || q.trim().length < 2 || !box) return;
    api('GET', '/api/v1/search?q=' + encodeURIComponent(q.trim())).then(function (r) {
      if (!r.ok || !r.data.data.contacts.total) return;
      var c = r.data.data.contacts; box.hidden = false; box.textContent = 'Contacts correspondants : ';
      c.items.slice(0, 5).forEach(function (x, i) { if (i) box.appendChild(document.createTextNode(', ')); var a = el('a', '', x.name); a.href = '/contacts?q=' + encodeURIComponent(q.trim()); box.appendChild(a); });
      if (c.total > 5) box.appendChild(document.createTextNode(' … (' + c.total + ' au total)'));
    });
  })();
  qsInit();
  calendars().then(function (cals) {
    calList = cals;
    cals.forEach(function (c) { var o = el('option', '', c.name); o.value = c.id; filters.elements.calendar.appendChild(o); });
    load();
    if (page.dataset.autoNew === '1') openNew();
  });
})();
