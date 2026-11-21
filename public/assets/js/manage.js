/* RendezVous Manager – gestion des contacts, catégories et calendriers (partage compris).
   Toute donnée affichée est insérée via textContent (aucun innerHTML). */
(function () {
  'use strict';

  var root = document.getElementById('manage');
  if (!root) return;
  var kind = root.dataset.kind, canWrite = root.dataset.canWrite === '1', canCreateAppt = root.dataset.canCreateAppointment === '1';
  var api = window.RVM.api, A = window.RVM.appointments, toast = A.toast;
  var toolbar = document.getElementById('manage-toolbar'), results = document.getElementById('manage-results'), pager = document.getElementById('manage-pager');

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function setColor(n, c) { n.style.setProperty('--c', /^#[0-9a-fA-F]{6}$/.test(c || '') ? c : '#64748b'); }
  function msg(r, fallback) { var e = r.data && r.data.error ? r.data.error : {}; return e.message || fallback; }

  // ---- Boîte de formulaire générique ---------------------------------------------------------------------------------
  /**
   * opts : { title, submitLabel, fields: [{name,label,type,value,required,maxlength,options,hint}], onSubmit(values) -> Promise<{ok,status,data}>, onDone(data) }
   * Les erreurs 422 sont affichées sous chaque champ.
   */
  function formDialog(opts) {
    var d = el('dialog', 'form-dialog manage-dialog'), f = el('form', 'af'), opener = document.activeElement; f.noValidate = true;
    var head = el('div', 'dlg-head'), h = el('h2', '', opts.title); h.id = 'mg-title'; d.setAttribute('aria-labelledby', 'mg-title');
    var x = el('button', 'icon-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); x.addEventListener('click', function () { d.close(); });
    head.append(h, x); f.appendChild(head);
    var summary = el('div', 'alert alert-error'); summary.setAttribute('role', 'alert'); summary.hidden = true; f.appendChild(summary);
    var ctl = {};
    opts.fields.forEach(function (fd, i) {
      var wrap = el('div', 'fld'), id = 'mg-f-' + i, l = el('label', '', fd.label), c; l.setAttribute('for', id);
      if (fd.type === 'textarea') { c = el('textarea'); c.rows = 3; }
      else if (fd.type === 'select') { c = el('select'); fd.options.forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; c.appendChild(op); }); }
      else { c = el('input'); c.type = fd.type || 'text'; }
      if (fd.maxlength) c.maxLength = fd.maxlength;
      c.id = id; c.value = fd.value === undefined || fd.value === null ? '' : fd.value; c.setAttribute('aria-describedby', id + '-err');
      if (fd.autocomplete) c.autocomplete = fd.autocomplete;
      var err = el('p', 'fld-err'); err.id = id + '-err'; err.hidden = true;
      wrap.append(l, c); if (fd.hint) wrap.appendChild(el('p', 'muted small', fd.hint)); wrap.appendChild(err);
      f.appendChild(wrap); ctl[fd.name] = { c: c, err: err };
    });
    var bar = el('div', 'af-actions'), ok = el('button', 'btn btn-primary', opts.submitLabel || 'Enregistrer'), no = el('button', 'btn', 'Annuler'); ok.type = 'submit'; no.type = 'button';
    no.addEventListener('click', function () { d.close(); }); bar.append(ok, no); f.appendChild(bar); d.appendChild(f); document.body.appendChild(d);
    function clear() { Object.keys(ctl).forEach(function (k) { ctl[k].err.hidden = true; ctl[k].c.removeAttribute('aria-invalid'); }); summary.hidden = true; }
    f.addEventListener('submit', function (e) {
      e.preventDefault(); clear();
      var v = {}; Object.keys(ctl).forEach(function (k) { v[k] = ctl[k].c.value; });
      for (var i = 0; i < opts.fields.length; i++) {
        var fd = opts.fields[i]; if (fd.required && !String(v[fd.name]).trim()) { ctl[fd.name].err.textContent = 'Champ requis.'; ctl[fd.name].err.hidden = false; ctl[fd.name].c.setAttribute('aria-invalid', 'true'); ctl[fd.name].c.focus(); return; }
      }
      ok.disabled = true;
      opts.onSubmit(v).then(function (r) {
        ok.disabled = false;
        if (r.ok) { d.close(); if (opts.onDone) opts.onDone(r.data && r.data.data); return; }
        var det = r.data && r.data.error && r.data.error.details, first = null, other = [];
        if (r.status === 422 && det && !det.code) {
          Object.keys(det).forEach(function (k) { if (ctl[k]) { ctl[k].err.textContent = det[k].join(' '); ctl[k].err.hidden = false; ctl[k].c.setAttribute('aria-invalid', 'true'); first = first || ctl[k].c; } else other.push(det[k].join(' ')); });
          summary.textContent = 'Le formulaire contient des erreurs. ' + other.join(' '); summary.hidden = false; if (first) first.focus(); return;
        }
        summary.textContent = msg(r, 'Enregistrement impossible.'); summary.hidden = false;
      }).catch(function () { ok.disabled = false; summary.textContent = 'Serveur injoignable.'; summary.hidden = false; });
    });
    d.addEventListener('close', function () { d.remove(); if (opener && document.contains(opener)) opener.focus(); });
    d.showModal(); var first = f.querySelector('input,textarea,select'); if (first) first.focus();
    return d;
  }

  function table(headers, rows, onRow) {
    var wrap = el('div', 'table-wrap'), t = el('table', 'tbl'), thead = el('thead'), hr = el('tr');
    headers.forEach(function (h) { var th = el('th', '', h); th.scope = 'col'; hr.appendChild(th); });
    thead.appendChild(hr); t.appendChild(thead);
    var tb = el('tbody'); rows.forEach(function (r) { var tr = el('tr'); onRow(tr, r); tb.appendChild(tr); }); t.appendChild(tb); wrap.appendChild(t); return wrap;
  }
  function actionCell(buttons) {
    var td = el('td', 'row-actions');
    buttons.forEach(function (b) { if (!b) return; var btn = el('button', 'btn btn-sm' + (b.danger ? ' btn-danger' : ''), b.label); btn.type = 'button'; btn.setAttribute('aria-label', b.label + ' : ' + b.target); btn.addEventListener('click', b.fn); td.appendChild(btn); });
    return td;
  }
  function empty(text) { results.textContent = ''; results.appendChild(el('p', 'empty', text)); }

  // =========================================================== CONTACTS ============================================
  function contacts() {
    var q = (new URLSearchParams(location.search).get('q') || '').slice(0, 200), page = 1, sort = 'name', dir = 'asc';
    var form = el('form', 'search-inline'); form.setAttribute('role', 'search');
    var qi = el('input'); qi.type = 'search'; qi.placeholder = 'Rechercher un contact…'; qi.maxLength = 200; qi.setAttribute('aria-label', 'Rechercher un contact'); qi.value = q;
    var sb = el('button', 'btn', 'Rechercher'); sb.type = 'submit'; form.append(qi, sb); toolbar.appendChild(form);
    form.addEventListener('submit', function (e) { e.preventDefault(); q = qi.value.trim(); page = 1; load(); });
    if (canWrite) { var nb = el('button', 'btn btn-primary', 'Nouveau contact'); nb.type = 'button'; nb.addEventListener('click', function () { edit(null); }); toolbar.appendChild(nb); }

    function fields(c) {
      c = c || {};
      return [
        { name: 'last_name', label: 'Nom', required: true, maxlength: 80, value: c.last_name }, { name: 'first_name', label: 'Prénom', maxlength: 80, value: c.first_name },
        { name: 'company', label: 'Société', maxlength: 120, value: c.company }, { name: 'phone', label: 'Téléphone', type: 'tel', maxlength: 40, value: c.phone },
        { name: 'email', label: 'Adresse e-mail', type: 'email', maxlength: 190, value: c.email }, { name: 'address', label: 'Adresse', maxlength: 255, value: c.address },
        { name: 'notes', label: 'Notes', type: 'textarea', maxlength: 10000, value: c.notes }
      ];
    }
    function edit(c) {
      formDialog({ title: c ? 'Modifier le contact' : 'Nouveau contact', fields: fields(c),
        onSubmit: function (v) { return c ? api('PUT', '/api/v1/contacts/' + c.id, v) : api('POST', '/api/v1/contacts', v); },
        onDone: function () { toast(c ? 'Contact enregistré.' : 'Contact créé.'); load(); } });
    }
    function remove(c) {
      if (!window.confirm('Supprimer le contact « ' + c.name + ' » ? Ses rendez-vous conservent son nom.')) return;
      api('DELETE', '/api/v1/contacts/' + c.id).then(function (r) { if (r.ok) { toast('Contact supprimé.'); load(); } else toast(msg(r, 'Suppression impossible.'), true); });
    }

    var dlg = el('dialog', 'ev-dialog'); dlg.setAttribute('aria-labelledby', 'ct-title'); document.body.appendChild(dlg);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    function details(c, opener) {
      dlg.textContent = '';
      var head = el('div', 'dlg-head'), h = el('h2', '', c.name); h.id = 'ct-title'; var x = el('button', 'icon-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); x.addEventListener('click', function () { dlg.close(); });
      head.append(h, x); dlg.appendChild(head);
      var dl = el('dl', 'kv');
      [['Société', c.company], ['Téléphone', c.phone], ['E-mail', c.email], ['Adresse', c.address]].forEach(function (p) { if (p[1]) { dl.appendChild(el('dt', '', p[0])); dl.appendChild(el('dd', '', p[1])); } });
      dlg.appendChild(dl); if (c.notes) dlg.appendChild(el('p', 'desc', c.notes));
      dlg.appendChild(el('h3', 'sub-h', 'Rendez-vous liés'));
      var list = el('div', 'linked'); list.appendChild(el('p', 'muted small', 'Chargement…')); dlg.appendChild(list);
      var bar = el('div', 'dlg-actions');
      if (canCreateAppt) { var na = el('button', 'btn btn-primary', 'Nouveau rendez-vous'); na.type = 'button'; na.addEventListener('click', function () { dlg.close(); A.openNew({ contactId: c.id }); }); bar.appendChild(na); }
      dlg.appendChild(bar);
      Promise.all([api('GET', '/api/v1/contacts/' + c.id + '/appointments'), A.calendars()]).then(function (res) {
        var r = res[0], cals = res[1]; list.textContent = '';
        if (!r.ok) { list.appendChild(el('p', 'alert alert-error', msg(r, 'Chargement impossible.'))); return; }
        if (!r.data.data.length) { list.appendChild(el('p', 'muted', 'Aucun rendez-vous lié.')); return; }
        r.data.data.forEach(function (a) {
          var row = el('button', 'link-row'); row.type = 'button';
          var when = a.all_day ? new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(Date.parse(a.start))
            : new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: A.TZ }).format(Date.parse(a.start));
          row.append(el('span', 'when', when), el('span', 'tt', a.title));
          row.addEventListener('click', function () { dlg.close(); A.openDetails(a, cals.filter(function (k) { return k.id === a.calendar_id; })[0], opener); });
          list.appendChild(row);
        });
        if (r.data.meta.total > r.data.data.length) list.appendChild(el('p', 'muted small', r.data.meta.total + ' rendez-vous au total (les 25 plus récents sont affichés).'));
      });
      dlg.showModal(); x.focus();
    }

    function load() {
      results.setAttribute('aria-busy', 'true');
      api('GET', '/api/v1/contacts?' + new URLSearchParams({ q: q, page: page, per_page: 25, sort: sort, dir: dir })).then(function (r) {
        results.removeAttribute('aria-busy');
        if (!r.ok) { empty(msg(r, 'Chargement impossible.')); pager.textContent = ''; return; }
        var rows = r.data.data, meta = r.data.meta;
        if (!rows.length) empty(q ? 'Aucun contact ne correspond.' : 'Aucun contact pour l\'instant.' + (canWrite ? ' Utilisez « Nouveau contact ».' : ''));
        else {
          results.textContent = '';
          results.appendChild(table(['Nom', 'Société', 'Téléphone', 'E-mail', ''], rows, function (tr, c) {
            var td = el('td'), b = el('button', 'link', c.name); b.type = 'button'; b.addEventListener('click', function () { details(c, b); }); td.appendChild(b); tr.appendChild(td);
            tr.appendChild(el('td', '', c.company || '')); tr.appendChild(el('td', '', c.phone || '')); tr.appendChild(el('td', '', c.email || ''));
            tr.appendChild(actionCell(canWrite ? [{ label: 'Modifier', target: c.name, fn: function () { edit(c); } }, { label: 'Supprimer', target: c.name, danger: true, fn: function () { remove(c); } }] : []));
          }));
        }
        pager.textContent = ''; pager.appendChild(el('span', 'muted', meta.total + ' contact' + (meta.total > 1 ? 's' : '') + ' · page ' + meta.page + ' / ' + meta.pages));
        var pv = el('button', 'btn', 'Précédent'), nx = el('button', 'btn', 'Suivant'); pv.type = nx.type = 'button'; pv.disabled = meta.page <= 1; nx.disabled = meta.page >= meta.pages;
        pv.addEventListener('click', function () { page--; load(); }); nx.addEventListener('click', function () { page++; load(); }); pager.append(pv, nx);
      });
    }
    load();
    document.addEventListener('rvm:sync', function (e) { if (window.RVM.sync.touches(e.detail, ['contact'])) load(); });
  }

  // =========================================================== CATÉGORIES ==========================================
  function categories() {
    var manage = false;
    function edit(c) {
      formDialog({ title: c ? 'Modifier la catégorie' : 'Nouvelle catégorie',
        fields: [{ name: 'name', label: 'Nom', required: true, maxlength: 80, value: c ? c.name : '' }, { name: 'color', label: 'Couleur', type: 'color', value: c ? c.color : '#2563eb' }],
        onSubmit: function (v) { return c ? api('PUT', '/api/v1/categories/' + c.id, v) : api('POST', '/api/v1/categories', v); },
        onDone: function () { toast(c ? 'Catégorie enregistrée.' : 'Catégorie créée.'); load(); } });
    }
    function remove(c) {
      var n = c.appointment_count ? ' ' + c.appointment_count + ' rendez-vous visibles seront détachés de cette catégorie.' : '';
      if (!window.confirm('Supprimer la catégorie « ' + c.name + ' » ?' + n)) return;
      api('DELETE', '/api/v1/categories/' + c.id).then(function (r) { if (r.ok) { toast('Catégorie supprimée.'); load(); } else toast(msg(r, 'Suppression impossible.'), true); });
    }
    function load() {
      api('GET', '/api/v1/categories').then(function (r) {
        if (!r.ok) { empty(msg(r, 'Chargement impossible.')); return; }
        manage = !!r.data.meta.can_manage; toolbar.textContent = '';
        if (manage) { var nb = el('button', 'btn btn-primary', 'Nouvelle catégorie'); nb.type = 'button'; nb.addEventListener('click', function () { edit(null); }); toolbar.appendChild(nb); }
        else toolbar.appendChild(el('p', 'muted small', 'Les catégories sont gérées par les responsables et administrateurs.'));
        var rows = r.data.data;
        if (!rows.length) { empty('Aucune catégorie pour l\'instant.'); return; }
        results.textContent = '';
        results.appendChild(table(['Catégorie', 'Rendez-vous', ''], rows, function (tr, c) {
          var td = el('td'), sw = el('span', 'swatch-lg'); setColor(sw, c.color); td.append(sw, document.createTextNode(c.name)); tr.appendChild(td);
          tr.appendChild(el('td', '', String(c.appointment_count)));
          tr.appendChild(actionCell(manage ? [{ label: 'Modifier', target: c.name, fn: function () { edit(c); } }, { label: 'Supprimer', target: c.name, danger: true, fn: function () { remove(c); } }] : []));
        }));
      });
    }
    load();
    document.addEventListener('rvm:sync', function (e) { if (window.RVM.sync.touches(e.detail, ['category', 'appointment'])) load(); });
  }

  // =========================================================== CALENDRIERS =========================================
  var KINDS = [['personal', 'Personnel'], ['professional', 'Professionnel'], ['team', 'Équipe'], ['training', 'Formation'], ['administrative', 'Administratif'], ['other', 'Autre']];
  var VIS = [['private', 'Privé (moi et les personnes avec qui je partage)'], ['shared', 'Partagé (uniquement sur invitation)'], ['organization', 'Organisation (lecture pour tous les comptes)']];
  var KIND_LABEL = {}; KINDS.forEach(function (k) { KIND_LABEL[k[0]] = k[1]; });
  var VIS_LABEL = { private: 'Privé', shared: 'Partagé', organization: 'Organisation' };

  function calendars() {
    function zones() {
      var list = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['Europe/Paris', 'UTC'];
      if (list.indexOf('UTC') < 0) list = list.concat(['UTC']);
      return list.map(function (z) { return [z, z]; });
    }
    function edit(c) {
      formDialog({ title: c ? 'Modifier le calendrier' : 'Nouveau calendrier',
        fields: [
          { name: 'name', label: 'Nom', required: true, maxlength: 120, value: c ? c.name : '' },
          { name: 'description', label: 'Description', type: 'textarea', maxlength: 2000, value: c ? c.description : '' },
          { name: 'color', label: 'Couleur', type: 'color', value: c ? c.color : '#2563eb' },
          { name: 'kind', label: 'Type', type: 'select', options: KINDS, value: c ? c.kind : 'personal' },
          { name: 'visibility', label: 'Visibilité', type: 'select', options: VIS, value: c ? c.visibility : 'private' },
          { name: 'timezone', label: 'Fuseau horaire', type: 'select', options: zones(), value: c ? c.timezone : A.TZ }
        ],
        onSubmit: function (v) { if (c) v.version = c.version; return c ? api('PUT', '/api/v1/calendars/' + c.id, v) : api('POST', '/api/v1/calendars', v); },
        onDone: function () { toast(c ? 'Calendrier enregistré.' : 'Calendrier créé.'); window.RVM.appointments.invalidate && window.RVM.appointments.invalidate(); load(); } });
    }
    function remove(c) {
      if (!window.confirm('Supprimer le calendrier « ' + c.name + ' » ? Ses rendez-vous ne seront plus visibles.')) return;
      api('DELETE', '/api/v1/calendars/' + c.id).then(function (r) { if (r.ok) { toast('Calendrier supprimé.'); load(); } else toast(msg(r, 'Suppression impossible.'), true); });
    }

    // ---- partage ----
    function share(c) {
      var d = el('dialog', 'form-dialog manage-dialog'), opener = document.activeElement; d.setAttribute('aria-labelledby', 'sh-title');
      var f = el('form', 'af'); f.noValidate = true;
      var head = el('div', 'dlg-head'), h = el('h2', '', 'Partager « ' + c.name + ' »'); h.id = 'sh-title'; var x = el('button', 'icon-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); x.addEventListener('click', function () { d.close(); });
      head.append(h, x); f.appendChild(head);
      var summary = el('div', 'alert alert-error'); summary.setAttribute('role', 'alert'); summary.hidden = true; f.appendChild(summary);
      var info = el('p', 'muted small', 'Chargement…'); f.appendChild(info);
      var grants = []; var holder = el('div', 'share-list'); f.appendChild(holder);
      var FLAGS = [['can_read', 'Lire'], ['can_create', 'Créer'], ['can_update', 'Modifier'], ['can_delete', 'Supprimer'], ['can_share', 'Partager']];

      function draw() {
        holder.textContent = '';
        if (!grants.length) { holder.appendChild(el('p', 'muted', 'Aucun partage individuel.')); return; }
        var t = el('table', 'tbl share-tbl'), tb = el('tbody');
        var thr = el('tr'); thr.appendChild(el('th', '', 'Personne')); FLAGS.forEach(function (fl) { thr.appendChild(el('th', '', fl[1])); }); thr.appendChild(el('th', 'sr-only', 'Retirer'));
        var th = el('thead'); th.appendChild(thr); t.appendChild(th);
        grants.forEach(function (g) {
          var tr = el('tr'); tr.appendChild(el('td', '', g.name + ' (' + g.username + ')'));
          FLAGS.forEach(function (fl) {
            var td = el('td'), cb = el('input'); cb.type = 'checkbox'; cb.checked = !!g[fl[0]]; cb.setAttribute('aria-label', fl[1] + ' — ' + g.name);
            cb.addEventListener('change', function () { g[fl[0]] = cb.checked; if (cb.checked && fl[0] !== 'can_read') { g.can_read = true; draw(); } if (!cb.checked && fl[0] === 'can_read') { FLAGS.forEach(function (z) { g[z[0]] = false; }); draw(); } });
            td.appendChild(cb); tr.appendChild(td);
          });
          var rm = el('td'), b = el('button', 'btn btn-sm', 'Retirer'); b.type = 'button'; b.setAttribute('aria-label', 'Retirer ' + g.name);
          b.addEventListener('click', function () { grants = grants.filter(function (z) { return z !== g; }); draw(); }); rm.appendChild(b); tr.appendChild(rm); tb.appendChild(tr);
        });
        t.appendChild(tb); var w = el('div', 'table-wrap'); w.appendChild(t); holder.appendChild(w);
      }

      var addBox = el('div', 'fld'), al = el('label', '', 'Ajouter une personne'); al.setAttribute('for', 'sh-q');
      var qi = el('input'); qi.id = 'sh-q'; qi.type = 'search'; qi.placeholder = 'Nom ou identifiant (2 caractères minimum)'; qi.autocomplete = 'off'; qi.maxLength = 100;
      var res = el('div', 'lookup'); res.setAttribute('role', 'listbox'); res.setAttribute('aria-label', 'Résultats'); addBox.append(al, qi, res); f.appendChild(addBox);
      var timer = null, last = '';
      qi.addEventListener('input', function () {
        clearTimeout(timer); var v = qi.value.trim(); res.textContent = '';
        if (v.length < 2) return;
        timer = setTimeout(function () {
          last = v;
          api('GET', '/api/v1/users/lookup?q=' + encodeURIComponent(v)).then(function (r) {
            if (v !== last) return; res.textContent = '';
            if (!r.ok) { res.appendChild(el('p', 'muted small', msg(r, 'Recherche impossible.'))); return; }
            var found = r.data.data.filter(function (u) { return !grants.some(function (g) { return g.user_id === u.id; }); });
            if (!found.length) { res.appendChild(el('p', 'muted small', 'Aucun résultat.')); return; }
            found.forEach(function (u) {
              var b = el('button', 'link-row', u.name + ' (' + u.username + ')'); b.type = 'button'; b.setAttribute('role', 'option');
              b.addEventListener('click', function () { grants.push({ user_id: u.id, name: u.name, username: u.username, can_read: true, can_create: false, can_update: false, can_delete: false, can_share: false }); qi.value = ''; res.textContent = ''; draw(); });
              res.appendChild(b);
            });
          });
        }, 250);
      });

      var bar = el('div', 'af-actions'), ok = el('button', 'btn btn-primary', 'Enregistrer'), no = el('button', 'btn', 'Fermer'); ok.type = 'submit'; no.type = 'button';
      no.addEventListener('click', function () { d.close(); }); bar.append(ok, no); f.appendChild(bar); d.appendChild(f); document.body.appendChild(d);
      f.addEventListener('submit', function (e) {
        e.preventDefault(); ok.disabled = true; summary.hidden = true;
        api('PUT', '/api/v1/calendars/' + c.id + '/permissions', { permissions: grants.map(function (g) { var o = { user_id: g.user_id }; FLAGS.forEach(function (fl) { o[fl[0]] = !!g[fl[0]]; }); return o; }) }).then(function (r) {
          ok.disabled = false;
          if (r.ok) { toast('Partage enregistré.'); d.close(); return; }
          var det = r.data && r.data.error && r.data.error.details; summary.textContent = (det && det.permissions ? det.permissions.join(' ') : msg(r, 'Enregistrement impossible.')); summary.hidden = false;
        });
      });
      d.addEventListener('close', function () { d.remove(); if (opener && document.contains(opener)) opener.focus(); });
      d.showModal(); qi.focus();
      api('GET', '/api/v1/calendars/' + c.id + '/permissions').then(function (r) {
        if (!r.ok) { info.textContent = msg(r, 'Chargement impossible.'); ok.disabled = true; return; }
        info.textContent = 'Propriétaire : ' + r.data.meta.owner + (r.data.meta.visibility === 'organization' ? ' · visible en lecture par tous les comptes' : '') + '. Lire est automatiquement accordé avec tout autre droit.';
        grants = r.data.data; draw();
      });
    }

    function load() {
      api('GET', '/api/v1/calendars').then(function (r) {
        if (!r.ok) { empty(msg(r, 'Chargement impossible.')); return; }
        toolbar.textContent = '';
        if (r.data.meta.can_create) { var nb = el('button', 'btn btn-primary', 'Nouveau calendrier'); nb.type = 'button'; nb.addEventListener('click', function () { edit(null); }); toolbar.appendChild(nb); }
        var rows = r.data.data;
        if (!rows.length) { empty('Aucun calendrier accessible.' + (r.data.meta.can_create ? ' Créez-en un avec « Nouveau calendrier ».' : '')); return; }
        results.textContent = '';
        results.appendChild(table(['Calendrier', 'Type', 'Visibilité', 'Propriétaire', ''], rows, function (tr, c) {
          var td = el('td'), sw = el('span', 'swatch-lg'); setColor(sw, c.color); td.append(sw, document.createTextNode(c.name)); if (c.description) td.appendChild(el('div', 'muted small', c.description)); tr.appendChild(td);
          tr.appendChild(el('td', '', KIND_LABEL[c.kind] || c.kind)); tr.appendChild(el('td', '', VIS_LABEL[c.visibility] || c.visibility)); tr.appendChild(el('td', '', c.mine ? 'Moi' : c.owner));
          tr.appendChild(actionCell([
            c.can.manage ? { label: 'Modifier', target: c.name, fn: function () { edit(c); } } : null,
            (c.can.share || c.can.manage) ? { label: 'Partager', target: c.name, fn: function () { share(c); } } : null,
            c.can.manage ? { label: 'Supprimer', target: c.name, danger: true, fn: function () { remove(c); } } : null
          ]));
        }));
      });
    }
    load();
    document.addEventListener('rvm:sync', function (e) { if (window.RVM.sync.touches(e.detail, ['calendar'])) load(); });
  }

  // =========================================================== NOTIFICATIONS =======================================
  function notifications() {
    var tab = 'unread', page = 1, TABS = [['unread', 'Non lues'], ['read', 'Lues'], ['upcoming', 'À venir'], ['all', 'Historique']];
    var fmt = new Intl.DateTimeFormat('fr-FR', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: A.TZ });
    var tabs = el('div', 'tabs'); tabs.setAttribute('role', 'tablist'); toolbar.appendChild(tabs);
    var allBtn = el('button', 'btn', 'Tout marquer comme lu'); allBtn.type = 'button'; toolbar.appendChild(allBtn);
    var tabBtns = {};
    TABS.forEach(function (t) {
      var b = el('button', '', t[1]); b.type = 'button'; b.setAttribute('role', 'tab'); b.setAttribute('aria-selected', String(t[0] === tab));
      b.addEventListener('click', function () { tab = t[0]; page = 1; Object.keys(tabBtns).forEach(function (k) { tabBtns[k].setAttribute('aria-selected', String(k === tab)); }); load(); });
      tabBtns[t[0]] = b; tabs.appendChild(b);
    });
    allBtn.addEventListener('click', function () {
      api('POST', '/api/v1/notifications/read-all').then(function (r) { if (r.ok) { window.RVM.setBadge(0); toast(r.data.updated ? 'Toutes les notifications sont marquées comme lues.' : 'Aucune notification non lue.'); load(); } });
    });

    function open(n) {
      Promise.all([api('GET', '/api/v1/appointments/' + n.appointment_id + (n.occurrence_start ? '?occurrence=' + encodeURIComponent(n.occurrence_start) : '')), A.calendars()]).then(function (res) {
        var r = res[0];
        if (!r.ok) { toast('Ce rendez-vous n\'existe plus ou n\'est plus accessible.', true); return; }
        A.openDetails(r.data.data, res[1].filter(function (c) { return c.id === r.data.data.calendar_id; })[0], document.activeElement);
        if (n.id && !n.read) api('POST', '/api/v1/notifications/' + n.id + '/read').then(function (x) { if (x.ok) { window.RVM.setBadge(x.data.unread); load(); } });
      });
    }
    function card(n, upcoming) {
      var d = el('div', 'notif' + (!upcoming && !n.read ? ' unread' : '')), main = el('div', 'main');
      main.append(el('span', 'tt', n.title), el('span', '', n.body || ''));
      main.appendChild(el('span', 'when', upcoming ? 'Déclenchement le ' + fmt.format(Date.parse(n.trigger_at)) : 'Reçue le ' + fmt.format(Date.parse(n.sent_at)) + (n.read ? ' · lue' : '')));
      var btns = el('div', 'btns');
      if (upcoming || n.appointment_exists) { var v = el('button', 'btn btn-sm', 'Voir le rendez-vous'); v.type = 'button'; v.addEventListener('click', function () { open(upcoming ? { appointment_id: n.appointment_id, occurrence_start: n.occurrence_start } : n); }); btns.appendChild(v); }
      if (!upcoming) {
        var m = el('button', 'btn btn-sm', n.read ? 'Marquer comme non lue' : 'Marquer comme lue'); m.type = 'button';
        m.addEventListener('click', function () { api('POST', '/api/v1/notifications/' + n.id + (n.read ? '/unread' : '/read')).then(function (r) { if (r.ok) { window.RVM.setBadge(r.data.unread); load(); } else toast(msg(r, 'Action impossible.'), true); }); });
        btns.appendChild(m);
      }
      d.append(main, btns); return d;
    }
    function load() {
      allBtn.hidden = tab === 'upcoming' || tab === 'read';
      results.setAttribute('aria-busy', 'true');
      var req = tab === 'upcoming' ? api('GET', '/api/v1/notifications/upcoming') : api('GET', '/api/v1/notifications?' + new URLSearchParams({ filter: tab, page: page, per_page: 20 }));
      req.then(function (r) {
        results.removeAttribute('aria-busy'); results.textContent = ''; pager.textContent = '';
        if (!r.ok) { empty(msg(r, 'Chargement impossible.')); return; }
        var rows = r.data.data;
        if (!rows.length) {
          empty({ unread: 'Aucune notification non lue.', read: 'Aucune notification lue.', upcoming: 'Aucun rappel prévu dans les 7 prochains jours. Ajoutez un rappel à un rendez-vous pour en recevoir.', all: 'Aucune notification pour l\'instant.' }[tab]);
        } else rows.forEach(function (n) { results.appendChild(card(n, tab === 'upcoming')); });
        if (r.data.meta) {
          window.RVM.setBadge(r.data.meta.unread);
          var meta = r.data.meta; pager.appendChild(el('span', 'muted', meta.total + ' notification' + (meta.total > 1 ? 's' : '') + ' · page ' + meta.page + ' / ' + meta.pages));
          var pv = el('button', 'btn', 'Précédent'), nx = el('button', 'btn', 'Suivant'); pv.type = nx.type = 'button'; pv.disabled = meta.page <= 1; nx.disabled = meta.page >= meta.pages;
          pv.addEventListener('click', function () { page--; load(); }); nx.addEventListener('click', function () { page++; load(); }); pager.append(pv, nx);
        }
      });
    }
    load();
    document.addEventListener('rvm:sync', function (e) { if (window.RVM.sync.touches(e.detail, ['notification', 'appointment'])) load(); });
  }

  ({ contacts: contacts, categories: categories, calendars: calendars, notifications: notifications }[kind] || function () {})();
})();
