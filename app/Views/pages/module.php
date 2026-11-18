<h1><?= $e($moduleTitle) ?></h1>
<?php if ($query !== ''): ?><p>Recherche : « <?= $e($query) ?> »</p><?php endif; ?>
<p class="notice">Ce module n'est pas encore disponible : il est prévu à la phase <?= (int) $phase ?> du plan de développement. Aucune donnée n'est affichée tant qu'il n'est pas livré.</p>
