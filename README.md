# RendezVous Manager 4.0 Web

Plateforme web professionnelle de gestion de rendez-vous, centrée sur un calendrier interactif en grille (jour, semaine, mois, planning) inspiré du fonctionnement de Google Agenda, sans en reprendre l'identité graphique.

> **État du projet : conception / démarrage.**
> Le dépôt ne contient pour l'instant que ce README. Les fonctionnalités ci-dessous sont les **objectifs** du projet, pas des fonctions déjà livrées. Ce document sera mis à jour à chaque phase terminée ; rien n'est annoncé comme testé tant qu'il ne l'a pas été.

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

Avancement : **aucune phase terminée** pour l'instant.

## Installation

La procédure détaillée (préparation Linux, Apache/Nginx, PHP, MySQL/MariaDB, phpMyAdmin, HTTPS, pare-feu, Composer, assistant d'installation, cron, Web Push, SMTP) sera fournie dans `docs/installation.md` une fois l'application réalisée. Aucune installation n'a été effectuée ni testée à ce stade.

## Licence

À définir.
