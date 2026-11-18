# RendezVous Manager 4.0 Web

Plateforme web professionnelle de gestion de rendez-vous, centrée sur un calendrier interactif en grille (jour, semaine, mois, planning) inspiré du fonctionnement de Google Agenda, sans en reprendre l'identité graphique.

> **État du projet : développement en cours (phases 1 à 8 terminées).**
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
| 5. Interface web générale | Terminée : page de connexion (`/login`, avec protection contre la redirection ouverte), gabarit à barre latérale + barre supérieure + mini-calendrier, navigation filtrée par permissions, thème clair/sombre/auto, menu utilisateur accessible au clavier, déconnexion, changement de mot de passe (page Paramètres), badge de notifications non lues, tableau de bord avec 4 compteurs réels, pages d'erreur 403/404, CSS statique (aucun CDN), CSP stricte sans script ni style en ligne, `manifest.webmanifest` |
| 6. Calendrier en grille | Terminée (lecture) : vues jour, semaine, mois et planning ; grille graphique native (sans FullCalendar ni autre dépendance) ; chevauchements répartis en colonnes ; journée entière ; rendez-vous annulés/provisoires distingués ; indicateur « maintenant » ; « + X autres » ; plusieurs calendriers avec couleur et affichage/masquage ; fenêtre de détails accessible ; navigation sans rechargement (boutons, mini-calendrier, raccourcis `t j k d w m a`) ; réglages d'affichage (début/fin de grille, pas 5-60 min, premier jour, week-end, 12/24 h) ; heures converties dans le fuseau de l'utilisateur ; API `GET /api/v1/calendars` et `GET /api/v1/appointments?start&end&calendars` |
| 7. CRUD rendez-vous | Terminée : formulaire modal complet (titre, description, dates/heures, journée entière, calendrier, catégorie, contact, lieu, adresse, statut, priorité, couleur, rappel, notes ; Enregistrer / Enregistrer et nouveau / Supprimer / Annuler), création par clic, glissement de sélection et touche `c`, détails avec actions (modifier, dupliquer, reporter de 1 jour/1 semaine, annuler, terminer, supprimer, restaurer), suppression logique et corbeille, verrou optimiste (409 `version_conflict`, recharger ou écraser), détection de conflits horaires (409 `time_conflict`, « Enregistrer quand même »), validation serveur détaillée par champ, listes `/appointments`, `/mine`, `/today`, `/upcoming` avec recherche, filtres, tri et pagination, journal d'audit et `sync_changes` alimentés ; API `POST/GET/PUT/DELETE /api/v1/appointments`, `/{id}/restore|duplicate|cancel|complete|reschedule`, `GET /categories`, `GET /contacts` |
| 8. Glisser-déposer et redimensionnement | Terminée : déplacement des blocs de la grille (autre heure et/ou autre jour, alignés sur le pas), redimensionnement par les bords haut et bas (durée minimale = un pas), fantôme et libellé d'horaire en direct, Échap pour annuler, défilement automatique près des bords, déplacement par jours des rendez-vous « journée entière » et des lignes de la vue mois (heure conservée), appui long puis glissement au toucher, alternative clavier (Alt+↑/↓ déplace d'un pas, Alt+Maj+↑/↓ change la fin, Alt+←/→ change de jour), conflit horaire à l'arrivée avec confirmation, verrou optimiste (rechargement si modifié entre-temps), calendriers en lecture seule et séries récurrentes non déplaçables |
| 9 à 20 | Non commencées |

Limites de la phase 8 : les rendez-vous « journée entière » ne se redimensionnent pas (on les déplace) ; un rendez-vous ne peut pas être glissé entre la zone « journée entière » et la grille horaire ; le redimensionnement d'un rendez-vous sur plusieurs jours n'agit que sur le segment du jour concerné et reste dans la journée ; aucun effet d'annulation (« Ctrl+Z ») après un déplacement ; les séries récurrentes sont exclues volontairement jusqu'à la phase 9 (non couvert par les tests navigateur) ; l'appui long au toucher est vérifié avec des événements tactiles réels de Chromium (CDP), pas sur un appareil physique. Un défaut a été corrigé en route : après un glissement dans la même colonne, le « click » du navigateur ouvrait le formulaire de création.

Règles de la phase 7 : un conflit = chevauchement strict avec un autre rendez-vous **du même calendrier**, ni annulé, ni supprimé ; les rendez-vous « journée entière » ne bloquent pas et ne sont pas bloqués ; la création d'une copie ne contrôle pas les conflits ; un rendez-vous illisible renvoie 404 (pas 403) ; modifier le calendrier d'un rendez-vous exige le droit de modification sur l'ancien et de création sur le nouveau. L'API des rendez-vous « journée entière » prend des dates (`AAAA-MM-JJ`) avec **fin exclusive**, le formulaire affiche une fin inclusive.

Limites de la phase 7 : le déplacement et le redimensionnement par glisser-déposer des blocs existent côté API (`reschedule`) mais pas encore dans l'interface (phase 8) ; les récurrences ne sont pas gérées (phase 9) ; catégories et contacts ne sont qu'**en lecture** (listes alimentant les menus) — leur création est prévue en phase 10, les menus sont donc vides tant qu'aucune donnée n'existe ; le rappel enregistre une ligne `reminders` mais aucune notification n'est envoyée (phase 12) ; rappel « personnalisé » possible par l'API (en minutes), pas dans le formulaire ; le glissement de sélection est réservé à la souris (au toucher : appui simple) ; l'édition simultanée n'est pas diffusée en temps réel (phase 11), elle est détectée à l'enregistrement.

Limites de la phase 6 : **lecture seule** — création par clic/sélection, déplacement, redimensionnement et modification arrivent aux phases 7 et 8 (le pas horaire règle déjà la graduation) ; les séries récurrentes ne sont pas dépliées (phase 9) ; les réglages d'affichage sont mémorisés dans le navigateur (enregistrement serveur : phase 21) ; l'affichage ne se rafraîchit pas tout seul (phase 11) ; aucune virtualisation : l'API plafonne à 2000 rendez-vous et 100 jours par requête ; les jours de changement d'heure ne sont pas ajustés visuellement (23/25 h) ; les rendez-vous « journée entière » sont des dates flottantes (minuit UTC, fin exclusive). Les comptes ayant la permission `calendars.manage_all` (administrateur, responsable) voient tous les calendriers.

Dans la phase 5, la barre supérieure du calendrier (Aujourd'hui, précédent/suivant, période, choix de vue) et le mini-calendrier changent réellement la période et la vue, mais **la grille elle-même n'existe pas encore** (phase 6). Les modules non livrés (rendez-vous, contacts, catégories, notifications, historique, rapports, utilisateurs) affichent une page « non disponible, prévu en phase N » au lieu de fausses données. La recherche de la barre supérieure mène à la page « Tous les rendez-vous » sans résultats (phase 7). « Nouveau rendez-vous » mène à une page « non disponible » (phase 7). L'indicateur « En ligne/Hors ligne » reflète le navigateur, pas une synchronisation serveur (phase 11).

Choix et écarts : les pages sont servies par le contrôleur frontal `public/index.php` (pas de `login.php`/`calendar.php`/`dashboard.php` séparés) ; le CSS est écrit à la main et statique, donc le CDN Tailwind n'est pas utilisé ; les icônes sont un sprite SVG interne.

Limites de la phase 4 : les prérequis « cron », « Web Push » et « SMTP » ne sont que **signalés** (aucun envoi ni exécution de worker n'est testé) ; la page de connexion `/login` n'existe pas encore (phase 5), la redirection finale y mène donc vers un message provisoire ; la création automatique de la base n'est pas proposée (elle doit exister) ; le mode TLS « verify » n'a pas été testé contre un serveur TLS réel.

Non fait dans la phase 3 : réinitialisation de mot de passe par e-mail (dépend du SMTP, phases 13/16), gestion des comptes (`/users`, phase 16), interface de connexion (phase 5). Le login lui-même n'est pas protégé par CSRF (aucune session n'existe encore).

Il n'y a pas encore d'interface web de connexion ni de calendrier. L'interface a aussi été parcourue dans Chromium (Playwright) contre le serveur intégré de PHP : connexion refusée puis réussie, redirection `next`, navigation de période et mini-calendrier, thème sombre, menu mobile (390 px), compte en lecture seule, sans erreur CSP. Pour le calendrier, un test navigateur reproductible existe (`tests/e2e/seed.php` + `tests/e2e/calendar.e2e.js` 18 vérifications et `tests/e2e/appointments.e2e.js` 30 vérifications et `tests/e2e/dragdrop.e2e.js` 21 vérifications passées, dont création par clic/glissement, conflit, conflit de version, report, annulation, corbeille/restauration, listes, touche `c`, journée entière ; calendrier : colonnes, chevauchements, détails et fuseau, vues mois/planning, masquage de calendrier, réglages, isolation d'un autre compte, mobile, absence d'erreur console/CSP) ; il se lance à la main, hors de `php tests/run.php`, et exige Playwright. L'assistant d'installation a été essayé via le serveur intégré de PHP (redirection `/` → `/install`, page de jeton, en-têtes CSP et cookie) mais le parcours complet n'a été exécuté que par les tests automatisés.

### Vérifié

- 72 tests (`php tests/run.php`) passent : unitaires (Env, routeur, découpage SQL, journalisation, mots de passe), migrations (tables, idempotence, seed, InnoDB/utf8mb4, contraintes, rejet d'une migration modifiée) assistant d'installation (jeton, CSRF, prérequis, test et privilèges SQL, secrets non renvoyés, base étrangère intacte, flux complet puis verrouillage, reprise avec données conservées, échappement XSS), pages web (redirection, CSP et absence de code en ligne, échappement XSS, navigation par permissions, chaque lien a une page, compteurs du tableau de bord par fuseau et par calendrier autorisé, badge de notifications), API calendrier (authentification, chevauchement de plage aux bornes, rendez-vous supprimés exclus, isolation entre utilisateurs, filtre `calendars` sans contournement, validation), gestion des rendez-vous (création, fuseaux, journée entière, 14 cas de validation, CSRF, permissions par calendrier et rôle, fusion à la mise à jour, verrou optimiste, conflits, suppression logique/restauration/corbeille, dupliquer/annuler/terminer/reporter, changement de calendrier, filtres/tri/pagination/jokers LIKE échappés, injection SQL, catalogues), et sécurité de l'authentification (cookies, jeton non stocké en clair, réponse identique pour compte inconnu, force brute, CSRF/Origin, expiration, désactivation, révocation après changement de mot de passe, audit, permissions de rôle et de calendrier).
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
