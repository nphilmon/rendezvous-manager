# Installation

Ce guide installe RendezVous Manager sur un hébergement PHP classique (mutualisé cPanel, VPS, serveur dédié). Pour une installation sous nginx, voir aussi `docs/nginx.conf.example`.

## 1. Prérequis

| Élément | Exigence |
|---|---|
| PHP | 8.3 ou plus, en ligne de commande **et** pour le site |
| Extensions obligatoires | `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json` |
| Extensions facultatives | `curl` (Web Push), `zip` (export XLSX), `intl`, `sodium` |
| Base de données | MySQL 8 ou MariaDB 10.6+ (testé : MariaDB 10.11), moteur InnoDB, jeu de caractères `utf8mb4` |
| Serveur web | Apache (`mod_rewrite`) ou nginx, **HTTPS obligatoire** en production |
| Tâches planifiées | `cron` (rappels, nettoyage, sauvegardes) |
| Accès | Un terminal (SSH ou « Terminal » de cPanel) pour générer le jeton d'installation |

Ne sont **pas** nécessaires : Composer, Node.js, Docker. Aucune bibliothèque tierce n'est chargée.

## 2. Déposer le code

Le code doit être placé **hors** du dossier public, et seul le sous-dossier `public/` doit être servi par le web.

```
/home/utilisateur/rendezvous-manager/      ← dépôt complet (jamais servi tel quel)
└── public/                                ← racine web du domaine
```

- **cPanel** : Git Version Control → Créer → *Cloner un dépôt* → URL du dépôt → chemin `rendezvous-manager` (un dossier **vide ou inexistant**, différent de la racine du site). Puis Domaines → votre domaine → Gérer → *Racine du document* = `rendezvous-manager/public`.
- **Autre hébergement** : `git clone … rendezvous-manager`, puis pointez le serveur web sur `rendezvous-manager/public`.
- **Mise à jour ultérieure** : cPanel → Git Version Control → Gérer → *Mise à jour depuis le dépôt distant* (ou `git pull`), puis appliquez les migrations (§ 7).

Si votre hébergeur ne permet pas de choisir la racine web, un `.htaccess` à la racine du dépôt n'expose que `public/` et interdit `app/`, `config/`, `storage/`, `.env`… (Apache uniquement ; à vérifier : `https://votre-domaine/.env` et `/app/` doivent répondre 403).

### Droits d'écriture

Le serveur web doit pouvoir écrire dans `storage/logs`, `storage/exports`, `storage/backups`, `storage/sessions`, et à la racine du dépôt (ou dans un `.env` existant) pour que l'assistant crée le fichier `.env`. Sur un mutualisé, ces droits sont en général déjà bons (dossiers 0755, fichiers 0644).

## 3. Créer la base de données

Voir `docs/configuration_mysql.md` (création de la base et de l'utilisateur, privilèges, TLS). Notez : nom de la base, utilisateur, mot de passe, hôte.

## 4. Assistant d'installation

1. Dans un terminal, depuis le dossier du dépôt :
   ```bash
   php scripts/install_token.php
   ```
   Le jeton est affiché **une seule fois**, valable 2 heures, à usage unique.
2. Ouvrez `https://votre-domaine/install`, saisissez le jeton.
3. Étapes de l'assistant : **Prérequis** (PHP, extensions, droits d'écriture) → **Base de données** (test de connexion, privilèges, TLS) → **Vérification** (création des tables par migrations, reprise possible) → **Administrateur** (identifiant, e-mail, mot de passe d'au moins 12 caractères avec majuscule, minuscule et chiffre) → **Services** (cron, SMTP, Web Push : simple état, rien n'est envoyé) → **Terminer**.
4. À la fin, `storage/install.lock` est créé : l'assistant est **verrouillé** (toute visite de `/install` est refusée). Les paramètres de connexion sont dans `.env` (droits 0600).

Si l'assistant est interrompu, relancez-le : la base déjà initialisée est reconnue et les données sont conservées. Une base contenant des tables étrangères à l'application est refusée (jamais écrasée).

## 5. Configuration (`.env`)

L'assistant écrit les paramètres de la base ; le reste se règle à la main dans `.env` (modèle : `.env.example`).

| Variable | Rôle | Défaut |
|---|---|---|
| `APP_ENV` | `production` en ligne (cookies sécurisés, erreurs masquées) | `production` |
| `APP_DEBUG` | jamais `true` en production | `false` |
| `APP_URL` | URL publique complète | — |
| `APP_TIMEZONE` | fuseau d'affichage par défaut | `Europe/Paris` |
| `DB_DRIVER` | pilote PDO (`mysql` pour MySQL et MariaDB) | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | connexion MySQL/MariaDB | — |
| `DB_SSL_MODE` | `disabled`, `required` ou `verify` (certificat et identité vérifiés) | `verify` |
| `DB_SSL_CA` | fichier d'autorité de certification (TLS `verify`) | vide |
| `COOKIE_SECURE` | cookie de session réservé à HTTPS | `true` en production |
| `HSTS_MAX_AGE` | durée de l'en-tête HSTS en secondes (0 = non envoyé) ; à activer une fois HTTPS définitif | `0` |
| `SYNC_INTERVAL` | intervalle de synchronisation des navigateurs (secondes, 2 à 300) | `30` |
| `MAIL_ENABLED`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`, `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_FROM`, `MAIL_CA_FILE` | e-mail SMTP facultatif (TLS vérifié, jamais de repli en clair) | désactivé |
| `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` | Web Push (clés générées par `php scripts/generate_vapid.php`) | vides |
| `PUSH_ALLOW_ANY` | autorise d'autres services push que FCM/Mozilla/Apple (déconseillé) | `false` |
| `BACKUP_DIR`, `BACKUP_KEEP` | dossier et nombre de sauvegardes conservées | `storage/backups`, `14` |
| `AUDIT_RETENTION_DAYS` | purge du journal d'audit (0 = jamais) | `0` |
| `TEST_DB_*` | base **dédiée** aux tests (nom finissant par `_test`) — inutile en production | — |

`.env` ne doit jamais être commité ni accessible par le web (droits 0600).

## 6. Tâches planifiées (cron)

À créer dans cPanel → Tâches Cron (ou `crontab -e`). Adaptez le chemin de PHP (`which php`) et du dépôt.

```cron
# Rappels et notifications (chaque minute)
* * * * * /usr/local/bin/php /home/utilisateur/rendezvous-manager/workers/reminder_worker.php >> /home/utilisateur/rendezvous-manager/storage/logs/cron.log 2>&1
# Nettoyage (sessions, journaux de synchronisation, tentatives) chaque nuit
17 3 * * * /usr/local/bin/php /home/utilisateur/rendezvous-manager/workers/cleanup_worker.php >> /home/utilisateur/rendezvous-manager/storage/logs/cron.log 2>&1
# Sauvegarde chaque nuit
30 2 * * * /usr/local/bin/php /home/utilisateur/rendezvous-manager/workers/backup_worker.php >> /home/utilisateur/rendezvous-manager/storage/logs/cron.log 2>&1
```

Détails des notifications : `docs/notifications.md`. Contrôle de santé : `php scripts/healthcheck.php` (base, dernier passage du worker, journaux).

## 7. Mise à jour

Raccourci recommandé : `scripts/update.sh` (maintenance, sauvegarde, code, migrations, contrôles) — voir `docs/deploiement.md`. Manuellement :

```bash
git pull                      # ou « Mise à jour depuis le dépôt distant » dans cPanel
php scripts/migrate.php       # applique les migrations en attente (idempotent)
```

Faites une sauvegarde avant (page **Sauvegardes**). Une migration déjà appliquée dont le fichier a été modifié est refusée (somme de contrôle).

## 8. Liste de contrôle après installation

- [ ] `https://votre-domaine/` ouvre la page de connexion en HTTPS.
- [ ] `https://votre-domaine/.env`, `/app/`, `/storage/`, `/install` répondent 403 ou 404.
- [ ] Le compte administrateur se connecte et change son mot de passe si besoin.
- [ ] Les trois tâches cron sont créées ; `php scripts/healthcheck.php` ne signale aucune erreur.
- [ ] Une sauvegarde manuelle réussit (page Sauvegardes) et une copie est conservée **hors du serveur**.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `COOKIE_SECURE=true`.
- [ ] `php scripts/deploy_check.php --url=https://votre-domaine` sans « ÉCHEC » (contrôle automatique de tout ce qui précède).

## 9. Dépannage

| Symptôme | Cause probable |
|---|---|
| **404 nginx** sur toutes les pages | la racine web n'est pas `public/`, ou nginx n'a pas la règle `try_files $uri /index.php?$query_string;` (voir `docs/nginx.conf.example`). Test : `/assets/css/app.css` doit s'afficher. |
| 404 sur `/login` mais pas sur les fichiers statiques | réécriture d'URL absente : `mod_rewrite` / `.htaccess` ignoré (nginx), `AllowOverride All` manquant (Apache) |
| Page blanche ou erreur 500 | consultez `storage/logs/*.log` ; version de PHP < 8.3, extension manquante |
| « Base inaccessible » | identifiants, hôte, droits ; mode TLS `verify` sans autorité de certification valide |
| Redirection vers `/install` | `storage/install.lock` absent : l'installation n'est pas terminée (ou le fichier a été supprimé) |
| Pas de rappels | le cron `reminder_worker.php` ne tourne pas : `php scripts/healthcheck.php` |
| Impossible de se connecter, cookie refusé | `COOKIE_SECURE=true` en HTTP : utilisez HTTPS |
