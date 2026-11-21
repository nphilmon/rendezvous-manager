# Déploiement pas à pas sur un serveur Linux

Ce guide suit les 20 étapes du cahier des charges (§ 25) pour un serveur Linux que vous administrez (VPS ou dédié, Debian 12 ou Ubuntu 24.04). Pour un hébergement mutualisé cPanel, suivez plutôt `docs/deploiement.md` § 1 ; les étapes 15 à 20 ci-dessous valent dans les deux cas.

> **Ce qui a été vérifié** : les commandes propres à l'application (`php scripts/…`, assistant, cron, sauvegardes, `deploy_check`) ont été exécutées, y compris sur un vrai hébergement cPanel (PHP 8.5, MariaDB 11.4). Les commandes système ci-dessous (`apt`, `ufw`, `certbot`, configuration Apache/nginx/phpMyAdmin) sont les procédures standard Debian/Ubuntu ; elles **n'ont pas été rejouées sur un serveur vierge**. Aucun Docker n'est utilisé ni nécessaire.

## 1. Préparer le serveur

```bash
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone Europe/Paris        # l'application stocke tout en UTC ; ceci règle seulement les journaux
sudo adduser --disabled-password --gecos "" rvm   # (facultatif) compte dédié ; sinon utilisez www-data
```
Activez les mises à jour de sécurité automatiques : `sudo apt install -y unattended-upgrades`.

## 2. Installer le serveur web

**Apache** : `sudo apt install -y apache2 && sudo a2enmod rewrite headers ssl`
**ou nginx** : `sudo apt install -y nginx` (configuration : `docs/nginx.conf.example`).

## 3. Installer PHP 8.3 et ses extensions

```bash
sudo apt install -y php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl php8.3-zip php8.3-intl
sudo apt install -y libapache2-mod-php8.3          # Apache ; pour nginx : php8.3-fpm
php -v && php -m | grep -E "pdo_mysql|mbstring|openssl|json|curl|zip"
```
Sur Ubuntu LTS plus ancien, ajoutez le dépôt `ppa:ondrej/php`. `openssl` et `json` sont intégrés à PHP. Dans `php.ini` (web et CLI) : `expose_php = Off`, `display_errors = Off`, `date.timezone = UTC`.

## 4. Installer MariaDB (ou MySQL 8)

```bash
sudo apt install -y mariadb-server
sudo mariadb-secure-installation                   # mot de passe root, supprime les comptes anonymes et la base test
```
Créez la base et l'utilisateur dédié (`docs/configuration_mysql.md`). **Ne laissez pas le port 3306 ouvert sur Internet** (voir étape 7) : base sur la même machine, `bind-address = 127.0.0.1`.

## 5. Installer phpMyAdmin (accès restreint)

```bash
sudo apt install -y phpmyadmin                     # choisissez Apache2 ; configurez avec dbconfig-common
```
Durcissement indispensable (voir aussi `docs/phpmyadmin.md`) :
1. **Ne l'exposez pas sur `/phpmyadmin`** : renommez l'alias dans `/etc/apache2/conf-available/phpmyadmin.conf` (par exemple `/gestion-bdd-<chaîne aléatoire>`), puis `sudo a2enconf phpmyadmin`.
2. **Restreignez par adresse IP** dans ce même fichier : `Require ip <votre.adresse.ip>` (ou accès par tunnel SSH : `ssh -L 8081:127.0.0.1:80 …`).
3. **Double authentification** : ajoutez une authentification HTTP (`AuthType Basic`, `htpasswd`) devant la page de connexion de phpMyAdmin.
4. HTTPS obligatoire, `AllowRoot` à `false` dans `config.inc.php`, utilisateur SQL dédié, jamais le compte `root`.
5. Sous nginx, configurez-le de la même façon (bloc `location` avec `allow`/`deny` et `auth_basic`).

## 6. Configurer HTTPS

```bash
sudo apt install -y certbot python3-certbot-apache      # ou python3-certbot-nginx
sudo certbot --apache -d rendezvous.example.org         # ou --nginx
sudo certbot renew --dry-run                            # le renouvellement est automatique
```
Vérifiez que HTTP redirige vers HTTPS, puis, quand HTTPS est définitif, `HSTS_MAX_AGE=31536000` dans `.env` (commencez par `86400` pour tester).

## 7. Configurer le pare-feu

```bash
sudo apt install -y ufw
sudo ufw default deny incoming && sudo ufw default allow outgoing
sudo ufw allow OpenSSH && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp
# SSH : restreindre à votre adresse si elle est fixe →  sudo ufw allow from <votre.ip> to any port 22
sudo ufw enable && sudo ufw status verbose
```
Le port **3306 (MySQL) reste fermé** : l'application se connecte en local. Pour une base distante, autorisez seulement l'adresse du serveur applicatif et activez TLS `verify` (`docs/configuration_mysql.md`). Complétez par `fail2ban` pour SSH (`sudo apt install -y fail2ban`).

## 8. Déployer les fichiers

```bash
sudo git clone <URL-du-dépôt> /var/www/rendezvous
sudo chown -R www-data:www-data /var/www/rendezvous/storage
sudo chmod 750 /var/www/rendezvous && sudo chmod -R u+rwX,g+rX,o-rwx /var/www/rendezvous
```
Racine web = `/var/www/rendezvous/public` uniquement (`docs/deploiement.md` §§ 2-3 pour les fichiers de site Apache et nginx).

## 9. Dépendances Composer

Le projet n'utilise **aucune bibliothèque tierce** : il fonctionne sans Composer, avec son chargeur de classes de secours. Composer n'est donc pas obligatoire. Si vous le souhaitez (chargeur optimisé) :
```bash
sudo apt install -y composer
cd /var/www/rendezvous && composer install --no-dev --optimize-autoloader
```
L'assistant d'installation affiche cet état (« dépendances installées ou chargeur de secours »).

## 10. à 14. Assistant : installation, MySQL, test, initialisation, administrateur

Voir `docs/installation.md` § 4 : jeton (`php scripts/install_token.php`), puis `https://<domaine>/install` : prérequis → base (test de connexion, vérification des privilèges, TLS) → vérification et initialisation (« Initialiser la base », jamais d'effacement de tables existantes) → compte administrateur → services → finalisation (assistant verrouillé).

## 15. Tâches cron

`docs/installation.md` § 6 (rappels chaque minute, nettoyage et sauvegarde chaque nuit), avec le chemin complet de PHP (`which php`) et l'utilisateur du serveur web (`sudo -u www-data crontab -e`).

## 16. Configurer Web Push

```bash
cd /var/www/rendezvous
php scripts/generate_vapid.php --subject=mailto:admin@votre-domaine.example
php scripts/healthcheck.php          # la ligne « push » doit passer à « Clés VAPID valides »
```
- La commande écrit `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` et `VAPID_SUBJECT` dans `.env` (droits 0600). Le contact `mailto:` est transmis aux services push : utilisez une adresse de service, pas une adresse personnelle.
- Exigences : HTTPS valide et extension `curl`. Les navigateurs s'abonnent depuis **Paramètres → Notifications push → Activer sur cet appareil** ; un bouton envoie un test.
- **Changer la paire de clés invalide tous les abonnements** (`--force` pour le faire volontairement).
- La réception n'est jamais garantie (navigateur fermé, économie d'énergie, iOS hors application installée). Détails : `docs/notifications.md`.

## 17. Configurer le SMTP (facultatif)

Dans `.env` (jamais dans un fichier accessible au web) :
```
MAIL_ENABLED=true
MAIL_HOST=smtp.exemple.org
MAIL_PORT=587
MAIL_ENCRYPTION=tls          # tls = STARTTLS (587) ; ssl = TLS implicite (465) ; jamais « none » hors localhost
MAIL_USER=agenda@exemple.org
MAIL_PASSWORD=…
MAIL_FROM=agenda@exemple.org
```
- Le certificat du serveur est **toujours vérifié** ; si votre fournisseur utilise une autorité privée, renseignez `MAIL_CA_FILE`. Il n'y a aucun repli en clair.
- Vérifiez : `php scripts/healthcheck.php` (ligne `email`), puis **Paramètres → Rappels par e-mail → Envoyer un e-mail de test**.
- Sur cPanel : créez une boîte (E-mail → Comptes e-mail), relevez le serveur sortant dans « Configurer le client de messagerie ».

## 18. Vérifier les sauvegardes

```bash
php workers/backup_worker.php                      # crée et vérifie une sauvegarde
ls -l storage/backups                              # fichier .sql.gz + .json, droits 0600
php scripts/restore.php storage/backups/rvm-….sql.gz   # vérification seule, rien n'est modifié
```
**Testez une restauration sur une base distincte** (cahier des charges § 22) : créez une base `…_restauration`, pointez temporairement un `.env` de test dessus, puis `php scripts/restore.php <fichier> --yes`, et comparez le nombre de lignes (`php scripts/deploy_check.php`, page *Utilisateurs*, un rendez-vous). Copiez régulièrement `storage/backups/` **hors du serveur**. Les sauvegardes ne sont pas chiffrées (limite connue) : chiffrez la copie externe (`gpg -c fichier`).

## 19. Tester les calendriers

Liste de recette à dérouler dans un navigateur (ordinateur puis téléphone) :
1. Connexion ; « Mon calendrier » existe (Calendriers).
2. Vues Jour, Semaine, Mois, Planning ; navigation et mini-calendrier.
3. Création par clic, par glissement et par le bouton ; conflit horaire signalé.
4. Déplacement et redimensionnement par glisser-déposer ; alternative au clavier (formulaire).
5. Série récurrente : modifier une occurrence, « celle-ci et les suivantes », toute la série ; supprimer et restaurer.
6. Partage d'un calendrier avec un second compte ; ouvrir une seconde session et vérifier que le changement apparaît en moins d'une minute.
7. Rappel dans 2 minutes : la notification arrive dans l'application (puis par push/e-mail si configurés).
8. Export CSV/ICS, import d'un fichier de test (simulation d'abord).

## 20. Vérifier la sécurité et les permissions

```bash
php scripts/deploy_check.php --url=https://rendezvous.example.org
```
Aucun « ÉCHEC » ; avertissements acceptés en connaissance de cause. Puis la liste de `docs/securite.md` : rôles (un compte « lecture seule » ne peut rien écrire), `/.env`, `/app/`, `/storage/`, `/install` inaccessibles, `chmod 600 .env`, phpMyAdmin restreint, port 3306 fermé, sauvegardes copiées hors du serveur.
