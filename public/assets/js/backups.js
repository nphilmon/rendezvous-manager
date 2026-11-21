// Sauvegardes : liste, création, vérification, téléchargement (fetch → blob), suppression. Rendu avec textContent uniquement.
(function () {
  'use strict';
  var root = document.getElementById('backups');
  if (!root) return;
  var api = window.RVM.api, results = document.getElementById('b-results'), err = document.getElementById('b-err');
  var TZ = (document.querySelector('meta[name="user-tz"]') || {}).content || 'UTC';
  var fmt = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'short', timeStyle: 'medium', timeZone: TZ });
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function toast(t, bad) { window.RVM.appointments.toast(t, bad); }
  function msg(r, fb) { return (r.data && r.data.error && r.data.error.message) || fb; }
  function size(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(n / 1024)) + ' Ko'; }

  function download(b, btn) {
    btn.disabled = true;
    fetch('/api/v1/backups/' + encodeURIComponent(b.name) + '/download', { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('Téléchargement impossible (' + r.status + ').');
      return r.blob();
    }).then(function (blob) {
      var a = document.createElement('a'), url = URL.createObjectURL(blob); a.href = url; a.download = b.name; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }).catch(function (e) { toast(e.message, true); }).then(function () { btn.disabled = false; });
  }

  function load() {
    return api('GET', '/api/v1/backups').then(function (r) {
      results.textContent = '';
      if (!r.ok) { results.appendChild(el('p', 'alert alert-error', msg(r, 'Chargement impossible.'))); return; }
      var rows = r.data.data;
      if (!r.data.meta.encrypts) results.appendChild(el('p', 'notice', 'Les nouvelles sauvegardes ne sont pas chiffrées : générez une clé avec « php scripts/generate_backup_key.php » (voir la documentation).'));
      if (!rows.length) { results.appendChild(el('p', 'empty', 'Aucune sauvegarde pour l\'instant. Créez-en une, et planifiez la sauvegarde automatique.')); return; }
      var age = (Date.now() - new Date(rows[0].created_at).getTime()) / 86400000;
      if (age > 2) results.appendChild(el('p', 'notice', 'La dernière sauvegarde date de ' + Math.floor(age) + ' jours : vérifiez la tâche planifiée.'));
      var wrap = el('div', 'table-wrap'), t = el('table', 'tbl'), th = el('thead'), hr = el('tr');
      ['Fichier', 'Date', 'Taille', 'Lignes', 'Chiffrée', 'Somme SHA-256', 'Actions'].forEach(function (c) { var h = el('th', '', c); h.scope = 'col'; hr.appendChild(h); });
      th.appendChild(hr); t.appendChild(th);
      var tb = el('tbody');
      rows.forEach(function (b) {
        var tr = el('tr');
        tr.append(el('td', '', b.name), el('td', 'nowrap', fmt.format(new Date(b.created_at))), el('td', '', size(b.size)), el('td', '', b.rows === null ? '?' : String(b.rows)), el('td', '', b.encrypted ? 'Oui' : 'Non'),
          el('td', 'small', b.sha256 ? b.sha256.slice(0, 16) + '…' : 'absente'));
        var td = el('td', 'row-actions');
        var btn = function (label, fn, danger) { var x = el('button', 'btn btn-sm' + (danger ? ' btn-danger' : ''), label); x.type = 'button'; x.setAttribute('aria-label', label + ' : ' + b.name); x.addEventListener('click', function () { fn(x); }); td.appendChild(x); };
        btn('Télécharger', function (x) { download(b, x); });
        btn('Vérifier', function (x) {
          x.disabled = true;
          api('POST', '/api/v1/backups/' + encodeURIComponent(b.name) + '/verify', {}).then(function (r) {
            x.disabled = false;
            if (!r.ok) return toast(msg(r, 'Vérification impossible.'), true);
            r.data.data.ok ? toast('Sauvegarde intègre : somme, archive et marqueur de fin corrects.') : toast(r.data.data.problems.join(' '), true);
          });
        });
        btn('Supprimer', function () {
          if (!window.confirm('Supprimer définitivement ' + b.name + ' ?')) return;
          api('DELETE', '/api/v1/backups/' + encodeURIComponent(b.name)).then(function (r) { if (r.ok) { toast('Sauvegarde supprimée.'); load(); } else toast(msg(r, 'Suppression impossible.'), true); });
        }, true);
        tr.appendChild(td); tb.appendChild(tr);
      });
      t.appendChild(tb); wrap.appendChild(t); results.appendChild(wrap);
    });
  }

  document.getElementById('b-create').addEventListener('click', function () {
    var b = this; b.disabled = true; err.hidden = true; b.textContent = 'Sauvegarde en cours…';
    api('POST', '/api/v1/backups', {}).then(function (r) {
      b.disabled = false; b.textContent = 'Créer une sauvegarde';
      if (!r.ok) { err.hidden = false; err.textContent = msg(r, 'Sauvegarde impossible.'); return; }
      if (!r.data.verified) { err.hidden = false; err.textContent = 'Sauvegarde créée mais la vérification a échoué : ' + r.data.problems.join(' '); } else toast('Sauvegarde créée et vérifiée (' + size(r.data.data.size) + ').');
      load();
    });
  });
  load();
})();
