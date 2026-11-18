// Administration des utilisateurs : liste, création, modification, activation, réinitialisation du mot de passe, déverrouillage, déconnexion forcée.
// Tout est rendu avec textContent. Un mot de passe provisoire n'est affiché qu'une fois, dans une boîte dédiée.
(function () {
  'use strict';
  var root = document.getElementById('users');
  if (!root) return;
  var api = window.RVM.api, me = parseInt(root.dataset.me, 10);
  var results = document.getElementById('users-results'), pager = document.getElementById('users-pager');
  var roles = [], state = { q: '', role: '', active: '', page: 1, sort: 'name', dir: 'asc' };
  var ROLE_FR = { admin: 'Administrateur', manager: 'Responsable', user: 'Utilisateur', readonly: 'Lecture seule' };
  var fmt = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'short' });

  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function msg(r, fb) { var e = r.data && r.data.error ? r.data.error : {}; return e.message || fb; }
  function toast(t, bad) { window.RVM.appointments.toast(t, bad); }

  function formDialog(opts) {
    var d = el('dialog', 'form-dialog manage-dialog'), f = el('form', 'af'), opener = document.activeElement; f.noValidate = true;
    var head = el('div', 'dlg-head'), h = el('h2', '', opts.title); h.id = 'us-title'; d.setAttribute('aria-labelledby', 'us-title');
    var x = el('button', 'icon-btn', '×'); x.type = 'button'; x.setAttribute('aria-label', 'Fermer'); x.addEventListener('click', function () { d.close(); });
    head.append(h, x); f.appendChild(head);
    var summary = el('div', 'alert alert-error'); summary.setAttribute('role', 'alert'); summary.hidden = true; f.appendChild(summary);
    var ctl = {};
    opts.fields.forEach(function (fd, i) {
      var wrap = el('div', 'fld'), id = 'us-f-' + i, l = el('label', '', fd.label), c; l.setAttribute('for', id);
      if (fd.type === 'select') { c = el('select'); fd.options.forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; c.appendChild(op); }); }
      else { c = el('input'); c.type = fd.type || 'text'; }
      if (fd.maxlength) c.maxLength = fd.maxlength;
      if (fd.autocomplete) c.autocomplete = fd.autocomplete;
      if (fd.disabled) c.disabled = true;
      c.id = id; c.value = fd.value === undefined || fd.value === null ? '' : fd.value; c.setAttribute('aria-describedby', id + '-err');
      var err = el('p', 'fld-err'); err.id = id + '-err'; err.hidden = true;
      wrap.append(l, c); if (fd.hint) wrap.appendChild(el('p', 'muted small', fd.hint)); wrap.appendChild(err);
      f.appendChild(wrap); ctl[fd.name] = { c: c, err: err, fd: fd };
    });
    var bar = el('div', 'af-actions'), ok = el('button', 'btn btn-primary', opts.submitLabel || 'Enregistrer'), no = el('button', 'btn', 'Annuler'); ok.type = 'submit'; no.type = 'button';
    no.addEventListener('click', function () { d.close(); }); bar.append(ok, no); f.appendChild(bar); d.appendChild(f); document.body.appendChild(d);
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      Object.keys(ctl).forEach(function (k) { ctl[k].err.hidden = true; ctl[k].c.removeAttribute('aria-invalid'); }); summary.hidden = true;
      var v = {};
      for (var k in ctl) {
        if (ctl[k].fd.disabled) continue;
        v[k] = ctl[k].c.value;
        if (ctl[k].fd.required && !v[k].trim()) { ctl[k].err.textContent = 'Champ requis.'; ctl[k].err.hidden = false; ctl[k].c.setAttribute('aria-invalid', 'true'); ctl[k].c.focus(); return; }
      }
      ok.disabled = true;
      opts.onSubmit(v).then(function (r) {
        ok.disabled = false;
        if (r.ok) { d.close(); opts.onDone(r.data); return; }
        var det = r.data && r.data.error && r.data.error.details, first = null, other = [];
        if (r.status === 422 && det) {
          Object.keys(det).forEach(function (k) { if (ctl[k]) { ctl[k].err.textContent = det[k].join(' '); ctl[k].err.hidden = false; ctl[k].c.setAttribute('aria-invalid', 'true'); first = first || ctl[k].c; } else other.push(det[k].join(' ')); });
          summary.textContent = 'Le formulaire contient des erreurs. ' + other.join(' '); summary.hidden = false; if (first) first.focus(); return;
        }
        summary.textContent = msg(r, 'Enregistrement impossible.'); summary.hidden = false;
      }).catch(function () { ok.disabled = false; summary.textContent = 'Serveur injoignable.'; summary.hidden = false; });
    });
    d.addEventListener('close', function () { d.remove(); if (opener && document.contains(opener)) opener.focus(); });
    d.showModal(); var first = f.querySelector('input:not([disabled]),select:not([disabled])'); if (first) first.focus();
  }

  /** Boîte d'affichage unique d'un mot de passe provisoire. */
  function secretDialog(user, pw, title) {
    var d = el('dialog', 'form-dialog manage-dialog'), opener = document.activeElement, box = el('div', 'af');
    d.setAttribute('aria-labelledby', 'us-secret'); var h = el('h2', '', title); h.id = 'us-secret';
    box.append(h, el('p', '', 'Mot de passe provisoire de ' + user.first_name + ' ' + user.last_name + ' (' + user.username + ') :'));
    var code = el('p', 'secret', pw); code.setAttribute('tabindex', '0'); box.appendChild(code);
    box.appendChild(el('p', 'muted small', 'Il ne sera plus affiché. Transmettez-le par un canal sûr : la personne devra le remplacer dès sa première connexion.'));
    var bar = el('div', 'af-actions'), cp = el('button', 'btn', 'Copier'), ok = el('button', 'btn btn-primary', 'Fermer'); cp.type = ok.type = 'button';
    cp.addEventListener('click', function () {
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(pw).then(function () { cp.textContent = 'Copié'; }, function () { cp.textContent = 'Copie impossible'; });
      else { var r = document.createRange(); r.selectNodeContents(code); var s = getSelection(); s.removeAllRanges(); s.addRange(r); cp.textContent = 'Sélectionné : Ctrl+C'; }
    });
    ok.addEventListener('click', function () { d.close(); }); bar.append(cp, ok); box.appendChild(bar); d.appendChild(box); document.body.appendChild(d);
    d.addEventListener('close', function () { d.remove(); if (opener && document.contains(opener)) opener.focus(); });
    d.showModal(); code.focus();
  }

  function roleOptions() { return roles.map(function (r) { return [r.code, r.label]; }); }
  var TZ = (document.querySelector('meta[name="user-tz"]') || {}).content || 'Europe/Paris';

  function edit(u) {
    var self = u && u.id === me;
    var fields = [
      { name: 'username', label: 'Identifiant', required: true, maxlength: 60, value: u ? u.username : '', disabled: !!u, autocomplete: 'off', hint: u ? 'L\'identifiant ne peut pas être modifié.' : '3 à 60 caractères : lettres, chiffres, point, tiret, souligné.' },
      { name: 'first_name', label: 'Prénom', required: true, maxlength: 80, value: u ? u.first_name : '' },
      { name: 'last_name', label: 'Nom', required: true, maxlength: 80, value: u ? u.last_name : '' },
      { name: 'email', label: 'Adresse e-mail', type: 'email', required: true, maxlength: 190, value: u ? u.email : '' },
      { name: 'role', label: 'Rôle', type: 'select', options: roleOptions(), value: u ? u.role : 'user', disabled: self, hint: self ? 'Vous ne pouvez pas changer votre propre rôle.' : 'Un changement de rôle déconnecte la personne.' },
      { name: 'timezone', label: 'Fuseau horaire', required: true, maxlength: 64, value: u ? u.timezone : TZ, hint: 'Nom IANA, par exemple Europe/Paris.' }
    ];
    if (!u) fields.push({ name: 'password', label: 'Mot de passe (facultatif)', type: 'password', maxlength: 1024, autocomplete: 'new-password', hint: 'Laissez vide pour en générer un. Dans tous les cas, il devra être changé à la première connexion.' });
    formDialog({ title: u ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur', fields: fields,
      onSubmit: function (v) { if (!u && v.password === '') delete v.password; return u ? api('PUT', '/api/v1/users/' + u.id, v) : api('POST', '/api/v1/users', v); },
      onDone: function (body) {
        load();
        if (!u) { toast('Utilisateur créé.'); secretDialog(body.data, body.temporary_password, 'Compte créé'); } else toast('Utilisateur enregistré.');
      } });
  }

  function act(label, method, path, ok, confirmText) {
    if (confirmText && !window.confirm(confirmText)) return;
    api(method, path, {}).then(function (r) {
      if (!r.ok) return toast(msg(r, 'Action impossible.'), true);
      toast(ok); if (r.data.temporary_password) secretDialog(r.data.data, r.data.temporary_password, 'Mot de passe réinitialisé');
      load();
    });
  }

  function render(rows, meta) {
    results.textContent = '';
    if (!rows.length) { results.appendChild(el('p', 'empty', 'Aucun utilisateur ne correspond.')); pager.textContent = ''; return; }
    var wrap = el('div', 'table-wrap'), t = el('table', 'tbl'), th = el('thead'), hr = el('tr');
    [['name', 'Nom'], ['username', 'Identifiant'], ['', 'E-mail'], ['role', 'Rôle'], ['', 'État'], ['last_login', 'Dernière connexion'], ['', 'Actions']].forEach(function (c) {
      var h = el('th'); h.scope = 'col';
      if (c[0]) { var b = el('button', 'link', c[1] + (state.sort === c[0] ? (state.dir === 'asc' ? ' ▲' : ' ▼') : '')); b.type = 'button'; b.addEventListener('click', function () { state.dir = state.sort === c[0] && state.dir === 'asc' ? 'desc' : 'asc'; state.sort = c[0]; state.page = 1; load(); }); h.appendChild(b); }
      else h.textContent = c[1];
      hr.appendChild(h);
    });
    th.appendChild(hr); t.appendChild(th);
    var tb = el('tbody');
    rows.forEach(function (u) {
      var tr = el('tr'); if (!u.is_active) tr.className = 'muted-row';
      tr.appendChild(el('td', '', u.first_name + ' ' + u.last_name + (u.id === me ? ' (vous)' : '')));
      tr.appendChild(el('td', '', u.username)); tr.appendChild(el('td', '', u.email)); tr.appendChild(el('td', '', ROLE_FR[u.role] || u.role));
      var st = el('td'); st.appendChild(el('span', 'badge-s ' + (u.is_active ? 'ok' : 'off'), u.is_active ? 'Actif' : 'Désactivé'));
      if (u.must_change_password) st.appendChild(el('span', 'badge-s warn', 'Mot de passe à changer'));
      if (u.locked) st.appendChild(el('span', 'badge-s bad', 'Bloqué'));
      tr.appendChild(st);
      tr.appendChild(el('td', '', u.last_login_at ? fmt.format(new Date(u.last_login_at)) : 'Jamais'));
      var td = el('td', 'row-actions');
      var btn = function (label, fn, danger) { var b = el('button', 'btn btn-sm' + (danger ? ' btn-danger' : ''), label); b.type = 'button'; b.setAttribute('aria-label', label + ' : ' + u.username); b.addEventListener('click', fn); td.appendChild(b); };
      btn('Modifier', function () { edit(u); });
      if (u.locked) btn('Débloquer', function () { act('Débloquer', 'POST', '/api/v1/users/' + u.id + '/unlock', 'Compte débloqué.'); });
      btn('Mot de passe', function () { act('Réinitialiser', 'POST', '/api/v1/users/' + u.id + '/reset-password', 'Mot de passe réinitialisé.', 'Réinitialiser le mot de passe de ' + u.username + ' ? Ses sessions seront fermées.'); });
      btn('Déconnecter', function () { act('Déconnecter', 'POST', '/api/v1/users/' + u.id + '/revoke-sessions', 'Sessions fermées.', 'Fermer toutes les sessions de ' + u.username + ' ?'); });
      if (u.id !== me) {
        if (u.is_active) btn('Désactiver', function () { act('Désactiver', 'POST', '/api/v1/users/' + u.id + '/deactivate', 'Compte désactivé.', 'Désactiver ' + u.username + ' ? La personne sera déconnectée et ne pourra plus se connecter.'); }, true);
        else btn('Activer', function () { act('Activer', 'POST', '/api/v1/users/' + u.id + '/activate', 'Compte réactivé.'); });
      }
      tr.appendChild(td); tb.appendChild(tr);
    });
    t.appendChild(tb); wrap.appendChild(t); results.appendChild(wrap);
    pager.textContent = '';
    pager.appendChild(el('span', 'muted', meta.total + ' utilisateur' + (meta.total > 1 ? 's' : '') + ' — page ' + meta.page + '/' + meta.pages));
    var prev = el('button', 'btn btn-sm', 'Précédent'), next = el('button', 'btn btn-sm', 'Suivant'); prev.type = next.type = 'button';
    prev.disabled = meta.page <= 1; next.disabled = meta.page >= meta.pages;
    prev.addEventListener('click', function () { state.page--; load(); }); next.addEventListener('click', function () { state.page++; load(); });
    pager.append(prev, next);
  }

  function load() {
    var q = '?page=' + state.page + '&sort=' + state.sort + '&dir=' + state.dir + (state.q ? '&q=' + encodeURIComponent(state.q) : '') + (state.role ? '&role=' + state.role : '') + (state.active !== '' ? '&active=' + state.active : '');
    return api('GET', '/api/v1/users' + q).then(function (r) {
      if (!r.ok) { results.textContent = ''; results.appendChild(el('p', 'alert alert-error', msg(r, 'Chargement impossible.'))); return; }
      render(r.data.data, r.data.meta);
    });
  }

  function roleTable() {
    var body = document.getElementById('users-roles-body'); body.textContent = '';
    roles.forEach(function (r) {
      body.appendChild(el('h3', '', r.label + ' (' + r.users + ' utilisateur' + (r.users > 1 ? 's' : '') + ')'));
      body.appendChild(el('p', 'muted small', r.permissions.join(', ')));
    });
  }

  document.getElementById('users-filter').addEventListener('submit', function (e) {
    e.preventDefault(); state.q = document.getElementById('u-q').value.trim(); state.role = document.getElementById('u-role').value; state.active = document.getElementById('u-active').value; state.page = 1; load();
  });
  document.getElementById('u-new').addEventListener('click', function () { edit(null); });
  document.addEventListener('rvm:sync', function () { /* les comptes ne passent pas par le journal de synchronisation */ });

  api('GET', '/api/v1/roles').then(function (r) {
    if (r.ok) {
      roles = r.data.data; var sel = document.getElementById('u-role');
      roles.forEach(function (x) { var o = el('option', '', x.label); o.value = x.code; sel.appendChild(o); });
      roleTable();
    }
    return load();
  });
})();
