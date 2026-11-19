# Configuration de MySQL / MariaDB

## Versions et réglages

| | |
|---|---|
| Serveurs pris en charge | MySQL 8.0+, MariaDB 10.6+ (**testé uniquement sur MariaDB 10.11** ; MySQL 8 non testé ici) |
| Moteur | InnoDB (clés étrangères, transactions, verrous) |
| Jeu de caractères | `utf8mb4`, collation `utf8mb4_unicode_ci` |
| Heures | l'application stocke **toutes les heures en UTC** ; le fuseau du serveur de base n'a pas d'incidence |
| `sql_mode` | les valeurs par défaut conviennent (l'application n'exige pas de mode particulier) |

## Créer la base et l'utilisateur

Dans cPanel : *Bases de données MySQL* → créer la base, créer l'utilisateur, **ajouter l'utilisateur à la base avec TOUS LES PRIVILÈGES**. Sur un serveur que vous administrez :

```sql
CREATE DATABASE rendezvous_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'rvm'@'localhost' IDENTIFIED BY 'un-mot-de-passe-long-et-unique';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, LOCK TABLES
  ON rendezvous_manager.* TO 'rvm'@'localhost';
```

- Utilisez un utilisateur **dédié à cette seule base**, jamais `root`.
- Privilèges : l'assistant les **teste réellement** (création de table, clé étrangère, ajout de colonne, index, insertion, mise à jour, lecture, suppression, `DROP` d'une table temporaire) et indique ceux qui manquent. `DROP` et `ALTER` sont nécessaires aux migrations et à la restauration d'une sauvegarde. `LOCK TABLES` n'est pas requis (les sauvegardes utilisent une transaction cohérente) mais ne gêne pas.
- Le mot de passe ne doit pas contenir à la fois des guillemets simples et doubles (limite du fichier `.env`).

## Connexion et TLS

`DB_SSL_MODE` dans `.env` :

| Mode | Effet | Quand l'utiliser |
|---|---|---|
| `disabled` | aucun chiffrement | base sur la même machine (`localhost`), réseau de confiance |
| `required` | connexion chiffrée, certificat non vérifié | à éviter : protège de l'écoute, pas d'une usurpation |
| `verify` | chiffrée **et** certificat/identité du serveur vérifiés | base distante (recommandé) ; renseigner `DB_SSL_CA` si l'autorité n'est pas connue du système |

Pour une base sur la même machine, `DB_HOST=localhost` (ou `127.0.0.1`) avec `DB_SSL_MODE=disabled` est normal. Le mode `verify` n'a été testé qu'avec un faux serveur de test, **pas contre un vrai serveur MySQL distant**.

## Structure

Le schéma est créé par des migrations versionnées (`database/migrations/*.sql`, appliquées dans l'ordre, somme de contrôle conservée dans `schema_migrations`). `database/schema.sql` est un instantané généré (`php scripts/build_schema.php`) à titre de référence : une installation normale utilise `php scripts/migrate.php` ou l'assistant.

Principales tables : `users`, `roles`, `permissions`, `role_permissions`, `user_sessions`, `login_attempts`, `calendars`, `calendar_permissions`, `appointments`, `appointment_recurrence`, `appointment_exceptions`, `contacts`, `categories`, `reminders`, `notification_deliveries`, `push_subscriptions`, `settings`, `audit_logs`, `sync_changes`, `schema_migrations`.

## Sauvegarde et restauration

- Sauvegarde logique intégrée : page **Sauvegardes** ou `workers/backup_worker.php` (voir `docs/installation.md`). Elle n'exige pas `mysqldump`.
- Restauration : `php scripts/restore.php NOM.sql.gz` (vérification seule) puis avec `--yes`. Les tables du fichier sont **remplacées**.
- Vous pouvez aussi utiliser vos propres outils (`mysqldump`, sauvegardes de l'hébergeur) : ils sont complémentaires.

## Performances et limites

Les index couvrent les recherches courantes (calendrier + période, utilisateur, curseur de synchronisation, notifications). Aucun test de charge n'a été réalisé ; la sauvegarde intégrée est prévue pour des bases de taille modeste.
