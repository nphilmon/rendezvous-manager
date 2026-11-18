/* Appliqué avant le rendu pour éviter le flash de thème. */
(function () {
  var t = 'auto';
  try { t = localStorage.getItem('rvm_theme') || 'auto'; } catch (e) {}
  if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
})();
