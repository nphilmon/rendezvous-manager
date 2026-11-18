<h1>Compte administrateur</h1>
<?php if ($created): ?><p class="ok">✔ Administrateur créé.</p>
<?php elseif ($exists): ?><p class="ok">Un administrateur actif existe déjà : aucun nouveau compte n'est créé.</p><?php endif; ?>
<?php foreach ($errors as $m): ?><div class="err" role="alert"><?= $e($m) ?></div><?php endforeach; ?>
<?php if (!$exists): ?>
<form method="post" action="/install/admin" autocomplete="off">
<input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
<div class="row">
<div><label for="last_name">Nom</label><input id="last_name" name="last_name" value="<?= $e($v['last_name']) ?>" required maxlength="80"></div>
<div><label for="first_name">Prénom</label><input id="first_name" name="first_name" value="<?= $e($v['first_name']) ?>" required maxlength="80"></div>
<div><label for="username">Identifiant</label><input id="username" name="username" value="<?= $e($v['username']) ?>" required maxlength="60" autocomplete="off"></div>
<div><label for="email">Adresse e-mail</label><input id="email" name="email" type="email" value="<?= $e($v['email']) ?>" required maxlength="190"></div>
<div><label for="password">Mot de passe (12 caractères min.)</label><div class="pw"><input id="password" name="password" type="password" required autocomplete="new-password"><button type="button" class="btn" data-toggle-pw="password">Afficher</button></div></div>
<div><label for="password_confirm">Confirmation</label><input id="password_confirm" name="password_confirm" type="password" required autocomplete="new-password"></div>
</div>
<div class="actions"><a class="btn" href="/install/verify">Précédent</a><button class="btn primary">Créer l'administrateur</button></div>
</form>
<?php else: ?>
<div class="actions"><a class="btn" href="/install/verify">Précédent</a><a class="btn primary" href="/install/services">Suivant</a></div>
<?php endif; ?>
