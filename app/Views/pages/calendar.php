<h1 class="sr-only" id="cal-h1">Calendrier – vue <?= $e($views[$view]) ?></h1>
<div id="calendar-root" class="calendar-root" data-view="<?= $e($view) ?>" data-date="<?= $e($date) ?>" data-tz="<?= $e($tz) ?>">
  <div class="cal-toolbar" id="cal-toolbar"></div>
  <div class="cal-grid" id="cal-grid"></div>
  <noscript><p class="notice">JavaScript est nécessaire pour afficher le calendrier.</p></noscript>
</div>
