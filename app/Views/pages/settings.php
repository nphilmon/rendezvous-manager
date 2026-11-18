<h1>Paramètres</h1>
<section class="panel" aria-labelledby="h-theme">
  <h2 id="h-theme">Affichage</h2>
  <p class="muted small">Ce réglage est enregistré dans ce navigateur uniquement. Les préférences enregistrées sur le serveur (langue, fuseau, calendrier) arrivent avec la phase 21.</p>
  <label for="theme-select">Thème</label>
  <select id="theme-select">
    <option value="auto">Automatique (selon le système)</option>
    <option value="light">Clair</option>
    <option value="dark">Sombre</option>
  </select>
</section>
<section class="panel" aria-labelledby="h-account">
  <h2 id="h-account">Compte</h2>
  <dl class="kv">
    <dt>Nom</dt><dd><?= $e($user->firstName . ' ' . $user->lastName) ?></dd>
    <dt>Identifiant</dt><dd><?= $e($user->username) ?></dd>
    <dt>Adresse e-mail</dt><dd><?= $e($user->email) ?></dd>
    <dt>Fuseau horaire</dt><dd><?= $e($user->timezone) ?></dd>
  </dl>
</section>
<section class="panel" aria-labelledby="h-pw">
  <h2 id="h-pw">Changer le mot de passe</h2>
  <form id="password-form" novalidate>
    <div class="alert" id="password-msg" role="status" hidden></div>
    <label for="current_password">Mot de passe actuel</label>
    <input id="current_password" type="password" autocomplete="current-password" required maxlength="1024">
    <label for="new_password">Nouveau mot de passe (12 caractères minimum, majuscule, minuscule, chiffre)</label>
    <input id="new_password" type="password" autocomplete="new-password" required maxlength="1024">
    <label for="confirm_password">Confirmation</label>
    <input id="confirm_password" type="password" autocomplete="new-password" required maxlength="1024">
    <button class="btn btn-primary" type="submit">Mettre à jour</button>
  </form>
  <p class="muted small">Les autres sessions ouvertes seront déconnectées.</p>
</section>
