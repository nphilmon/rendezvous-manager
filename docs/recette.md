# Recette : critères d'acceptation du cahier des charges

Chaque critère du § 27 est relié à ce qui le vérifie. **Vérifié automatiquement** = test de la suite (`php tests/run.php`, ou test navigateur `tests/e2e/*.e2e.js` lancé par `tests/e2e/run_all.sh`). **Vérifié sur l'hébergement réel** = exécuté sur le serveur de production décrit dans `docs/deploiement.md`. Les écarts et les points non vérifiés sont listés à la fin : aucun n'est caché.

| # | Critère | Vérification | État |
|---|---|---|---|
| 1 | Installation initiale guidée | `InstallerTest` (jeton, CSRF, étapes, reprise, verrouillage) ; assistant déroulé en production | Automatique + réel |
| 2 | Vérification des prérequis | `InstallerTest` (PHP, extensions, écriture) ; page « Prérequis » en production | Automatique + réel |
| 3 | Configuration MySQL | `InstallerTest` (privilèges testés réellement, secrets jamais renvoyés) ; MariaDB 11.4 en production | Automatique + réel |
| 4 | Test de connexion | idem ; messages d'erreur distincts (accès refusé, base inconnue) vus en production | Réel |
| 5 | Premier administrateur | `InstallerTest`, `DefaultCalendarTest` ; créé en production | Automatique + réel |
| 6 | Connexion utilisateur | `AuthTest` (11 tests : cookies, force brute, CSRF, expiration…) ; connexion en production | Automatique + réel |
| 7 | Calendrier en grille | `calendar.e2e.js` (colonnes, chevauchements, fuseaux) | Automatique |
| 8 | Vues jour, semaine, mois, planning | `calendar.e2e.js`, `CalendarApiTest` | Automatique |
| 9 | Création par clic ou sélection | `appointments.e2e.js` (clic, glissement, formulaire, touche C) | Automatique |
| 10 | Déplacement | `dragdrop.e2e.js` (souris, tactile par événements Chromium, clavier) | Automatique |
| 11 | Redimensionnement | `dragdrop.e2e.js` | Automatique |
| 12 | Récurrences | `RRuleTest` (14), `RecurringAppointmentTest` (19), `recurrence.e2e.js` (toutes portées, exceptions, DST) | Automatique |
| 13 | Contacts | `ManagementApiTest`, `manage.e2e.js` | Automatique |
| 14 | Catégories | idem | Automatique |
| 15 | Partage des calendriers | `ManagementApiTest`, `manage.e2e.js` (droits lire/créer/modifier/supprimer/partager) | Automatique |
| 16 | Synchronisation multi-utilisateurs | `SyncApiTest`, `sync.e2e.js` (deux navigateurs, partage retiré, coupure réseau) | Automatique |
| 17 | Notifications autonomes côté serveur | `NotificationEngineTest` (dont 4 workers en parallèle, 300 rappels envoyés une fois), `notifications.e2e.js` ; cron actif en production | Automatique + réel |
| 18 | Web Push | `WebPushCryptoTest` (vecteur RFC 8291), `PushDeliveryTest` (faux service push qui vérifie VAPID et déchiffre), `push.e2e.js` | Automatique ; **aucun service push réel testé** |
| 19 | Rappels par e-mail | `SmtpMailerTest`, `EmailDeliveryTest` (faux SMTP avec TLS vérifié) | Automatique ; **aucun SMTP réel testé** |
| 20 | Exports | `TransferTest` (CSV, XLSX, PDF, ICS, formules neutralisées), `transfer.e2e.js` | Automatique |
| 21 | Importations | `TransferTest` (simulation, erreurs par ligne, doublons, ICS) | Automatique (import XLSX non fourni) |
| 22 | Permissions | `AccessMatrixTest` (toutes les routes, quatre rôles), `AuthTest`, tests de chaque API | Automatique |
| 23 | Administration avec phpMyAdmin | `docs/phpmyadmin.md`, `docs/serveur_linux.md` § 5 | **Documenté, non installé ni testé ici** |
| 24 | Sauvegardes (automatiques, manuelles, chiffrées, versionnées, vérifiées, restaurables) | `BackupAuditTest`, `BackupEncryptionTest` (chiffrement, altération, troncature, mauvaise clé), `audit.e2e.js` ; restauration vers une autre base répétée de bout en bout ; sauvegarde réelle en production | Automatique + réel (restauration réelle en production non exercée volontairement) |
| 25 | Recherche et filtres (§ 20), rapports (§ 5), rappels (§ 18) | `SearchRemindersTest` (recherche globale, filtres combinés, rappels), `ReportsTest`, `appointments.e2e.js`, `reports.e2e.js` | Automatique |
| 26 | Ordinateur et mobile | captures et tests à 390 px (menu mobile, calendrier, tactile) | Émulation Chromium ; **aucun appareil réel** |

## Tests obligatoires (§ 24)

| Thème | Où |
|---|---|
| Installation, prérequis, connexion MySQL | `InstallerTest` |
| Authentification, permissions, sécurité | `AuthTest`, `AccessMatrixTest`, `CodeHygieneTest` |
| Création, modification, suppression, restauration | `AppointmentCrudTest`, `appointments.e2e.js` |
| Glisser-déposer, redimensionnement | `dragdrop.e2e.js` |
| Conflits | `AppointmentCrudTest`, `appointments.e2e.js` |
| Récurrences et exceptions | `RRuleTest`, `RecurringAppointmentTest`, `recurrence.e2e.js` |
| Vues jour, semaine, mois | `CalendarApiTest`, `calendar.e2e.js` |
| Synchronisation, multi-utilisateurs | `SyncApiTest`, `sync.e2e.js` |
| Notifications serveur, Web Push, e-mail | `NotificationEngineTest`, `PushDeliveryTest`, `EmailDeliveryTest`, `notifications.e2e.js`, `push.e2e.js` |
| Exports, importations | `TransferTest`, `transfer.e2e.js` |
| Sauvegardes, migrations | `BackupAuditTest`, `MigrationTest` |
| Tableau de bord, paramètres, administration | `DashboardTest`, `SettingsUiTest`, `UserAdminTest`, `dashboard.e2e.js`, `settings.e2e.js`, `users.e2e.js` |

Les tests utilisent une base **isolée** dont le nom doit finir par `_test` (refus sinon) ; les scripts navigateur refusent aussi toute base qui ne finit pas par `_test` et tout serveur où `storage/install.lock` existe déjà.

## Écarts assumés par rapport au cahier des charges

| Demandé | Réalisé | Pourquoi |
|---|---|---|
| FullCalendar (timeGrid, dayGrid) | **Grille écrite pour le projet**, sans bibliothèque | évite les questions de licence des fonctions utilisées et toute dépendance ; couverte par les tests |
| Tailwind (CDN en développement, CSS compilé en production) | **CSS écrit à la main**, statique, aucun CDN | rien à compiler, CSP stricte sans style en ligne |
| Composer pour les bibliothèques nécessaires | `composer.json` sans paquet tiers ; chargeur de secours | aucune bibliothèque nécessaire |
| Sauvegardes **chiffrées** | **Chiffrées** (AES-256-GCM) dès que `BACKUP_KEY` est renseignée ; en clair sinon | clé à conserver hors du serveur ; secrets de `.env` non chiffrés au repos |
| Server-Sent Events « éventuellement » | Interrogation périodique (30 s par défaut, 2 à 300) | première implémentation prévue par le cahier |
| Langue, format de date (§ 21) | Français uniquement ; format de date fixe | non réalisé |
| Couleurs et calendriers affichés (§ 21) | couleurs par calendrier/catégorie ; masquage de calendriers conservé **dans le navigateur** | non enregistré sur le serveur |
| « État du serveur, version de la base, migrations » dans l'administration | panneau « État du serveur » dans Paramètres (administrateur) et `GET /api/v1/system` | réalisé |
| Audit des « changements de permissions » | `calendar.permissions_changed` (droits avant/après par utilisateur) ; rôles et comptes via `user.*` | réalisé |

## Non vérifié

- Services externes réels : Web Push (FCM, Mozilla, Apple), SMTP.
- MySQL 8 (testé : MariaDB 10.11 en test, 11.4 en production) ; TLS `verify` contre une base distante.
- PHP 8.3 en production (le serveur fournit PHP 8.5.13 : l'application y fonctionne) ; PHP 8.4 non essayé.
- Navigateurs : Chromium seulement (pas Firefox ni Safari) ; appareils mobiles réels ; lecteurs d'écran (accessibilité au clavier testée, pas d'audit complet).
- Charge (nombreux utilisateurs, grosses bases), restauration de grosses sauvegardes.
- Apache sous `.htaccess` racine de secours ; configuration nginx complète ; cron de fournisseurs autres que cPanel.
- Aucun audit de sécurité externe.
