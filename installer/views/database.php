<h1>Configuration MySQL / MariaDB</h1>
<p class="muted">Ces identifiants restent sur le serveur (fichier <code>.env</code>, permissions 0600, hors zone publique) et ne sont jamais renvoyés au navigateur.</p>
<?php if ($result): foreach ($result['errors'] as $m): ?><div class="err" role="alert"><?= $e($m) ?></div><?php endforeach;
foreach ($result['lines'] as $l): ?><p class="<?= $l['ok'] ? 'ok' : 'bad' ?>"><?= $l['ok'] ? '✔' : '✘' ?> <?= $e($l['text']) ?></p><?php endforeach; endif; ?>
<form method="post" action="/install/database" autocomplete="off">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="row">
<div><label for="type">Type de base</label><select id="type" name="type">
<option value="mysql"<?= ($v['type'] ?? '') === 'mysql' ? ' selected' : '' ?>>MySQL</option>
<option value="mariadb"<?= ($v['type'] ?? '') === 'mariadb' ? ' selected' : '' ?>>MariaDB</option></select></div>
<div><label for="name">Nom de la base</label><input id="name" name="name" value="<?= $e($v['name'] ?? '') ?>" required></div>
<div><label for="host">Adresse du serveur</label><input id="host" name="host" value="<?= $e($v['host'] ?? '') ?>" required></div>
<div><label for="port">Port</label><input id="port" name="port" type="number" min="1" max="65535" value="<?= $e($v['port'] ?? 3306) ?>" required></div>
<div><label for="user">Utilisateur SQL</label><input id="user" name="user" value="<?= $e($v['user'] ?? '') ?>" required autocomplete="off"></div>
<div><label for="password">Mot de passe</label><div class="pw"><input id="password" name="password" type="password" autocomplete="new-password" placeholder="<?= $hasPassword ? '(conservé : laisser vide)' : '' ?>"><button type="button" class="btn" data-toggle-pw="password">Afficher</button></div></div>
<div><label for="ssl_mode">Mode TLS</label><select id="ssl_mode" name="ssl_mode">
<option value="verify"<?= ($v['ssl_mode'] ?? 'verify') === 'verify' ? ' selected' : '' ?>>Vérifier le certificat et l'identité du serveur</option>
<option value="required"<?= ($v['ssl_mode'] ?? '') === 'required' ? ' selected' : '' ?>>Chiffré, sans vérification d'identité</option>
<option value="disabled"<?= ($v['ssl_mode'] ?? '') === 'disabled' ? ' selected' : '' ?>>Désactivé (réseau local de confiance uniquement)</option></select></div>
<div><label for="ssl_ca">Certificat CA (chemin sur le serveur)</label><input id="ssl_ca" name="ssl_ca" value="<?= $e($v['ssl_ca'] ?? '') ?>" placeholder="/etc/ssl/certs/ca.pem"></div>
</div>
<div class="actions">
<a class="btn" href="/install/prerequisites">Précédent</a>
<button class="btn" name="action" value="test">Tester la connexion</button>
<button class="btn" name="action" value="perms">Vérifier les permissions</button>
<button class="btn primary" name="action" value="save">Enregistrer</button>
<a class="btn primary<?= ($result['saved'] ?? false) ? '' : ' disabled' ?>" href="/install/verify">Suivant</a>
</div>
</form>
