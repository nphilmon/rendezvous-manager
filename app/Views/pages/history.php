<h1>Historique</h1>
<div id="history">
  <p class="muted">Journal d'audit : qui a fait quoi, et quand. Les mots de passe et jetons n'y figurent jamais. L'historique d'un rendez-vous est aussi disponible dans sa fiche.</p>
  <form class="tr-form" id="h-filter" role="search">
    <label>Recherche (utilisateur, adresse IP) <input type="search" id="h-q" maxlength="100"></label>
    <label>Action <select id="h-action"><option value="">Toutes</option></select></label>
    <label>Du <input type="date" id="h-from"></label>
    <label>Au (inclus) <input type="date" id="h-to"></label>
    <div><button type="submit" class="btn btn-primary">Filtrer</button> <button type="button" class="btn" id="h-reset">Réinitialiser</button></div>
  </form>
  <div id="h-results" aria-live="polite"></div>
  <div id="h-pager" class="pager"></div>
  <noscript><p class="notice">JavaScript est nécessaire pour afficher cette page.</p></noscript>
</div>
