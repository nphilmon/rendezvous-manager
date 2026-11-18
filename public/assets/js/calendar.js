/* Navigation de période du calendrier (barre supérieure). La grille arrive en phase 6. */
(function () {
  'use strict';
  var nav = document.getElementById('cal-nav');
  if (!nav) return;
  var view = nav.dataset.view || 'week';
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var iso = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
  var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(nav.dataset.date || '');
  var current = m ? new Date(+m[1], +m[2] - 1, +m[3]) : new Date();
  current = new Date(current.getFullYear(), current.getMonth(), current.getDate());

  function shift(d, dir) {
    if (view === 'day') return new Date(d.getFullYear(), d.getMonth(), d.getDate() + dir);
    if (view === 'month') return new Date(d.getFullYear(), d.getMonth() + dir, 1);
    return new Date(d.getFullYear(), d.getMonth(), d.getDate() + 7 * dir); // semaine et planning
  }
  function go(d) { location.href = '/calendar?view=' + encodeURIComponent(view) + '&date=' + iso(d); }

  function monday(d) { return new Date(d.getFullYear(), d.getMonth(), d.getDate() - ((d.getDay() + 6) % 7)); }
  function label() {
    var f = function (o) { return new Intl.DateTimeFormat('fr-FR', o); };
    if (view === 'day') return f({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(current);
    if (view === 'month') return f({ month: 'long', year: 'numeric' }).format(current);
    var a = monday(current), b = new Date(a.getFullYear(), a.getMonth(), a.getDate() + 6);
    if (view === 'agenda') return 'À partir du ' + f({ day: 'numeric', month: 'long', year: 'numeric' }).format(current);
    return f({ day: 'numeric', month: 'short' }).format(a) + ' – ' + f({ day: 'numeric', month: 'short', year: 'numeric' }).format(b);
  }
  document.getElementById('cal-period').textContent = label();
  document.getElementById('cal-today').addEventListener('click', function () { go(new Date()); });
  document.getElementById('cal-prev').addEventListener('click', function () { go(shift(current, -1)); });
  document.getElementById('cal-next').addEventListener('click', function () { go(shift(current, 1)); });
})();
