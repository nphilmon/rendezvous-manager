<h1>Vérification de la base « <?= $e($dbName) ?> »</h1>
<?php if ($msg): ?><p class="<?= $msg['ok'] ? 'ok' : 'bad' ?>"><?= $msg['ok'] ? '✔' : '✘' ?> <?= $e($msg['text']) ?></p><?php endif; ?>
<table>
<tr><th>Serveur</th><td><?= $e($version) ?> — <span class="<?= $vcheck['ok'] ? 'ok' : 'bad' ?>"><?= $e($vcheck['message']) ?></span></td></tr>
<tr><th>Encodage de la base</th><td class="<?= $info['charset_ok'] ? 'ok' : 'warn' ?>"><?= $e($info['charset'] ?: 'inconnu') ?><?= $info['charset_ok'] ? '' : ' — avertissement : utf8mb4 recommandé (les tables de l\'application sont créées en utf8mb4)' ?></td></tr>
<tr><th>Tables présentes</th><td><?= (int) $info['table_count'] ?></td></tr>
<tr><th>État</th><td><?= match ($info['state']) {
    'empty' => 'Base vide : prête à être initialisée.',
    'compatible' => 'Installation RendezVous Manager existante : les données sont préservées.',
    default => '<span class="bad">Tables inconnues : base non utilisable. Aucune table ne sera supprimée.</span>',
} ?></td></tr>
<tr><th>Migrations appliquées</th><td><?= $e(implode(', ', $info['applied']) ?: 'aucune') ?></td></tr>
<tr><th>Migrations en attente</th><td><?= $e(implode(', ', $info['pending']) ?: 'aucune') ?></td></tr>
<?php if ($info['unknown_applied']): ?><tr><th>Schéma plus récent</th><td class="bad"><?= $e(implode(', ', $info['unknown_applied'])) ?></td></tr><?php endif; ?>
</table>
<form method="post" action="/install/verify">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="actions">
<a class="btn" href="/install/database">Précédent</a>
<?php if ($info['state'] !== 'foreign' && $info['pending']): ?><button class="btn primary" name="action" value="migrate"><?= $info['state'] === 'empty' ? 'Initialiser la base' : 'Appliquer les migrations' ?></button><?php endif; ?>
<a class="btn primary<?= $ready ? '' : ' disabled' ?>" href="/install/admin">Suivant</a>
</div>
</form>
