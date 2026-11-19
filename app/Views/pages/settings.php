<h1>Paramètres</h1>
<?php if ($user->mustChangePassword): ?>
<div class="alert alert-error" role="alert">Votre mot de passe a été défini par un administrateur : choisissez-en un nouveau pour continuer à utiliser l'application.</div>
<?php endif; ?>
<section class="panel" aria-labelledby="h-account">
  <h2 id="h-account">Profil</h2>
  <form id="profile-form" class="tr-form" novalidate>
    <div class="alert" id="profile-msg" role="status" hidden></div>
    <label>Prénom <input id="pf-first" maxlength="80" required autocomplete="given-name"></label>
    <label>Nom <input id="pf-last" maxlength="80" required autocomplete="family-name"></label>
    <label>Fuseau horaire <input id="pf-tz" list="tz-list" maxlength="64" required autocomplete="off"></label>
    <datalist id="tz-list"></datalist>
    <div><button class="btn btn-primary" type="submit">Enregistrer le profil</button></div>
  </form>
  <dl class="kv">
    <dt>Identifiant</dt><dd><?= $e($user->username) ?></dd>
    <dt>Adresse e-mail</dt><dd><?= $e($user->email) ?></dd>
  </dl>
  <p class="muted small">L'identifiant et l'adresse e-mail sont modifiés par un administrateur. Changer de fuseau horaire modifie l'affichage de tous vos rendez-vous (les heures enregistrées ne changent pas).</p>
</section>
<section class="panel" aria-labelledby="h-theme">
  <h2 id="h-theme">Affichage et calendrier</h2>
  <p class="muted small">Enregistré sur le serveur : vous retrouvez ces préférences sur tous vos appareils. Valeurs par défaut de l'organisation si vous n'en avez pas choisi.</p>
  <form id="ui-form" class="tr-form" novalidate>
    <div class="alert" id="ui-msg" role="status" hidden></div>
    <div id="ui-fields" class="tr-filters"></div>
    <div><button class="btn btn-primary" type="submit">Enregistrer l'affichage</button></div>
  </form>
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

<section class="panel" aria-labelledby="h-notif">
  <h2 id="h-notif">Notifications</h2>
  <form id="notif-form" novalidate>
    <div class="alert" id="notif-msg" role="status" hidden></div>
    <label class="check"><input type="checkbox" id="n-enabled"> Recevoir des notifications de rappel</label>
    <fieldset class="chan"><legend>Canaux</legend>
      <label class="check"><input type="checkbox" checked disabled> Dans l'application (toujours actif)</label>
      <label class="check"><input type="checkbox" id="n-ch-push" disabled> Notifications push du navigateur <span class="muted small" id="n-ch-push-hint"></span></label>
      <label class="check"><input type="checkbox" id="n-ch-email" disabled> E-mail <span class="muted small" id="n-ch-email-hint"></span></label>
    </fieldset>
    <label for="n-default">Rappel proposé pour les nouveaux rendez-vous</label>
    <select id="n-default"><option value="">Aucun</option><option value="0">À l'heure du rendez-vous</option><option value="5">5 minutes avant</option><option value="10">10 minutes avant</option><option value="15">15 minutes avant</option><option value="30">30 minutes avant</option><option value="60">1 heure avant</option><option value="120">2 heures avant</option><option value="1440">1 jour avant</option></select>
    <div class="row2">
      <div><label for="n-qs">Heures silencieuses : début</label><input id="n-qs" type="time"></div>
      <div><label for="n-qe">Heures silencieuses : fin</label><input id="n-qe" type="time"></div>
    </div>
    <p class="muted small">Heure locale (<?= $e($user->timezone) ?>). Les heures silencieuses retardent les canaux sonores (push, e-mail) ; le centre de notifications reste silencieux par nature.</p>
    <label for="n-privacy">Confidentialité du contenu</label>
    <select id="n-privacy"><option value="full">Afficher le titre, l'heure et le lieu</option><option value="minimal">Message générique, sans détail du rendez-vous</option></select>
    <label for="n-missed">Rappels manqués (serveur arrêté ou compte inactif)</label>
    <select id="n-missed"><option value="deliver">Les rattraper (jusqu'à 24 h, tant que le rendez-vous n'est pas terminé)</option><option value="skip">Ne pas les rattraper</option></select>
    <button class="btn btn-primary" type="submit">Enregistrer</button>
  </form>
</section>
<section class="panel" id="push-panel" aria-labelledby="h-push" hidden>
  <h2 id="h-push">Notifications push (cet appareil)</h2>
  <p id="push-status" class="muted" role="status">Vérification…</p>
  <div class="actions-row">
    <button class="btn btn-primary" type="button" id="push-enable" hidden>Activer sur cet appareil</button>
    <button class="btn" type="button" id="push-disable" hidden>Désactiver sur cet appareil</button>
    <button class="btn" type="button" id="push-test" hidden>Envoyer une notification de test</button>
  </div>
  <p class="muted small">La réception dépend du navigateur, du système et de la permission accordée : elle n'est jamais garantie (appareil éteint, mode économie d'énergie, navigateur fermé selon les systèmes).</p>
  <h3 class="sub-h">Appareils abonnés</h3>
  <div id="push-devices"><p class="muted small">Chargement…</p></div>
  <h3 class="sub-h">Derniers envois push</h3>
  <div id="push-history"><p class="muted small">Chargement…</p></div>
</section>
<section class="panel" id="email-panel" aria-labelledby="h-email" hidden>
  <h2 id="h-email">Rappels par e-mail</h2>
  <p class="muted">Les rappels sont envoyés par le serveur à l'adresse de votre compte (<?= $e($user->email) ?>), même si aucun navigateur n'est ouvert.</p>
  <button class="btn" type="button" id="email-test">Envoyer un e-mail de test</button>
  <p class="small" id="email-status" role="status"></p>
</section>
<?php if (!empty($canAdmin)): ?>
<section class="panel" aria-labelledby="h-org">
  <h2 id="h-org">Organisation (administrateur)</h2>
  <p class="muted small">Réglages communs à tous les utilisateurs. Les préférences d'affichage ci-dessous servent de valeurs par défaut à ceux qui n'ont rien choisi.</p>
  <form id="org-form" class="tr-form" novalidate>
    <div class="alert" id="org-msg" role="status" hidden></div>
    <label>Nom affiché (titre, en-tête, connexion) <input id="org-name" maxlength="80" placeholder="RendezVous Manager"></label>
    <label>Fuseau horaire par défaut des nouveaux comptes <input id="org-tz" list="tz-list" maxlength="64" autocomplete="off"></label>
    <div id="org-fields" class="tr-filters"></div>
    <div><button class="btn btn-primary" type="submit">Enregistrer l'organisation</button></div>
  </form>
</section>
<?php endif; ?>
<script src="/assets/js/prefs.js" defer></script>
<script src="/assets/js/settings.js" defer></script>
<script src="/assets/js/push.js" defer></script>
