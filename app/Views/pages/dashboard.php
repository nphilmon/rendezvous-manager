<div data-sync-banner="1" hidden></div>
<h1>Tableau de bord</h1>
<p class="muted">Bonjour <?= $e($user->firstName) ?>. Chiffres réels, limités aux calendriers auxquels vous avez accès (<?= (int) $calendarCount ?>).</p>
<section class="cards" aria-label="Indicateurs">
  <article class="stat"><span class="stat-n"><?= (int) $counts['total'] ?></span><span class="stat-l">Rendez-vous au total</span></article>
  <article class="stat"><span class="stat-n"><?= (int) $counts['today'] ?></span><span class="stat-l">Aujourd'hui</span></article>
  <article class="stat"><span class="stat-n"><?= (int) $counts['week'] ?></span><span class="stat-l">Cette semaine</span></article>
  <article class="stat"><span class="stat-n"><?= (int) $counts['upcoming'] ?></span><span class="stat-l">À venir</span></article>
</section>
<?php if ($counts['total'] === 0): ?>
<p class="notice">Aucun rendez-vous pour l'instant. La création de rendez-vous sera disponible avec la phase 7.</p>
<?php endif; ?>
<p class="muted small">Les graphiques, répartitions par catégorie/statut, rendez-vous terminés/annulés et prochain rendez-vous arrivent avec la phase 14. Les séries récurrentes ne sont pas encore dépliées (phase 9).</p>
