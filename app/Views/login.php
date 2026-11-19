<main class="auth-card" id="main">
<div class="brand brand-lg"><svg class="logo" aria-hidden="true"><use href="/assets/icons/logo.svg#logo"/></svg><span><?= $e($appName ?? "RendezVous Manager") ?></span></div>
<h1>Connexion</h1>
<form id="login-form" method="post" action="/login" novalidate data-next="<?= $e($next) ?>">
<div class="alert alert-error" id="login-error" role="alert" hidden></div>
<label for="identifier">Identifiant ou adresse e-mail</label>
<input id="identifier" name="identifier" autocomplete="username" required autofocus maxlength="190">
<label for="password">Mot de passe</label>
<div class="input-group">
<input id="password" name="password" type="password" autocomplete="current-password" required maxlength="1024">
<button type="button" class="btn" id="toggle-password" aria-controls="password" aria-pressed="false">Afficher</button>
</div>
<button class="btn btn-primary btn-block" id="login-submit" type="submit">Se connecter</button>
</form>
<noscript><p class="alert alert-error">JavaScript est nécessaire pour se connecter.</p></noscript>
</main>
