/* Page Paramètres : réglages de notification (enregistrés sur le serveur, par utilisateur). */
(function () {
  'use strict';
  // Les scripts différés s'exécutent dans l'ordre du document : on attend app.js (window.RVM.api).
  document.addEventListener('DOMContentLoaded', init);
  function init() {
  var f = document.getElementById('notif-form');
  if (!f || !window.RVM || !window.RVM.api) return;
  var $ = function (id) { return document.getElementById(id); }, msg = $('notif-msg');
  function show(text, ok) { msg.hidden = false; msg.className = 'alert ' + (ok ? 'alert-ok' : 'alert-error'); msg.textContent = text; }

  function fill(n) {
    $('n-enabled').checked = !!n.enabled; $('n-default').value = n.default_reminder === null ? '' : String(n.default_reminder);
    $('n-qs').value = n.quiet_start || ''; $('n-qe').value = n.quiet_end || ''; $('n-privacy').value = n.privacy; $('n-missed').value = n.missed;
    ['push', 'email'].forEach(function (c) { $('n-ch-' + c).checked = (n.channels || []).indexOf(c) >= 0; });
  }
  /** Canaux proposés : seulement ceux que le serveur sait envoyer (clés VAPID, SMTP). */
  function channels(avail) {
    ['push', 'email'].forEach(function (c) {
      var on = avail.indexOf(c) >= 0, cb = $('n-ch-' + c);
      cb.disabled = !on; $('n-ch-' + c + '-hint').textContent = on ? '' : '— non configuré sur ce serveur';
    });
    $('push-panel').hidden = avail.indexOf('push') < 0; $('email-panel').hidden = avail.indexOf('email') < 0;
    document.dispatchEvent(new CustomEvent('rvm:channels', { detail: avail }));
  }
  window.RVM.api('GET', '/api/v1/settings').then(function (r) { if (r.ok) { channels(r.data.meta.channels_available); fill(r.data.notifications); } else show('Chargement des réglages impossible.', false); });

  f.addEventListener('submit', function (e) {
    e.preventDefault(); msg.hidden = true;
    var qs = $('n-qs').value, qe = $('n-qe').value, dr = $('n-default').value;
    var body = { enabled: $('n-enabled').checked, default_reminder: dr === '' ? null : parseInt(dr, 10), quiet_start: qs || null, quiet_end: qe || null, privacy: $('n-privacy').value, missed: $('n-missed').value,
      channels: ['inapp'].concat($('n-ch-push').checked && !$('n-ch-push').disabled ? ['push'] : [], $('n-ch-email').checked && !$('n-ch-email').disabled ? ['email'] : []) };
    window.RVM.api('PUT', '/api/v1/settings', { notifications: body }).then(function (r) {
      if (r.ok) { channels(r.data.meta.channels_available); fill(r.data.notifications); show('Réglages enregistrés.', true); return; }
      var d = r.data && r.data.error ? r.data.error : {}, extra = d.details ? ' ' + Object.keys(d.details).map(function (k) { return d.details[k].join(' '); }).join(' ') : '';
      show((d.message || 'Enregistrement impossible.') + extra, false);
    });
  });
  }
})();
