<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= $e($csrf) ?>">
<meta name="user-tz" content="<?= $e($tz) ?>">
<meta name="sync-cursor" content="<?= (int) $syncCursor ?>">
<meta name="sync-access" content="<?= $e($syncAccess) ?>">
<meta name="default-reminder" content="<?= $e($defaultReminder) ?>">
<meta name="sync-interval" content="<?= (int) $syncInterval ?>">
<title><?= $e($title) ?> – <?= $e($appName) ?></title>
<link rel="icon" href="/assets/icons/logo.svg" type="image/svg+xml">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="stylesheet" href="/assets/css/app.css">
<link rel="stylesheet" href="/assets/css/calendar.css">
<script src="/assets/js/theme.js"></script>
</head>
<body class="app">
<?php include __DIR__ . '/_icons.php'; ?>
<a class="skip-link" href="#main">Aller au contenu</a>

<header class="topbar">
  <button class="icon-btn only-mobile" id="sidebar-toggle" aria-label="Ouvrir le menu" aria-controls="sidebar" aria-expanded="false"><svg><use href="#i-menu"/></svg></button>
  <a class="brand" href="/"><svg class="logo" aria-hidden="true"><use href="/assets/icons/logo.svg#logo"/></svg><span class="hide-sm">RendezVous Manager</span></a>

  <?php if ($calendarNav): ?>
  <div class="cal-nav" id="cal-nav" data-view="<?= $e($view) ?>" data-date="<?= $e($date) ?>">
    <button class="btn" id="cal-today" type="button">Aujourd'hui</button>
    <button class="icon-btn" id="cal-prev" type="button" aria-label="Période précédente"><svg><use href="#i-left"/></svg></button>
    <button class="icon-btn" id="cal-next" type="button" aria-label="Période suivante"><svg><use href="#i-right"/></svg></button>
    <h2 class="period" id="cal-period" aria-live="polite"></h2>
  </div>
  <?php endif; ?>

  <form class="search" role="search" method="get" action="/appointments">
    <label class="sr-only" for="q">Rechercher un rendez-vous</label>
    <svg aria-hidden="true"><use href="#i-search"/></svg>
    <input id="q" name="q" type="search" placeholder="Rechercher" maxlength="200">
  </form>

  <?php if ($calendarNav): ?>
  <nav class="view-switch" aria-label="Choix de la vue">
    <?php foreach ($views as $k => $label): ?>
    <a href="/calendar?view=<?= $e($k) ?><?= $date !== '' ? '&amp;date=' . $e($date) : '' ?>" class="<?= $k === $view ? 'active' : '' ?>"<?= $k === $view ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <button class="sync" id="sync-state" type="button" aria-label="Actualiser maintenant" title="Actualiser maintenant"><svg aria-hidden="true"><use href="#i-wifi"/></svg><span class="hide-sm" id="sync-label">Synchronisation…</span></button>
  <span class="sr-only" id="sync-live" role="status" aria-live="polite"></span>
  <a class="icon-btn bell" id="bell" href="/notifications" aria-label="Notifications<?= $unread > 0 ? ' (' . (int) $unread . ' non lues)' : '' ?>"><svg><use href="#i-bell"/></svg><?php if ($unread > 0): ?><span class="badge"><?= (int) $unread ?></span><?php endif; ?></a>

  <div class="menu-wrap">
    <button class="avatar" id="user-menu-btn" aria-haspopup="true" aria-expanded="false" aria-controls="user-menu" aria-label="Menu utilisateur"><?= $e(mb_strtoupper(mb_substr($user->firstName, 0, 1) . mb_substr($user->lastName, 0, 1))) ?></button>
    <div class="menu" id="user-menu" role="menu" hidden>
      <div class="menu-head"><strong><?= $e($user->firstName . ' ' . $user->lastName) ?></strong><span><?= $e($user->email) ?></span><span class="role"><?= $e(['admin' => 'Administrateur', 'manager' => 'Responsable', 'user' => 'Utilisateur', 'readonly' => 'Lecture seule'][$user->roleCode] ?? $user->roleCode) ?></span></div>
      <a role="menuitem" href="/settings">Paramètres</a>
      <button role="menuitem" type="button" id="theme-toggle">Thème : <span id="theme-label">auto</span></button>
      <button role="menuitem" type="button" id="logout-btn"><svg><use href="#i-logout"/></svg> Se déconnecter</button>
    </div>
  </div>
</header>

<div class="shell">
  <aside class="sidebar" id="sidebar" aria-label="Navigation principale">
    <?php if ($canCreate): ?><a class="btn btn-primary btn-new" href="/appointments/new"><svg><use href="#i-plus"/></svg> Nouveau rendez-vous</a><?php endif; ?>
    <div class="mini-cal" id="mini-cal" data-date="<?= $e($date) ?>" data-view="<?= $e($view) ?>" data-tz="<?= $e($tz) ?>" aria-label="Mini-calendrier"></div>
    <?php if ($calendarNav): ?><div class="cal-list" id="cal-list"></div><?php endif; ?>
    <nav>
      <?php $groupLabels = ['main' => '', 'appointments' => 'Rendez-vous', 'data' => 'Données', 'admin' => 'Gestion'];
      $last = null;
      foreach ($nav as $key => $item):
          if ($item['group'] !== $last): $last = $item['group']; ?>
        <?php if ($groupLabels[$last] !== ''): ?><p class="nav-group"><?= $e($groupLabels[$last]) ?></p><?php endif; ?>
      <?php endif; ?>
      <a class="nav-item<?= $key === $current ? ' active' : '' ?>" href="<?= $e($item['href']) ?>"<?= $key === $current ? ' aria-current="page"' : '' ?>><svg aria-hidden="true"><use href="#i-<?= $e($item['icon']) ?>"/></svg><span><?= $e($item['label']) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <p class="version">v<?= $e($version) ?></p>
  </aside>
  <div class="scrim" id="scrim" hidden></div>
  <main id="main" class="content" tabindex="-1">
    <?= $content ?>
  </main>
</div>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/appointments.js" defer></script>
<script src="/assets/js/sync.js" defer></script>
<?php if ($calendarNav): ?><script src="/assets/js/calendar.js" defer></script><?php endif; ?>
<?php if (!empty($dashboard)): ?><script src="/assets/js/dashboard.js" defer></script><?php endif; ?>
<?php if (!empty($transfer)): ?><script src="/assets/js/transfer.js" defer></script><?php endif; ?>
<?php if ($manage): ?><script src="/assets/js/manage.js" defer></script><?php endif; ?>
</body>
</html>
