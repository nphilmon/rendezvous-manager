# Déploiement en production

Ce guide complète `docs/installation.md` : il décrit les scénarios d'hébergement, la mise en ligne, la **mise à jour sans interruption surprise**, le retour arrière et la supervision. Les outils fournis :

| Outil | Rôle |
|---|---|
| `php scripts/deploy_check.php [--url=https://…]` | contrôle la configuration et, avec l'URL, **l'exposition depuis Internet** (fichiers sensibles, HTTPS, en-têtes de sécurité) ; code de sortie 1 en cas d'échec |
| `scripts/update.sh` | mise à jour : maintenance → sauvegarde → `git pull` → migrations → contrôles |
| `storage/maintenance.lock` | mode maintenance : tant que ce fichier existe, le site répond 503 |
| `php scripts/healthcheck.php [--json]` | santé pour la supervision (base, migrations, cron) |
| `GET /api/v1/health` | sonde HTTP publique : `{status, database}`, 200 ou 503 |

## 1. Scénario A — cPanel (mutualisé)

1. **Version de PHP** : *MultiPHP Manager* → votre domaine → PHP **8.3** ; *Extensions PHP* : `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zip` (et `intl`, `sodium` si proposés).
2. **Code** : *Git Version Control* → Créer → cloner l'URL du dépôt dans un dossier **nouveau**, par exemple `rendezvous-manager` (cPanel refuse un dossier qui contient déjà des fichiers, comme la racine d'un site).
3. **Racine du site** : *Domaines* → votre (sous-)domaine → Gérer → *Racine du document* = `rendezvous-manager/public`.
4. **HTTPS** : *SSL/TLS Status* → lancer AutoSSL pour le domaine ; puis forcer HTTPS (*Domaines* → « Forcer la redirection HTTPS »).
5. **Base** : *Bases de données MySQL* → base + utilisateur + tous les privilèges (`docs/configuration_mysql.md`). Sur mutualisé, les noms sont préfixés (`phni1318_agenda`).
6. **Terminal** (*Terminal* ou SSH) :
   ```bash
   cd ~/rendezvous-manager
   php -v                                  # doit afficher 8.3 ; sinon utiliser le chemin complet, ex. /opt/cpanel/ea-php83/root/usr/bin/php
   php scripts/install_token.php           # jeton d'installation (une fois, 2 h)
   ```
7. Ouvrir `https://votre-domaine/install` et suivre l'assistant (`docs/installation.md` § 4). Hôte de base : `localhost` ; TLS `disabled` (même machine).
8. **Cron** (*Tâches Cron*) : trois lignes de `docs/installation.md` § 6, avec le **chemin complet de PHP 8.3** (celui que `which php` ou la page MultiPHP indique) et `>> …/storage/logs/cron.log 2>&1`.
9. **Contrôle** :
   ```bash
   php scripts/deploy_check.php --url=https://votre-domaine
   ```
   Tous les points doivent être « OK » ou, pour HSTS et `X-Powered-By`, des avertissements que vous acceptez en connaissance de cause.
10. **HSTS** (facultatif, plus tard) : une fois certain que HTTPS restera, `HSTS_MAX_AGE=31536000` dans `.env`.

Sur cPanel, nginx sert souvent de frontal à Apache : le `.htaccess` de `public/` est alors lu par Apache. Si un **404 nginx** apparaît sur les pages mais pas sur `/assets/css/app.css`, la réécriture d'URL ne parvient pas à `index.php` (voir `docs/installation.md` § 9).

Si l'hébergeur **impose** la racine du dépôt comme racine du site, le `.htaccess` de la racine n'expose que `public/` ; `deploy_check --url=` doit alors le confirmer (aucun « FICHIER EXPOSÉ »).

## 2. Scénario B — serveur Linux avec Apache

```bash
sudo apt install apache2 php8.3 php8.3-mysql php8.3-mbstring php8.3-curl php8.3-zip libapache2-mod-php8.3 mariadb-server
sudo a2enmod rewrite headers ssl
sudo git clone <dépôt> /var/www/rendezvous
sudo chown -R www-data:www-data /var/www/rendezvous/storage
```

```apache
<VirtualHost *:443>
    ServerName rendezvous.example.org
    DocumentRoot /var/www/rendezvous/public
    <Directory /var/www/rendezvous/public>
        AllowOverride All
        Require all granted
    </Directory>
    SSLEngine on
    # certificat : Let's Encrypt (certbot --apache)
    # Header always set Strict-Transport-Security "max-age=31536000"   # OU HSTS_MAX_AGE dans .env, pas les deux
</VirtualHost>
<VirtualHost *:80>
    ServerName rendezvous.example.org
    Redirect permanent / https://rendezvous.example.org/
</VirtualHost>
```

Puis : base de données, `php scripts/install_token.php` (en tant que `www-data` : `sudo -u www-data php …`), assistant, cron (`sudo -u www-data crontab -e`).

## 3. Scénario C — nginx + PHP-FPM

Utilisez `docs/nginx.conf.example` (redirection HTTPS, seul `index.php` exécutable, fichiers cachés interdits, taille des requêtes). Même déroulement que B pour le reste. **nginx ignore les `.htaccess`** : la configuration ci-dessus est indispensable.

## 4. Mettre à jour

```bash
cd ~/rendezvous-manager
scripts/update.sh                 # ou : PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php scripts/update.sh
```

Le script : refuse de démarrer si des fichiers du dépôt ont été modifiés à la main ; **active la maintenance** (503 pour tout le monde) ; **sauvegarde la base** et la vérifie ; récupère le code (`git pull --ff-only`) ; applique les migrations ; lance `deploy_check` ; **lève la maintenance** seulement si tout a réussi. En cas d'échec la maintenance **reste active** et la commande de retour arrière est affichée.

Sans accès à `git` sur le serveur (cPanel : *Mise à jour depuis le dépôt distant*) : faites la mise à jour dans l'interface, puis `php scripts/migrate.php` et `php scripts/deploy_check.php`. Faites une sauvegarde avant (page *Sauvegardes*).

Mettre à jour en dehors des heures d'usage : la maintenance dure en général quelques secondes, davantage avec une grosse base.

## 5. Retour arrière

1. Retrouvez la version précédente (affichée par `update.sh`, ou `git reflog`) et la sauvegarde faite juste avant.
2. ```bash
   git checkout <ancien-commit>
   php scripts/restore.php <sauvegarde.sql.gz>            # vérifie seulement
   php scripts/restore.php <sauvegarde.sql.gz> --yes      # restaure (une sauvegarde de sécurité de l'état actuel est faite d'abord)
   rm storage/maintenance.lock
   ```
3. Une migration ne se « défait » pas : seule la restauration de la sauvegarde ramène le schéma d'avant. Les modifications faites **depuis** la sauvegarde sont perdues.

Le retour arrière n'a été exercé que sur une base de test (voir « Validation » ci-dessous).

## 6. Supervision

- **Sonde HTTP** (UptimeRobot, Uptime Kuma, supervision de l'hébergeur) : `GET https://votre-domaine/api/v1/health` → 200 `{"status":"ok","database":"ok"}` ; 503 si la base est inaccessible ou si la maintenance est active.
- **Cron de contrôle** (alerte par e-mail de cron en cas d'échec) :
  ```cron
  5 * * * * /usr/local/bin/php /home/utilisateur/rendezvous-manager/scripts/healthcheck.php > /dev/null || echo "RendezVous Manager : anomalie" 
  ```
- Journaux : `storage/logs/app-AAAA-MM-JJ.log` (erreurs), `cron.log` ; à purger ou faire tourner à la main (pas de rotation automatique).
- **Sauvegardes** : copiez régulièrement `storage/backups/` **hors du serveur** (le script ne le fait pas) ; testez de temps en temps une restauration sur une base de test.

## 7. Sécurité à la mise en ligne

Lancez `php scripts/deploy_check.php --url=https://votre-domaine` après chaque changement de configuration d'hébergement. Il échoue si `.env`, le code ou la base de données sont téléchargeables, si l'assistant `/install` est ouvert, si le cookie de session n'est pas sécurisé, si `APP_DEBUG` est actif, ou si les sauvegardes sont dans `public/`. Liste complète : `docs/securite.md`.

## 8. Validation (ce qui a été vérifié, et ce qui ne l'a pas été)

**Vérifié ici** (environnement de test, MariaDB 10.11) : `update.sh` de bout en bout sur un dépôt cloné (maintenance activée puis levée, sauvegarde, récupération du code, migration appliquée, contrôles) ; échec d'une migration (maintenance conservée, commande de retour arrière affichée, schéma inchangé) ; `deploy_check --url` contre un serveur dont la racine est `public/` (aucun fichier exposé) et contre un serveur dont la racine est le dépôt (13 fichiers exposés détectés) ; tests automatiques des contrôles, du mode maintenance et de l'en-tête HSTS.

**Non vérifié** : tout ce qui dépend de votre hébergeur — AutoSSL, redirection HTTPS, version de PHP et chemins de cPanel, cron réel, `git` en ligne de commande sur le mutualisé, HSTS en conditions réelles, `.htaccess` sous Apache, configuration nginx complète (le fichier d'exemple n'a pas été chargé dans un vrai nginx), MySQL 8. Le script `scripts/update.sh` suppose `bash` et `git` sur le serveur.
