<h1>Accès à l'installation</h1>
<p>L'installation est protégée par un jeton à usage unique, généré sur le serveur en ligne de commande :</p>
<p><code>php scripts/install_token.php</code></p>
<?php if (!$hasToken): ?><p class="muted">Aucun jeton valide n'est actuellement émis (ou il a expiré).</p><?php endif; ?>
<?php if ($error): ?><div class="err" role="alert"><?= $e($error) ?></div><?php endif; ?>
<form method="post" action="/install/token" autocomplete="off">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<label for="token">Jeton d'installation</label>
<input id="token" name="token" required autofocus spellcheck="false" autocomplete="off">
<div class="actions"><button class="btn primary">Valider</button></div>
</form>
