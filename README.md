# RendezVous Manager 4.0 Web

Plateforme web professionnelle de gestion de rendez-vous, centrée sur un calendrier interactif en grille (jour, semaine, mois, planning) inspiré du fonctionnement de Google Agenda, sans en reprendre l'identité graphique.

> **État du projet : développement en cours (phases 1 à 4 terminées).**
> Les fonctionnalités listées dans « Objectifs » ne sont **pas** toutes livrées : voir « Avancement » ci-dessous pour ce qui existe réellement.

## Objectifs

- Calendrier en grille interactif : vues jour, semaine, mois, planning, multi-calendriers.
- Création par clic ou sélection, déplacement par glisser-déposer, redimensionnement, gestion des chevauchements, alternative clavier/formulaire, interactions tactiles.
- Rendez-vous complets : récurrences (modèle compatible iCalendar RRULE, exceptions, modification d'une occurrence / des suivantes / de la série), suppression logique, restauration, duplication, report, annulation, contrôle de concurrence optimiste, détection des conflits.
- Contacts, calendriers (couleur, visibilité, partage) et catégories configurables.
- Notifications autonomes côté serveur (cron + workers PHP, réservation atomique, tentatives, anti-doublons), Web Push (VAPID), centre de notifications, e-mail SMTP optionnel.
- Synchronisation multi-utilisateurs par curseur serveur (polling 30–60 s, SSE envisageable).
- Utilisateurs, rôles (Administrateur, Responsable, Utilisateur, Lecture seule) et permissions par calendrier, appliqués côté serveur.
- Tableau de bord, recherche globale avec filtres combinés, paramètres, administration.
- Import/export CSV, XLSX, PDF, ICS (protection contre l'injection de formules).
- Sauvegardes chiffrées, versionnées, vérifiées et restaurables ; journal d'audit.
- Assistant d'installation web sécurisé (jeton à usage unique, verrouillé après configuration).
- Interface en français, responsive (ordinateur, tablette, smartphone).

## Pile technique

| Couche | Choix |
|---|---|
| Backend | PHP 8.3+ natif, MVC léger, PDO, API REST JSON, Composer |
| Base de données | MySQL 8 ou MariaDB (InnoDB, utf8mb4, migrations versionnées), instants stockés en UTC |
| Frontend | HTML5, CSS3, JavaScript natif, Fetch API, FullCalendar (licences à vérifier), Tailwind (CDN en développement uniquement ; CSS compilé en production) |
| Serveur | Apache ou Nginx, HTTPS, cron |
| Administration | phpMyAdmin (accès restreint) |

**Exclus** : Docker, SQLite, React, Next.js, Node.js/NestJS côté serveur, Electron, backend Python.

## Structure prévue

```
RendezVous-Manager-Web/
├── public/        # seule racine web (index, login, calendar, dashboard, assets, service-worker)
├── app/           # Controllers, Models, Repositories, Services, Middleware, Security, Validation, Views
├── api/           # routes REST (/api/v1/...)
├── config/        # app, database, notifications (hors zone publique)
├── database/      # schema.sql, migrations, seeds
├── installer/     # assistant de première installation
├── workers/       # reminder, push, cleanup (cron)
├── scripts/       # migrate, backup, healthcheck
├── tests/         # Unit, Integration, Security
├── docs/          # installation, MySQL, phpMyAdmin, notifications, API, sécurité, utilisation
└── storage/       # logs, exports (non accessibles depuis le web)
```

## Principes de sécurité

- Le serveur web ne doit pointer que vers `public/`.
- Identifiants SQL/SMTP uniquement côté serveur, hors zone publique, jamais renvoyés au navigateur ; aucun secret en dur (`.env`).
- Mots de passe hachés avec Argon2id lorsque disponible ; protection anti-force brute.
- Sessions sécurisées (cookies Secure, HttpOnly, SameSite), protection CSRF, CORS restrictif, limitation des requêtes sensibles.
- Requêtes SQL paramétrées, validation systématique côté serveur, permissions vérifiées à chaque opération.
- Aucune désactivation de la vérification TLS.
- Journalisation d'audit sans mots de passe ni jetons.

## Plan de développement

1. Architecture et fichiers
2. Base MySQL et migrations
3. Authentification et permissions
4. Assistant d'installation
5. Interface web générale
6. Calendrier en grille
7. CRUD rendez-vous
8. Glisser-déposer et redimensionnement
9. Récurrences
10. Contacts et catégories
11. Synchronisation multi-utilisateurs
12. Notifications autonomes serveur
13. Web Push et e-mail
14. Tableau de bord
15. Import / export
16. Administration
17. Sauvegardes et audit
18. Tests
19. Documentation
20. Déploiement

## Avancement

| Phase | État |
|---|---|
| 1. Architecture et fichiers | Terminée : noyau PHP (Env, Config, Database PDO avec TLS, Request/Response/Router, Logger avec masquage des secrets), API `GET /api/v1/health` et `GET /api/v1/version`, config, `.htaccess`, exemple Nginx |
| 2. Base MySQL et migrations | Terminée : 4 migrations (19 tables dont `schema_migrations`, plus `login_attempts` et `password_resets`), migrateur avec sommes de contrôle, `scripts/migrate.php`, `database/schema.sql` généré, rôles et permissions initiaux |
| 3. Authentification et permissions | Terminée : `POST /api/v1/auth/login`, `POST /auth/logout`, `GET /auth/me`, `POST /auth/password` ; Argon2id, sessions en base (jeton haché SHA-256, cookie HttpOnly/SameSite=Lax/Secure), expiration absolue et par inactivité, jeton CSRF + contrôle d'Origin, blocage anti-force brute (par identifiant et par IP, HTTP 429), audit sans secrets, permissions de rôle et de calendrier (`PermissionService`) |
| 4. Assistant d'installation | Terminée : `/install` en 7 étapes (bienvenue, prérequis, MySQL/MariaDB, vérification et migrations, administrateur, services, finalisation), jeton à usage unique généré en CLI (`php scripts/install_token.php`, seul le hachage est stocké, valable 2 h, invalidé après 10 échecs), verrou `storage/install.lock` après installation (l'assistant répond alors 404), `.env` écrit en 0600, aucune table existante jamais supprimée, base non reconnue refusée, reprise d'une installation existante avec conservation des données |
| 5 à 20 | Non commencées |

Limites de la phase 4 : les prérequis « cron », « Web Push » et « SMTP » ne sont que **signalés** (aucun envoi ni exécution de worker n'est testé) ; la page de connexion `/login` n'existe pas encore (phase 5), la redirection finale y mène donc vers un message provisoire ; la création automatique de la base n'est pas proposée (elle doit exister) ; le mode TLS « verify » n'a pas été testé contre un serveur TLS réel.

Non fait dans la phase 3 : réinitialisation de mot de passe par e-mail (dépend du SMTP, phases 13/16), gestion des comptes (`/users`, phase 16), interface de connexion (phase 5). Le login lui-même n'est pas protégé par CSRF (aucune session n'existe encore).

Il n'y a pas encore d'interface web de connexion ni de calendrier. L'assistant d'installation a été essayé via le serveur intégré de PHP (redirection `/` → `/install`, page de jeton, en-têtes CSP et cookie) mais le parcours complet n'a été exécuté que par les tests automatisés.

### Vérifié

- 37 tests (`php tests/run.php`) passent : unitaires (Env, routeur, découpage SQL, journalisation, mots de passe), migrations (tables, idempotence, seed, InnoDB/utf8mb4, contraintes, rejet d'une migration modifiée) assistant d'installation (jeton, CSRF, prérequis, test et privilèges SQL, secrets non renvoyés, base étrangère intacte, flux complet puis verrouillage, reprise avec données conservées, échappement XSS), et sécurité de l'authentification (cookies, jeton non stocké en clair, réponse identique pour compte inconnu, force brute, CSRF/Origin, expiration, désactivation, révocation après changement de mot de passe, audit, permissions de rôle et de calendrier).
- Les tests d'intégration ont été exécutés **uniquement sur MariaDB 10.11**. **MySQL 8 n'a pas été testé.**
- Les tests d'authentification appellent le routeur directement (sans serveur HTTP ni HTTPS réels). Seules `/api/v1/version` et `/api/v1/health` ont été appelées via le serveur intégré de PHP. La connexion TLS à la base n'a pas été testée.

## Développement

```bash
composer install            # optionnel : un autoloader de secours existe
cp .env.example .env        # puis renseigner DB_*
php scripts/install_token.php  # jeton pour l'assistant web /install
php scripts/migrate.php     # applique les migrations (--status pour l'état)
php scripts/build_schema.php  # régénère database/schema.sql
```

Tests (la base doit se nommer `*_test`, jamais la production) :

```bash
TEST_DB_USER=... TEST_DB_PASSWORD=... TEST_DB_NAME=rendezvous_manager_test php tests/run.php
```

Sans variables `TEST_DB_*`, les tests d'intégration sont ignorés.

## Installation

La procédure détaillée (préparation Linux, Apache/Nginx, PHP, MySQL/MariaDB, phpMyAdmin, HTTPS, pare-feu, Composer, assistant d'installation, cron, Web Push, SMTP) sera fournie dans `docs/installation.md` une fois l'application réalisée. Aucune installation complète sur un serveur distant n'a été effectuée ni testée à ce stade.

## Licence

À définir.
