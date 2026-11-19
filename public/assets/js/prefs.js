// Page Paramètres : profil, préférences d'affichage/calendrier et réglages d'organisation (tout est enregistré sur le serveur).
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var pf = document.getElementById('profile-form');
    if (!pf || !window.RVM || !window.RVM.api) return;
    var api = window.RVM.api, $ = function (id) { return document.getElementById(id); };
    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
    function show(box, text, ok) { box.hidden = !text; box.className = 'alert ' + (ok ? 'alert-ok' : 'alert-error'); box.textContent = text || ''; }
    function errText(r, fb) {
      var d = r.data && r.data.error ? r.data.error : {}, extra = d.details ? ' ' + Object.keys(d.details).map(function (k) { return k + ' : ' + d.details[k].join(' '); }).join(' ') : '';
      return (d.message || fb) + extra;
    }
    function hrs(from, to) { var a = []; for (var i = from; i <= to; i++) a.push([i, (i < 10 ? '0' : '') + i + ':00']); return a; }

    // Champs d'affichage, communs au formulaire personnel et à celui de l'organisation
    var FIELDS = [
      { k: 'theme', label: 'Thème', opts: [['auto', 'Automatique (système)'], ['light', 'Clair'], ['dark', 'Sombre']] },
      { k: 'view', label: 'Vue d\'ouverture du calendrier', opts: [['day', 'Jour'], ['week', 'Semaine'], ['month', 'Mois'], ['agenda', 'Planning']] },
      { k: 'week_start', label: 'Premier jour de la semaine', opts: [[1, 'Lundi'], [0, 'Dimanche']], num: true },
      { k: 'start_hour', label: 'Début de la grille', opts: hrs(0, 23), num: true },
      { k: 'end_hour', label: 'Fin de la grille', opts: hrs(1, 24), num: true },
      { k: 'step', label: 'Pas horaire', opts: [[5, '5 minutes'], [10, '10 minutes'], [15, '15 minutes'], [30, '30 minutes'], [60, '60 minutes']], num: true },
      { k: 'hour12', label: 'Format de l\'heure', opts: [['false', '24 heures'], ['true', '12 heures']], bool: true },
      { k: 'weekend', label: 'Week-end', opts: [['true', 'Afficher'], ['false', 'Masquer']], bool: true },
      { k: 'default_duration', label: 'Durée par défaut d\'un nouveau rendez-vous', opts: [[15, '15 minutes'], [30, '30 minutes'], [45, '45 minutes'], [60, '1 heure'], [90, '1 h 30'], [120, '2 heures'], [240, '4 heures']], num: true },
      { k: 'default_calendar', label: 'Calendrier proposé par défaut', personal: true, dynamic: true }
    ];
    function build(host, prefix, personal) {
      host.textContent = '';
      FIELDS.forEach(function (f) {
        if (f.personal && !personal) return;
        var l = el('label', '', f.label), s = el('select'); s.id = prefix + f.k;
        (f.opts || []).forEach(function (o) { var op = el('option', '', o[1]); op.value = String(o[0]); s.appendChild(op); });
        l.appendChild(s); host.appendChild(l);
      });
    }
    function fill(prefix, ui) {
      FIELDS.forEach(function (f) {
        var s = $(prefix + f.k); if (!s) return;
        var v = ui[f.k]; if (f.k === 'default_calendar') v = v === null || v === undefined ? '' : v;
        if (!s.querySelector('option[value="' + String(v) + '"]')) { var op = el('option', '', String(v)); op.value = String(v); s.appendChild(op); }
        s.value = String(v);
      });
    }
    function collect(prefix) {
      var out = {};
      FIELDS.forEach(function (f) {
        var s = $(prefix + f.k); if (!s) return;
        var v = s.value;
        out[f.k] = f.k === 'default_calendar' ? (v === '' ? null : parseInt(v, 10)) : f.bool ? v === 'true' : f.num ? parseInt(v, 10) : v;
      });
      return out;
    }

    build($('ui-fields'), 'u-', true);
    var tzs = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['Europe/Paris', 'UTC'];
    var dl = $('tz-list'); tzs.concat(['UTC']).forEach(function (z) { var o = el('option'); o.value = z; dl.appendChild(o); });

    api('GET', '/api/v1/settings').then(function (r) {
      if (!r.ok) { show($('ui-msg'), 'Chargement impossible.', false); return; }
      $('pf-first').value = r.data.profile.first_name; $('pf-last').value = r.data.profile.last_name; $('pf-tz').value = r.data.profile.timezone;
      fill('u-', r.data.ui);
    });
    api('GET', '/api/v1/calendars').then(function (r) {
      var s = $('u-default_calendar'); if (!s) return;
      var none = el('option', '', 'Automatique (dernier utilisé)'); none.value = ''; s.insertBefore(none, s.firstChild);
      if (r.ok) r.data.data.filter(function (c) { return c.can.create; }).forEach(function (c) { var o = el('option', '', c.name); o.value = String(c.id); s.appendChild(o); });
      if (window.RVM.ui) s.value = window.RVM.ui.default_calendar === null || window.RVM.ui.default_calendar === undefined ? '' : String(window.RVM.ui.default_calendar);
    });

    pf.addEventListener('submit', function (e) {
      e.preventDefault(); var box = $('profile-msg'); show(box, '');
      var tz = $('pf-tz').value.trim(), changed = tz !== (document.querySelector('meta[name="user-tz"]') || {}).content;
      api('PUT', '/api/v1/settings', { profile: { first_name: $('pf-first').value, last_name: $('pf-last').value, timezone: tz } }).then(function (r) {
        if (!r.ok) return show(box, errText(r, 'Enregistrement impossible.'), false);
        if (changed) { location.reload(); return; }
        show(box, 'Profil enregistré.', true);
      });
    });
    $('ui-form').addEventListener('submit', function (e) {
      e.preventDefault(); var box = $('ui-msg'); show(box, '');
      window.RVM.saveUi(collect('u-')).then(function (r) {
        if (!r.ok) return show(box, errText(r, 'Enregistrement impossible.'), false);
        document.documentElement.setAttribute('data-theme', r.data.ui.theme === 'auto' ? '' : r.data.ui.theme);
        if (r.data.ui.theme === 'auto') document.documentElement.removeAttribute('data-theme');
        show(box, 'Préférences enregistrées.', true);
      });
    });

    // Organisation (administrateur)
    var of = $('org-form'); if (!of) return;
    build($('org-fields'), 'o-', false);
    api('GET', '/api/v1/settings/global').then(function (r) {
      if (!r.ok) return show($('org-msg'), errText(r, 'Chargement impossible.'), false);
      $('org-name').value = r.data.global.org_name; $('org-tz').value = r.data.global.default_timezone; fill('o-', r.data.ui);
    });
    of.addEventListener('submit', function (e) {
      e.preventDefault(); var box = $('org-msg'); show(box, '');
      api('PUT', '/api/v1/settings/global', { global: { org_name: $('org-name').value, default_timezone: $('org-tz').value.trim() }, ui: collect('o-') }).then(function (r) {
        if (!r.ok) return show(box, errText(r, 'Enregistrement impossible.'), false);
        show(box, 'Réglages de l\'organisation enregistrés (le nom s\'affiche au prochain chargement des pages).', true);
      });
    });
  });
})();
