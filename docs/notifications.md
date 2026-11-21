# Notifications autonomes (serveur)

Les rappels sont envoyés par le **serveur**, sans qu'aucune page du calendrier ne soit ouverte.

## Mise en service

1. Appliquer les migrations (`php scripts/migrate.php`).
2. Planifier le worker de rappels par cron (chaque minute) :

   ```cron
   * * * * * www-data php /var/www/rendezvous/workers/reminder_worker.php >> /var/www/rendezvous/storage/logs/cron.log 2>&1
   17 3 * * * www-data php /var/www/rendezvous/workers/cleanup_worker.php >> /var/www/rendezvous/storage/logs/cron.log 2>&1
   ```

   `reminder_worker.php --loop` répète le cycle toutes les 10 s pendant ~55 s : une seule ligne cron suffit alors à réduire la latence.
3. Vérifier : le worker écrit `storage/logs/cron_heartbeat` à chaque passage (l'assistant d'installation et `scripts/healthcheck.php` s'y fient).

## Fonctionnement

1. **Reprise** des réservations abandonnées (`processing` depuis plus de 5 min : remises en `pending`, ou `failed` si les 5 tentatives sont épuisées).
2. **Planification** : pour chaque rappel (`reminders`) dont l'heure de déclenchement tombe entre « maintenant − 24 h » et « maintenant » (5 min si l'utilisateur a choisi « ne pas rattraper »), une ligne `notification_deliveries` est créée. La clé unique (rappel, début d'origine de l'occurrence, canal) rend l'opération idempotente. Un seul worker planifie à la fois (`GET_LOCK`).
3. **Réservation atomique** par lots de 100 : `UPDATE … WHERE status = 'pending' … LIMIT n` ; deux workers ne reçoivent jamais la même ligne. Les interblocages InnoDB (1213/1205) sont rejoués.
4. **Validation à l'envoi** : rendez-vous supprimé, annulé, terminé, déplacé, occurrence annulée, rappel retiré, compte désactivé, accès au calendrier retiré ou notifications désactivées → ligne `cancelled`.
5. **Envoi par canal** (`ChannelSender`) ; échec temporaire → nouvelle tentative avec repli (1 min, 5 min, 15 min, 1 h, 6 h), échec définitif ou 5 tentatives → `failed`. Une exception de l'expéditeur compte comme échec temporaire.
6. **Heures silencieuses** : seuls les canaux autres que `inapp` sont différés (sans consommer de tentative).

Canaux : `inapp` (la ligne `sent` est la notification du centre de notifications ; le navigateur est prévenu par la synchronisation), `push` (Web Push) et `email` (SMTP). Un canal n'est proposé aux utilisateurs que si le serveur est configuré pour l'envoyer (voir ci-dessous) ; tant qu'aucun expéditeur n'est enregistré pour un canal, ses lignes restent `pending` sans être perdues.

## Web Push

1. Générer les clés VAPID (une seule fois) : `php scripts/generate_vapid.php --subject=mailto:admin@exemple.org`. Elles sont écrites dans `.env` (0600). **Changer la paire invalide tous les abonnements** (`--force` pour la remplacer).
2. Le site doit être servi en **HTTPS** (les navigateurs refusent Push API et service workers sinon, hors `localhost`).
3. Chaque utilisateur active les notifications sur chaque appareil depuis *Paramètres → Notifications push* : permission du navigateur, enregistrement du service worker (`/service-worker.js`), abonnement, puis envoi d'un test.
4. Le serveur chiffre chaque message (RFC 8291, `aes128gcm`) et le signe avec un jeton VAPID (RFC 8292, ES256) avant de l'envoyer au service push du navigateur (FCM, Mozilla, Apple…). L'extension `curl` est requise.
5. Un point d'accès expiré (HTTP 404/410) est supprimé ; un refus répété (400/401/403) supprime l'abonnement après 5 échecs ; 429/5xx/réseau → nouvelle tentative avec repli.

**Sécurité (SSRF)** : le serveur appelle l'URL fournie par le navigateur. Elle doit être en `https`, appartenir à un service push reconnu (`notifications.push.allowed_hosts` dans `config/notifications.php`, à compléter pour un service privé) et se résoudre uniquement vers des adresses publiques ; l'adresse vérifiée est épinglée pour l'appel. `PUSH_ALLOW_ANY=1` désactive ces garde-fous : développement et tests uniquement.

La réception n'est **jamais garantie** : elle dépend du navigateur, du système, de l'économie d'énergie et de la permission accordée.

## E-mail (SMTP)

Dans `.env` : `MAIL_ENABLED=true`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION` (`tls` = STARTTLS, port 587 ; `ssl` = TLS implicite, port 465 ; `none` uniquement vers `localhost`), `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_FROM`, et `MAIL_CA_FILE` si le serveur utilise une autorité privée. Le certificat **et le nom d'hôte** sont toujours vérifiés ; il n'y a aucun repli en clair si STARTTLS est absent ; les identifiants ne sont envoyés qu'après chiffrement. Les secrets SMTP ne sont jamais renvoyés au navigateur. Le bouton *Envoyer un e-mail de test* (Paramètres) valide la configuration.

## Workers

- `workers/reminder_worker.php` : planifie, envoie *inapp* et, si configurés, *push* et *e-mail* (une seule entrée cron suffit).
- `workers/push_worker.php` : n'envoie que *push* et *e-mail* déjà planifiés (sans planifier) ; utile pour isoler un service push ou un SMTP lents du reste.
- `php scripts/healthcheck.php [--json]` : état de la base, des migrations, du worker, des clés VAPID et du SMTP (code de sortie utilisable par une supervision).

## Réglages utilisateur (`GET/PUT /api/v1/settings`, page Paramètres)

`enabled`, `channels` (`inapp` toujours, plus `push` et `email` lorsque le serveur les propose et que l'utilisateur les active), `privacy` (`full` | `minimal`), `missed` (`deliver` | `skip`), `quiet_start` / `quiet_end` (HH:MM, heure locale), `default_reminder` (minutes).

## Séries et journées entières

Les occurrences des séries sont dépliées à l'exécution (aucune ligne par occurrence). Une occurrence déplacée déclenche son rappel à sa nouvelle heure, mais reste identifiée par son début d'origine. Le rappel d'une journée entière se déclenche à minuit dans le fuseau de l'utilisateur (moins le délai choisi).
