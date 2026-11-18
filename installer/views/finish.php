<h1>Finalisation</h1>
<?php if ($error): ?><div class="err" role="alert"><?= $e($error) ?></div><?php endif; ?>
<table><?php foreach ($summary as $s): ?><tr><td><?= $e($s['label']) ?></td><td class="<?= $s['ok'] ? 'ok' : 'bad' ?>"><strong><?= $s['ok'] ? 'Réussi' : 'Non réussi' ?></strong></td></tr><?php endforeach; ?></table>
<p class="muted">En terminant, l'assistant est verrouillé définitivement (<code>storage/install.lock</code>) et vous serez redirigé vers la page de connexion.</p>
<form method="post" action="/install/finish">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="actions"><a class="btn" href="/install/services">Précédent</a><button class="btn primary"<?= $ok ? '' : ' disabled' ?>>Terminer l'installation</button></div>
</form>
