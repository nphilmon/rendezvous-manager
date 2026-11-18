<div class="dash-head">
  <div>
    <h1>Tableau de bord</h1>
    <p class="muted">Bonjour <?= $e($user->firstName) ?>. Chiffres réels, limités aux calendriers auxquels vous avez accès (<?= (int) $calendarCount ?>).</p>
  </div>
  <label class="dash-period">Période des graphiques
    <select id="dash-period">
      <?php foreach ($periods as $k => $label): ?><option value="<?= $e($k) ?>"<?= $k === 'month' ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
    </select>
  </label>
</div>

<div id="dash-error" class="alert alert-error" role="alert" hidden></div>
<div id="dash-warn" class="notice" role="status" hidden></div>

<section class="cards" aria-label="Indicateurs" id="dash-cards">
  <?php foreach (['total' => 'Rendez-vous au total', 'today' => 'Aujourd\'hui', 'week' => 'Cette semaine', 'month' => 'Ce mois-ci', 'upcoming' => 'À venir (30 jours)', 'completed' => 'Terminés (période)', 'cancelled' => 'Annulés (période)', 'reminders' => 'Rappels dans les 24 h'] as $k => $label): ?>
  <article class="stat"><span class="stat-n" data-k="<?= $e($k) ?>">–</span><span class="stat-l"><?= $e($label) ?></span></article>
  <?php endforeach; ?>
</section>

<div class="dash-grid">
  <section class="panel" aria-labelledby="dash-next-h">
    <h2 id="dash-next-h">Prochain rendez-vous</h2>
    <div id="dash-next"><p class="muted">Chargement…</p></div>
  </section>
  <section class="panel" aria-labelledby="dash-short-h">
    <h2 id="dash-short-h">Raccourcis</h2>
    <div class="shortcuts">
      <?php if ($canCreate): ?><a class="btn btn-primary" href="/appointments/new">Nouveau rendez-vous</a><?php endif; ?>
      <a class="btn" href="/calendar">Calendrier</a>
      <a class="btn" href="/appointments/today">Aujourd'hui</a>
      <a class="btn" href="/appointments/upcoming">À venir</a>
      <a class="btn" href="/contacts">Contacts</a>
      <a class="btn" href="/notifications">Notifications <span class="small muted" id="dash-unread"></span></a>
    </div>
  </section>
  <section class="panel" aria-labelledby="dash-status-h">
    <h2 id="dash-status-h">Répartition par statut <span class="muted small" data-period-label></span></h2>
    <div id="dash-status"></div>
  </section>
  <section class="panel" aria-labelledby="dash-cat-h">
    <h2 id="dash-cat-h">Répartition par catégorie <span class="muted small" data-period-label></span></h2>
    <div id="dash-cat"></div>
  </section>
  <section class="panel panel-wide" aria-labelledby="dash-day-h">
    <h2 id="dash-day-h">Charge des 14 prochains jours</h2>
    <div id="dash-day"></div>
  </section>
</div>
