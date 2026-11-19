# Utiliser phpMyAdmin avec RendezVous Manager

phpMyAdmin n'est **pas** installé avec l'application. Si votre hébergeur en fournit un (cPanel : *phpMyAdmin*), voici comment l'utiliser sans risque.

## Bonnes pratiques

- **Accès restreint** : ne l'exposez jamais publiquement sans protection (authentification de l'hébergeur, liste d'adresses IP, VPN). Ne l'installez pas dans `public/`.
- **Compte dédié** : connectez-vous avec l'utilisateur de la base de l'application, pas avec un compte global.
- **Lecture d'abord** : pour comprendre ou dépanner, utilisez l'onglet *Structure*, *Parcourir* ou *SQL* avec des `SELECT`. Évitez toute modification manuelle des données.
- **Sauvegardez avant d'agir** : page *Sauvegardes* de l'application, ou l'onglet *Exporter* de phpMyAdmin.

## Ce qu'il ne faut pas faire

| À éviter | Pourquoi |
|---|---|
| Modifier `audit_logs` | le journal d'audit n'est pas signé : une modification manuelle le falsifie sans trace |
| Modifier `schema_migrations` ou une table à la main | les migrations refusent un fichier déjà appliqué dont la somme de contrôle diffère ; un schéma modifié casse les mises à jour |
| Supprimer des lignes d'`appointments`, `contacts`, `calendars` | l'application fait de la **suppression logique** (colonne `deleted_at`) et garde les liens ; une suppression physique casse l'historique et les clés étrangères |
| Changer `users.password_hash` | utilisez *Utilisateurs → Mot de passe* dans l'application (génère un mot de passe provisoire à changer) |
| Importer un `.sql` inconnu | exécute du code SQL arbitraire ; n'importez que vos propres sauvegardes |

## Opérations courantes

- **Voir un compte bloqué** : `SELECT username, is_active FROM users;` ; pour le débloquer, utilisez l'application (*Utilisateurs → Débloquer*).
- **Retrouver l'accès administrateur** : depuis un autre administrateur, utilisez *Utilisateurs → Mot de passe*. S'il n'en reste aucun, générez un condensat en ligne de commande (`php -r 'echo password_hash("Mot-de-passe-Provisoire-1", PASSWORD_ARGON2ID), PHP_EOL;'`) puis, dans phpMyAdmin : `UPDATE users SET password_hash = '…', must_change_password = 1, is_active = 1 WHERE username = 'admin';` — le mot de passe devra être changé dès la connexion. Ne saisissez jamais le mot de passe en clair dans la base.
- **Exporter** : onglet *Exporter* (format SQL, « structure et données », compression gzip). Cet export n'est pas celui de l'application (pas de contrôle SHA-256 ni de marqueur de fin).
- **Importer une sauvegarde phpMyAdmin** : sur une base vide ou après sauvegarde de l'existant ; vérifiez ensuite la page *Utilisateurs* et un rendez-vous.
- **Surveiller** : `SELECT COUNT(*) FROM notification_deliveries WHERE status = 'pending';` (rappels en attente), `SELECT * FROM audit_logs ORDER BY id DESC LIMIT 50;`.

## Confidentialité

La base contient des données personnelles (noms, e-mails, rendez-vous) et des condensats de mots de passe (Argon2id). Traitez toute capture d'écran ou export comme confidentiel.
