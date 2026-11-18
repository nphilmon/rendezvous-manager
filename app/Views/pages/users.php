<h1>Utilisateurs</h1>
<div id="users" data-me="<?= (int) $me ?>">
  <p class="muted">Créez et gérez les comptes. Un compte n'est jamais supprimé : il est désactivé, et ses calendriers et rendez-vous sont conservés.</p>
  <div class="manage-toolbar" id="users-toolbar">
    <form class="search-inline" id="users-filter" role="search">
      <input type="search" id="u-q" placeholder="Rechercher un utilisateur…" maxlength="100" aria-label="Rechercher un utilisateur">
      <select id="u-role" aria-label="Filtrer par rôle"><option value="">Tous les rôles</option></select>
      <select id="u-active" aria-label="Filtrer par état"><option value="">Tous les états</option><option value="1">Actifs</option><option value="0">Désactivés</option></select>
      <button class="btn" type="submit">Filtrer</button>
    </form>
    <button class="btn btn-primary" type="button" id="u-new">Nouvel utilisateur</button>
  </div>
  <div id="users-results" aria-live="polite"></div>
  <div id="users-pager" class="pager"></div>
  <details class="panel" id="users-roles"><summary>Rôles et permissions</summary><div id="users-roles-body"></div></details>
  <noscript><p class="notice">JavaScript est nécessaire pour afficher cette page.</p></noscript>
</div>
