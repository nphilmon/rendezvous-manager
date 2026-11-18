# RendezVous Manager 4.0 Web

Plateforme web professionnelle de gestion de rendez-vous, centrée sur un calendrier interactif en grille (jour, semaine, mois, planning) inspiré du fonctionnement de Google Agenda, sans en reprendre l'identité graphique.

> **État du projet : développement en cours (phases 1 à 13 terminées).**
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
| 9. Récurrences | Terminée : moteur RRULE (sous-ensemble RFC 5545 : `FREQ` DAILY/WEEKLY/MONTHLY/YEARLY, `INTERVAL`, `BYDAY` avec rang, `BYMONTHDAY` y compris négatif, `BYMONTH`, `COUNT`, `UNTIL`, `WKST`), occurrences calculées à la demande dans le fuseau de la série (l'heure locale est conservée aux changements d'heure) et **jamais stockées** (une série = une ligne `appointments` + une ligne `appointment_recurrence`), exceptions `appointment_exceptions` (occurrence modifiée, déplacée ou annulée), modification d'une occurrence / de cette occurrence et des suivantes (la série est coupée en deux) / de toute la série, suppression selon les mêmes portées, restauration, copie d'une occurrence, ajout ou retrait d'une récurrence sur un rendez-vous existant, grille/mois/planning/listes qui déplient les séries, formulaire de répétition (préréglages, personnalisée, jours de semaine, fin jamais/date/nombre), choix de portée pour modifier, déplacer, reporter, annuler, terminer et supprimer |
| 10. Contacts, catégories (et calendriers) | Terminée : **contacts** (liste paginée avec recherche et tri, fiche, création/modification/suppression logique, rendez-vous liés, « Nouveau rendez-vous » depuis la fiche, chacun ne voit que les siens ; `calendars.manage_all` voit tout) ; **catégories** globales (gestion réservée à `categories.manage`, nom unique sans tenir compte de la casse, décompte des rendez-vous lisibles, suppression qui détache les rendez-vous) ; **calendriers** (création, modification avec verrou optionnel, suppression logique, type, visibilité, fuseau) et **partage** par personne avec droits lire/créer/modifier/supprimer/partager (lire est implicite, délégation du partage, plafond du rôle « Lecture seule »), recherche d'utilisateurs limitée au nom et à l'identifiant ; API `GET/POST/PUT/DELETE /api/v1/contacts[/{id}]`, `/contacts/{id}/appointments`, `/categories[/{id}]`, `POST/PUT/DELETE /calendars[/{id}]`, `GET/PUT /calendars/{id}/permissions`, `GET /users/lookup` ; pages `/contacts`, `/categories`, `/calendars` |
| 11. Synchronisation multi-utilisateurs | Terminée (interrogation périodique) : `GET /api/v1/sync/changes?cursor=N` renvoie, par curseur serveur, uniquement la **nature** des changements visibles (type, identifiant, création/modification/suppression/restauration, calendrier, `by_me`) — jamais de titre ni de contenu ; curseur initial et empreinte d'accès intégrés à la page ; pagination (500 par réponse, `has_more`), `reset` si le curseur est inconnu ou purgé ; l'empreinte `access` détecte un partage accordé ou retiré ; client `sync.js` : intervalle fixé par le serveur (30 s par défaut), aucune requête onglet masqué, reprise immédiate au retour, repli exponentiel (plafonné à 5 min) en cas d'erreur, rattrapage après coupure, actualisation manuelle et état affiché dans la barre supérieure (À jour / Synchronisation… / Hors ligne / Erreur) ; le calendrier, les listes, les contacts, catégories et calendriers se rechargent seuls (pas pendant un glisser), le tableau de bord affiche une bannière, le formulaire d'édition avertit si le rendez-vous est modifié ou supprimé ailleurs (en plus du verrou optimiste de la phase 7) ; `workers/cleanup_worker.php` purge `sync_changes` (30 jours), sessions expirées et tentatives de connexion anciennes |
| 12. Notifications autonomes côté serveur | Terminée pour le canal « dans l'application » : worker cron `workers/reminder_worker.php` (planification idempotente, réservation atomique par lots, reprise des réservations abandonnées, validation à l'envoi, nouvelles tentatives avec repli, heures silencieuses, rattrapage des rappels manqués, rappels des séries et des journées entières, confidentialité), centre de notifications `/notifications` (non lues, lues, à venir, historique, marquer lu/non lu, tout marquer comme lu, lien vers le rendez-vous), pastille de la cloche mise à jour en direct par la synchronisation, réglages de notification (page Paramètres et `GET/PUT /api/v1/settings`), rappel personnalisé et rappel par défaut dans le formulaire, documentation `docs/notifications.md` |
| 13. Web Push et e-mail | Terminée : **Web Push** natif (sans bibliothèque) — chiffrement RFC 8291 `aes128gcm` **reproduisant exactement le vecteur de test de la RFC (annexe A)**, jetons VAPID ES256 (RFC 8292), clés générées par `scripts/generate_vapid.php`, abonnements par appareil (API `/api/v1/push/*`, 10 maximum, réattribution d'un navigateur partagé), protection SSRF des points d'accès (https, liste de services reconnus, adresses publiques, IP épinglée), service worker `service-worker.js` (affichage, ouverture sûre de l'URL, renouvellement d'abonnement), interface dans Paramètres (permission, activation/désactivation, test, appareils, historique), nettoyage des abonnements expirés ; **e-mail** SMTP sans dépendance (TLS implicite ou STARTTLS avec vérification du certificat et du nom d'hôte, aucun repli en clair, injection d'en-têtes bloquée, `none` limité à la machine locale), `POST /api/v1/email/test` ; canaux proposés selon la configuration serveur, rappels synchronisés avec les canaux activés ; `workers/push_worker.php`, `scripts/healthcheck.php`, icônes PNG, manifeste |
| 14 à 20 | Non commencées |

Limites de la phase 13 : **aucun envoi réel vers FCM, Mozilla ou Apple n'a été fait** (pas d'accès Internet ici) : le chiffrement est validé par le vecteur de la RFC, et l'envoi par un faux service push local qui vérifie le jeton VAPID et déchiffre le message ; de même **aucun serveur SMTP réel** n'a été testé (faux serveur local avec TLS à certificat de test) ; l'abonnement réel d'un navigateur n'a pas pu être obtenu : dans le test navigateur il est simulé avec des clés connues, le service worker (réel) recevant ses messages par le protocole de débogage de Chromium ; le clic sur une notification système n'est pas testé ; la réception push n'est jamais garantie (navigateur, système, économie d'énergie) ; si plusieurs appareils sont abonnés et qu'au moins un accepte le message, la notification est considérée comme remise (pas de relance des autres) ; seul le texte (titre, corps) est envoyé, ≤ 3993 octets ; pas de Web Push sur iOS hors site installé sur l'écran d'accueil ; un seul expéditeur SMTP, sans file d'attente propre (la file est celle des notifications) ; les secrets (clé VAPID privée, mot de passe SMTP) vivent dans `.env` — leur chiffrement au repos relève de l'hébergement.

Limites de la phase 12 : **seul le canal « dans l'application » envoie réellement** ; push et e-mail (phase 13) apparaissent grisés dans les réglages, et leurs lignes de rappel restent en attente sans être perdues ; un seul rappel par rendez-vous et par personne dans le formulaire (l'API de base de données en autorise plusieurs) ; les heures silencieuses ne s'appliquent qu'aux canaux autres que « dans l'application » ; un rendez-vous déplacé après l'envoi de son rappel reçoit un nouveau rappel à sa nouvelle heure ; une occurrence de série déplacée est identifiée par son début d'origine (un second rappel n'est pas envoyé si elle est redéplacée) ; un rappel dont l'heure est passée de plus de 24 h n'est jamais rattrapé ; le worker n'a pas été exécuté sous cron réel dans cet environnement (lancé à la main et en 4 processus parallèles) ; les notifications ne sont pas encore purgées par `cleanup_worker.php`.

Écarts et limites de la phase 11 : **Server-Sent Events non implémentés** (le cahier des charges ne les demandait qu'« éventuellement » ; une connexion longue bloquerait un serveur PHP mono-processus comme `php -S` et mobiliserait un worker PHP-FPM par client) ; le délai de propagation est donc l'intervalle d'interrogation (30 s, ajustable par `SYNC_INTERVAL`, borné à 2–300 s ; 2 s n'est utilisé que pour les tests) ; les contacts d'un autre utilisateur ne notifient pas leur propriétaire (contacts privés) ; l'empreinte d'accès ne couvre que l'ensemble des calendriers lisibles (un changement de droits d'écriture sans changement de lecture n'est visible qu'au prochain chargement des calendriers) ; la purge doit être planifiée par cron (non vérifiée dans l'installateur) ; les clients très anciens (curseur purgé) rechargent tout ; aucune charge réelle n'a été simulée au-delà de quelques sessions.

Écart assumé : le plan ne prévoyait que contacts et catégories pour cette phase ; j'y ai ajouté la gestion des calendriers et leur partage (section 14 du cahier des charges), faute de quoi un calendrier ne pouvait être créé que directement en base. Règles et limites de la phase 10 : les contacts sont **privés à leur propriétaire** (pas de carnet partagé) ; supprimer un contact le retire des listes mais ses rendez-vous conservent son nom ; supprimer un calendrier masque ses rendez-vous (conservés en base, aucune restauration dans l'interface) ; seul le propriétaire (ou `calendars.manage_all`) modifie ou supprime un calendrier, pas un simple partage ; pas de transfert de propriété ; les contacts et catégories n'ont pas de verrou optimiste (dernier enregistrement gagnant) ; la page des utilisateurs reste à faire (phase 16), la recherche d'utilisateurs du partage n'expose pas les e-mails.

Règles de la phase 9 : pour « toute la série », seuls l'horaire (heure du jour et durée) et les champs communs changent ; la date de départ de la série ne bouge que si l'on modifie la **première** occurrence ; changer la règle, l'horaire, la durée ou le fuseau d'une série efface ses exceptions ; « cet événement et les suivants » supprime les exceptions postérieures à la coupure et reporte le reste du `COUNT` sur la nouvelle série ; pour une seule occurrence, calendrier, catégorie, contact et « journée entière » ne sont pas modifiables ; les séries **ne participent pas à la détection de conflits** (ni comme source ni comme cible) ; une heure inexistante (saut de printemps) est décalée vers l'avant, une heure répétée (retour d'automne) prend la première occurrence ; une règle qui ne produit jamais de date (ex. 30 février) s'arrête après DTSTART ; `COUNT` ≤ 5000 ; les listes filtrées par période (« Aujourd'hui », « À venir ») montrent la première occurrence de chaque série dans la période, sans période une ligne par série ; le tableau de bord compte toujours une ligne par série (phase 14) ; les rappels d'une série ne sont pas encore dépliés (phase 12).

Limites de la phase 8 (les séries sont désormais déplaçables avec choix de portée, voir phase 9) : les rendez-vous « journée entière » ne se redimensionnent pas (on les déplace) ; un rendez-vous ne peut pas être glissé entre la zone « journée entière » et la grille horaire ; le redimensionnement d'un rendez-vous sur plusieurs jours n'agit que sur le segment du jour concerné et reste dans la journée ; aucun effet d'annulation (« Ctrl+Z ») après un déplacement ; l'appui long au toucher est vérifié avec des événements tactiles réels de Chromium (CDP), pas sur un appareil physique. Un défaut a été corrigé en route : après un glissement dans la même colonne, le « click » du navigateur ouvrait le formulaire de création ; le correctif ne retient plus qu'un seul clic et ignore ceux qui visent une boîte de dialogue.

Règles de la phase 7 : un conflit = chevauchement strict avec un autre rendez-vous **du même calendrier**, ni annulé, ni supprimé ; les rendez-vous « journée entière » ne bloquent pas et ne sont pas bloqués ; la création d'une copie ne contrôle pas les conflits ; un rendez-vous illisible renvoie 404 (pas 403) ; modifier le calendrier d'un rendez-vous exige le droit de modification sur l'ancien et de création sur le nouveau. L'API des rendez-vous « journée entière » prend des dates (`AAAA-MM-JJ`) avec **fin exclusive**, le formulaire affiche une fin inclusive.

Limites de la phase 7 : le déplacement et le redimensionnement par glisser-déposer des blocs existent côté API (`reschedule`) mais pas encore dans l'interface (phase 8) ; les récurrences ne sont pas gérées (phase 9) ; catégories et contacts ne sont qu'**en lecture** (listes alimentant les menus) — leur création est prévue en phase 10, les menus sont donc vides tant qu'aucune donnée n'existe ; le rappel enregistre une ligne `reminders` mais aucune notification n'est envoyée (phase 12) ; rappel « personnalisé » possible par l'API (en minutes), pas dans le formulaire ; le glissement de sélection est réservé à la souris (au toucher : appui simple) ; l'édition simultanée n'est pas diffusée en temps réel (phase 11), elle est détectée à l'enregistrement.

Limites de la phase 6 : **lecture seule** — création par clic/sélection, déplacement, redimensionnement et modification arrivent aux phases 7 et 8 (le pas horaire règle déjà la graduation) ; les séries récurrentes ne sont pas dépliées (phase 9) ; les réglages d'affichage sont mémorisés dans le navigateur (enregistrement serveur : phase 21) ; l'affichage ne se rafraîchit pas tout seul (phase 11) ; aucune virtualisation : l'API plafonne à 2000 rendez-vous et 100 jours par requête ; les jours de changement d'heure ne sont pas ajustés visuellement (23/25 h) ; les rendez-vous « journée entière » sont des dates flottantes (minuit UTC, fin exclusive). Les comptes ayant la permission `calendars.manage_all` (administrateur, responsable) voient tous les calendriers.

Dans la phase 5, la barre supérieure du calendrier (Aujourd'hui, précédent/suivant, période, choix de vue) et le mini-calendrier changent réellement la période et la vue, mais **la grille elle-même n'existe pas encore** (phase 6). Les modules non livrés (rendez-vous, contacts, catégories, notifications, historique, rapports, utilisateurs) affichent une page « non disponible, prévu en phase N » au lieu de fausses données. La recherche de la barre supérieure mène à la page « Tous les rendez-vous » sans résultats (phase 7). « Nouveau rendez-vous » mène à une page « non disponible » (phase 7). L'indicateur « En ligne/Hors ligne » reflète le navigateur, pas une synchronisation serveur (phase 11).

Choix et écarts : les pages sont servies par le contrôleur frontal `public/index.php` (pas de `login.php`/`calendar.php`/`dashboard.php` séparés) ; le CSS est écrit à la main et statique, donc le CDN Tailwind n'est pas utilisé ; les icônes sont un sprite SVG interne.

Limites de la phase 4 : les prérequis « cron », « Web Push » et « SMTP » ne sont que **signalés** (aucun envoi ni exécution de worker n'est testé) ; la page de connexion `/login` n'existe pas encore (phase 5), la redirection finale y mène donc vers un message provisoire ; la création automatique de la base n'est pas proposée (elle doit exister) ; le mode TLS « verify » n'a pas été testé contre un serveur TLS réel.

Non fait dans la phase 3 : réinitialisation de mot de passe par e-mail (dépend du SMTP, phases 13/16), gestion des comptes (`/users`, phase 16), interface de connexion (phase 5). Le login lui-même n'est pas protégé par CSRF (aucune session n'existe encore).

Il n'y a pas encore d'interface web de connexion ni de calendrier. L'interface a aussi été parcourue dans Chromium (Playwright) contre le serveur intégré de PHP : connexion refusée puis réussie, redirection `next`, navigation de période et mini-calendrier, thème sombre, menu mobile (390 px), compte en lecture seule, sans erreur CSP. Pour le calendrier, un test navigateur reproductible existe (`tests/e2e/seed.php` + `tests/e2e/calendar.e2e.js` 18 vérifications et `tests/e2e/appointments.e2e.js` 30 vérifications et `tests/e2e/dragdrop.e2e.js` 21 vérifications et `tests/e2e/recurrence.e2e.js` 19 vérifications et `tests/e2e/manage.e2e.js` 25 vérifications et `tests/e2e/sync.e2e.js` 22 vérifications et `tests/e2e/notifications.e2e.js` 22 vérifications et `tests/e2e/push.e2e.js` 23 vérifications passées (Chromium « nouveau headless » pour la permission notifications ; faux service push et faux SMTP lancés par le test ; service worker réel) (worker lancé à la main, pastille en direct, centre de notifications, à venir, réglages, confidentialité, désactivation, rappel par défaut et personnalisé) (deux navigateurs en parallèle, serveur lancé avec `SYNC_INTERVAL=2` : création/modification/suppression propagées, aucune fuite d'un calendrier non autorisé, partage accordé puis retiré, onglet masqué, coupure réseau et rattrapage, avertissement d'édition concurrente, bannière), dont création par clic/glissement, conflit, conflit de version, report, annulation, corbeille/restauration, listes, touche `c`, journée entière ; calendrier : colonnes, chevauchements, détails et fuseau, vues mois/planning, masquage de calendrier, réglages, isolation d'un autre compte, mobile, absence d'erreur console/CSP) ; il se lance à la main, hors de `php tests/run.php`, et exige Playwright. L'assistant d'installation a été essayé via le serveur intégré de PHP (redirection `/` → `/install`, page de jeton, en-têtes CSP et cookie) mais le parcours complet n'a été exécuté que par les tests automatisés.

### Vérifié

- 178 tests (`php tests/run.php`) passent : unitaires (Env, routeur, découpage SQL, journalisation, mots de passe), migrations (tables, idempotence, seed, InnoDB/utf8mb4, contraintes, rejet d'une migration modifiée) assistant d'installation (jeton, CSRF, prérequis, test et privilèges SQL, secrets non renvoyés, base étrangère intacte, flux complet puis verrouillage, reprise avec données conservées, échappement XSS), pages web (redirection, CSP et absence de code en ligne, échappement XSS, navigation par permissions, chaque lien a une page, compteurs du tableau de bord par fuseau et par calendrier autorisé, badge de notifications), API calendrier (authentification, chevauchement de plage aux bornes, rendez-vous supprimés exclus, isolation entre utilisateurs, filtre `calendars` sans contournement, validation), gestion des rendez-vous (création, fuseaux, journée entière, 14 cas de validation, CSRF, permissions par calendrier et rôle, fusion à la mise à jour, verrou optimiste, conflits, suppression logique/restauration/corbeille, dupliquer/annuler/terminer/reporter, changement de calendrier, filtres/tri/pagination/jokers LIKE échappés, injection SQL, catalogues), moteur RRULE (14 tests : intervalles, jours de semaine, WKST, mois courts, rang de jour, années bissextiles, UNTIL/COUNT, règles invalides, aller-retour, changement d'heure, heure inexistante, saut vers la période, règle impossible, saisie structurée) et séries en base (19 tests : une seule ligne, expansion, série sans fin, DST, journée entière annuelle, validation et portée obligatoire, exception, occurrence déplacée, annulation/suppression, toute la série, coupure, règle modifiée, suppression suivantes/tous, restauration, copie, conversion, conflits, permissions, listes par période), contacts, catégories, calendriers et partage (11 tests : validation, propriété, rôles, CSRF, recherche/tri/pagination avec jokers échappés, rendez-vous liés limités aux calendriers lisibles, unicité et détachement des catégories, version, suppression logique, délégation du partage, plafond du rôle lecture seule, recherche d'utilisateurs sans e-mail), synchronisation (9 tests : authentification, curseur initial, incrémental, suppressions/restaurations, visibilité par calendrier sans fuite de contenu, empreinte d'accès au partage, catégories/contacts/calendriers, pagination, curseurs invalides ou inconnus, purge et plancher, curseur intégré à la page, maintenance), moteur de notifications (18 tests : planification unique et idempotente, conditions de suppression, partage retiré, rattrapage ou non, confidentialité, annulation à l'envoi, tentatives avec repli et échec définitif, exception d'un expéditeur, canal sans expéditeur, heures silencieuses, réservations abandonnées, réservations disjointes entre deux connexions, **4 processus worker en parallèle sur 300 rappels envoyés exactement une fois**, séries et exceptions, journée entière, aperçu) et API du centre de notifications et des réglages (5 tests), Web Push et e-mail (30 tests : vecteur RFC 8291, aller-retour et altération, conversions de clés et signatures, jetons VAPID, SSRF, correspondance des réponses des services push, expiration des abonnements, intégration au moteur avec nouvelles tentatives, **échange HTTP réel avec un faux service push qui vérifie VAPID et déchiffre**, API d'abonnement, limites, test, historique, synchronisation des canaux ; SMTP : authentification, TLS vérifié STARTTLS et implicite, certificat non approuvé, absence de repli en clair, injection, codes 4xx/5xx ; moteur → e-mail, e-mail de test), et sécurité de l'authentification (cookies, jeton non stocké en clair, réponse identique pour compte inconnu, force brute, CSRF/Origin, expiration, désactivation, révocation après changement de mot de passe, audit, permissions de rôle et de calendrier).
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
