<h1>Rapports</h1>
<div id="reports">
  <p class="muted">Statistiques d'activité de vos calendriers autorisés. Un rendez-vous compte dans la période où il commence ; les séries sont comptées occurrence par occurrence.</p>
  <form class="tr-form" id="rp-form" novalidate>
    <label>Du <input type="date" id="rp-from"></label>
    <label>Au (inclus) <input type="date" id="rp-to"></label>
    <div class="tr-actions">
      <button type="submit" class="btn btn-primary">Afficher</button>
      <button type="button" class="btn" data-preset="month">Ce mois</button>
      <button type="button" class="btn" data-preset="30d">30 derniers jours</button>
      <button type="button" class="btn" data-preset="year">Cette année</button>
      <?php if ($canExport): ?><button type="button" class="btn" id="rp-csv">Exporter en CSV</button><?php endif; ?>
    </div>
  </form>
  <div class="alert alert-error" id="rp-err" role="alert" hidden></div>
  <p class="notice" id="rp-warn" role="status" hidden></p>
  <section class="cards" id="rp-cards" aria-label="Totaux"></section>
  <div class="dash-grid" id="rp-grid"></div>
</div>
