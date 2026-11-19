/* Appliqué avant le rendu pour éviter le flash de thème. Pages connectées : le serveur écrit déjà data-theme (réglage enregistré) ;
   page de connexion : préférence conservée dans ce navigateur. */
(function () {
  if (document.documentElement.getAttribute('data-theme-server') === '1') return;
  var t = 'auto';
  try { t = localStorage.getItem('rvm_theme') || 'auto'; } catch (e) {}
  if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
})();
