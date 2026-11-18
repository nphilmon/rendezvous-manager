<h1><?= $e($heading) ?></h1>
<div id="appt-list" data-scope="<?= $e($scope) ?>" data-auto-new="<?= $autoNew ? '1' : '0' ?>">
  <form id="filters" class="filters" role="search" aria-label="Filtres">
    <div class="fld"><label for="f-q">Recherche</label><input id="f-q" name="q" type="search" maxlength="200" placeholder="Titre, description, lieu…"></div>
    <div class="fld"><label for="f-cal">Calendrier</label><select id="f-cal" name="calendar"><option value="">Tous</option></select></div>
    <div class="fld"><label for="f-status">Statut</label><select id="f-status" name="status"><option value="">Tous</option>
      <?php foreach ($statuses as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label for="f-prio">Priorité</label><select id="f-prio" name="priority"><option value="">Toutes</option>
      <?php foreach ($priorities as $k => $l): ?><option value="<?= $e($k) ?>"><?= $e($l) ?></option><?php endforeach; ?></select></div>
    <div class="fld"><label for="f-from">Du</label><input id="f-from" name="from" type="date"></div>
    <div class="fld"><label for="f-to">Au</label><input id="f-to" name="to" type="date"></div>
    <div class="fld"><label for="f-sort">Trier par</label><select id="f-sort" name="sort">
      <option value="start">Date</option><option value="title">Titre</option><option value="status">Statut</option><option value="priority">Priorité</option><option value="created">Création</option><option value="updated">Modification</option></select></div>
    <div class="fld"><label for="f-dir">Ordre</label><select id="f-dir" name="dir"><option value="<?= $scope === 'all' ? 'desc' : 'asc' ?>"><?= $scope === 'all' ? 'Plus récent d\'abord' : 'Plus ancien d\'abord' ?></option><option value="<?= $scope === 'all' ? 'asc' : 'desc' ?>"><?= $scope === 'all' ? 'Plus ancien d\'abord' : 'Plus récent d\'abord' ?></option></select></div>
    <label class="check"><input type="checkbox" name="trash" value="1"> Corbeille</label>
    <div class="filter-actions"><button class="btn btn-primary" type="submit">Filtrer</button><button class="btn" type="reset">Réinitialiser</button></div>
  </form>
  <div id="list-results" aria-live="polite"></div>
  <div id="list-pager" class="pager"></div>
  <noscript><p class="notice">JavaScript est nécessaire pour afficher la liste.</p></noscript>
</div>
