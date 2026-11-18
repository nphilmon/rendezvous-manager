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

Canaux : `inapp` est implémenté (la ligne `sent` est la notification du centre de notifications, et le navigateur est prévenu par la synchronisation). `push` et `email` seront branchés en phase 13 : tant qu'aucun expéditeur n'est enregistré, leurs lignes restent `pending` sans être perdues.

## Réglages utilisateur (`GET/PUT /api/v1/settings`, page Paramètres)

`enabled`, `channels` (seul `inapp` pour l'instant), `privacy` (`full` | `minimal`), `missed` (`deliver` | `skip`), `quiet_start` / `quiet_end` (HH:MM, heure locale), `default_reminder` (minutes).

## Séries et journées entières

Les occurrences des séries sont dépliées à l'exécution (aucune ligne par occurrence). Une occurrence déplacée déclenche son rappel à sa nouvelle heure, mais reste identifiée par son début d'origine. Le rappel d'une journée entière se déclenche à minuit dans le fuseau de l'utilisateur (moins le délai choisi).
