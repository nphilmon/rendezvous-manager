<h1>Vérification des prérequis</h1>
<?php include __DIR__ . '/_checks.php'; ?>
<?php if (!$can): ?><div class="err" role="alert">Des prérequis indispensables ne sont pas satisfaits. Corrigez-les puis relancez les vérifications.</div><?php endif; ?>
<div class="actions">
<a class="btn" href="/install">Précédent</a>
<a class="btn" href="/install/prerequisites">Relancer les vérifications</a>
<a class="btn primary<?= $can ? '' : ' disabled' ?>" href="/install/database"<?= $can ? '' : ' aria-disabled="true"' ?>>Suivant</a>
</div>
