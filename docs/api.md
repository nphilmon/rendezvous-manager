# API REST v1

Toutes les routes sont sous `/api/v1`, renvoient du JSON UTF-8 et ne sont jamais mises en cache (`Cache-Control: no-store`). L'interface web utilise exactement cette API ; il n'existe pas d'autre chemin d'accès aux données.

## Conventions

| Sujet | Règle |
|---|---|
| **Authentification** | `POST /api/v1/auth/login` crée une session : cookie `rvm_session` (`HttpOnly`, `SameSite=Lax`, `Secure` en production). Le jeton n'est jamais stocké en clair côté serveur. |
| **CSRF** | Toute requête `POST`, `PUT`, `PATCH`, `DELETE` doit envoyer l'en-tête `X-CSRF-Token` (valeur renvoyée par `login` et `GET /auth/me`) ; un en-tête `Origin` d'un autre hôte est refusé (403). |
| **Format** | corps JSON (`Content-Type: application/json`) ; succès : `{"data": …, "meta": …}` ; les listes ont `meta.total`, `page`, `per_page`, `pages`. |
| **Erreurs** | `{"error": {"status": 422, "message": "…", "details": {"champ": ["message"]}}}`. Codes : 401 non authentifié, 403 droit manquant / CSRF, 404 introuvable **ou non autorisé** (on ne révèle pas l'existence), 405, 409 conflit, 413 trop gros, 422 validation, 429 trop de tentatives (`Retry-After`). Aucune trace, chemin ni message SQL n'est renvoyé. |
| **Dates** | instants en ISO 8601 UTC (`2026-11-18T09:00:00Z`) ; une **journée entière** est une date `AAAA-MM-JJ` avec fin **exclusive** ; chaque rendez-vous porte son fuseau d'origine (`timezone`). |
| **Verrou optimiste** | `PUT`/`DELETE` d'un rendez-vous envoient `version` ; version périmée → 409 `details.code = "version_conflict"`. Un chevauchement horaire → 409 `time_conflict` (renvoyer avec `ignore_conflicts: true` pour passer outre). |
| **Mot de passe à changer** | tant que le compte a `credentials_expired: true`, toutes les routes répondent 403 `password_change_required` sauf `auth/password`, `auth/logout`, `auth/me`. |
| **Pagination** | `page` ≥ 1, `per_page` 1 à 100 (25 par défaut) ; tris et filtres inconnus → 422. |

Exemple :

```bash
# connexion : le cookie est conservé dans cookies.txt, le jeton CSRF est dans la réponse
curl -s -c cookies.txt -H 'Content-Type: application/json' \
  -d '{"identifier":"admin","password":"…"}' https://votre-domaine/api/v1/auth/login

# création d'un rendez-vous
curl -s -b cookies.txt -H 'Content-Type: application/json' -H 'X-CSRF-Token: <csrf_token>' \
  -d '{"calendar_id":1,"title":"Réunion","timezone":"Europe/Paris","start":"2026-11-18T09:00:00Z","end":"2026-11-18T10:00:00Z"}' \
  https://votre-domaine/api/v1/appointments
```

## Droits

Rôles : **admin**, **manager** (Responsable), **user** (Utilisateur), **readonly** (Lecture seule). Les droits (`appointments.read`, `appointments.write`, `appointments.delete`, `calendars.create`, `calendars.manage_all`, `contacts.read`, `contacts.write`, `categories.manage`, `reports.view`, `data.export`, `data.import`, `users.manage`, `settings.admin`, `audit.view`, `backups.manage`) sont portés par le rôle ; en plus, l'accès à un **calendrier** dépend de sa propriété, de sa visibilité et des partages (lire, créer, modifier, supprimer, partager). Un rôle ne peut jamais être dépassé par un partage (la lecture seule ne peut pas écrire).

« Session » = tout compte connecté.

## Authentification

| Route | Droit | Rôle de la route |
|---|---|---|
| `POST /api/v1/auth/login` | publique | `{identifier, password}` → `{user, csrf_token}` + cookie. Limitation : 5 échecs par identifiant et 20 par adresse IP sur 15 min (429). |
| `POST /api/v1/auth/logout` | session | ferme la session |
| `GET /api/v1/auth/me` | session | utilisateur courant, permissions, jeton CSRF |
| `POST /api/v1/auth/password` | session | `{current_password, new_password}` ; ferme les autres sessions |

## Rendez-vous

| Route | Droit | Rôle de la route |
|---|---|---|
| `GET /api/v1/appointments` | `appointments.read` | **mode grille** avec `start`+`end` (≤ 100 jours, séries dépliées en occurrences, `calendars=1,2` pour filtrer) ; **mode liste** : `q`, `status`, `priority`, `category_id`, `contact_id`, `created_by`, `from`, `to`, `deleted=1` (corbeille), `sort`, `dir`, `page`, `per_page` |
| `POST /api/v1/appointments` | `appointments.write` | création ; `recurrence` = `{freq, interval, byday, bymonthday, bymonth, end:{type: never\|count\|until}}`, `reminder_minutes` |
| `GET /api/v1/appointments/{id}` | `appointments.read` | détail ; `?occurrence=` pour une occurrence d'une série |
| `PUT /api/v1/appointments/{id}` | `appointments.write` | modification partielle ; `version` obligatoire ; `scope` = `single` \| `following` \| `all` et `occurrence` pour les séries |
| `DELETE /api/v1/appointments/{id}` | `appointments.delete` | suppression logique (corbeille) |
| `POST /api/v1/appointments/{id}/restore` | `appointments.delete` | restauration |
| `POST /api/v1/appointments/{id}/duplicate` | `appointments.write` | copie |
| `POST /api/v1/appointments/{id}/cancel` | `appointments.write` | statut « annulé » |
| `POST /api/v1/appointments/{id}/complete` | `appointments.write` | statut « terminé » |
| `POST /api/v1/appointments/{id}/reschedule` | `appointments.write` | nouvelle plage `start`/`end` |
| `GET /api/v1/appointments/{id}/history` | lecture du calendrier | journal des modifications (ancienne et nouvelle valeur), sans adresse IP |

Statuts : `scheduled`, `confirmed`, `tentative`, `completed`, `cancelled`. Priorités : `low`, `normal`, `high`, `urgent`.

## Calendriers, catégories, contacts

| Route | Droit | Rôle de la route |
|---|---|---|
| `GET /api/v1/calendars` | `appointments.read` | calendriers visibles, avec `can` (create, update, delete, share, manage) |
| `POST /api/v1/calendars` | `calendars.create` | création (`name`, `kind`, `visibility`, `color`, `timezone`) |
| `PUT /api/v1/calendars/{id}` | propriétaire ou `calendars.manage_all` | modification (`version` facultative : verrou optimiste si fournie) |
| `DELETE /api/v1/calendars/{id}` | propriétaire ou `calendars.manage_all` | suppression logique |
| `GET /api/v1/calendars/{id}/permissions` | `appointments.read` + droit de partage | partages |
| `PUT /api/v1/calendars/{id}/permissions` | droit de partage | remplace les partages |
| `GET /api/v1/users/lookup` | `calendars.create` | recherche minimale d'utilisateurs (`q` ≥ 2 caractères) pour le partage : ni e-mail ni comptes désactivés |
| `GET /api/v1/categories` | `appointments.read` | catégories avec décompte |
| `POST /api/v1/categories` | `categories.manage` | création (nom unique, insensible à la casse) |
| `PUT /api/v1/categories/{id}` | `categories.manage` | modification |
| `DELETE /api/v1/categories/{id}` | `categories.manage` | suppression ; les rendez-vous sont détachés |
| `GET /api/v1/contacts` | `contacts.read` | liste (`q`, `sort`, `dir`, `page`, `per_page`) ; ses propres contacts, ou tous avec `calendars.manage_all` |
| `POST /api/v1/contacts` | `contacts.write` | création |
| `GET /api/v1/contacts/{id}` | `contacts.read` | détail |
| `PUT /api/v1/contacts/{id}` | `contacts.write` | modification |
| `DELETE /api/v1/contacts/{id}` | `contacts.write` | suppression logique |
| `GET /api/v1/contacts/{id}/appointments` | `contacts.read` | rendez-vous liés (calendriers lisibles seulement) |

## Tableau de bord, synchronisation, notifications

| Route | Droit | Rôle de la route |
|---|---|---|
| `GET /api/v1/dashboard` | `appointments.read` | compteurs, prochain rendez-vous, répartitions ; `period` = `week` \| `month` \| `30d` \| `year` |
| `GET /api/v1/sync/changes` | `appointments.read` | `?cursor=N` : nature des changements visibles (type, identifiant, action) — jamais de contenu ; `reset: true` si le curseur est inconnu |
| `GET /api/v1/notifications` | session | centre de notifications (filtres, pagination) |
| `GET /api/v1/notifications/count` | session | nombre de non lues |
| `GET /api/v1/notifications/upcoming` | session | rappels à venir (calculés, non stockés) |
| `POST /api/v1/notifications/read-all` | session | tout marquer lu |
| `POST /api/v1/notifications/{id}/read` | session | marquer lue (ses propres notifications) |
| `POST /api/v1/notifications/{id}/unread` | session | marquer non lue |
| `GET /api/v1/settings` | session | réglages de l'utilisateur : notifications, affichage (valeurs effectives : défauts < organisation < utilisateur), profil |
| `PUT /api/v1/settings` | session | modification : `{notifications?, ui?, profile?}` (au moins un groupe, validés ensemble, rien n'est écrit si l'un est invalide). `ui` = préférences d'affichage (`theme`, `view`, `week_start`, `start_hour`, `end_hour`, `step`, `weekend`, `hour12`, `default_duration`, `default_calendar`) ; `profile` = `first_name`, `last_name`, `timezone` (jamais l'identifiant, l'e-mail ni le rôle) |
| `GET /api/v1/settings/global` | `settings.admin` | réglages de l'organisation : `global` (`org_name`, `default_timezone`) et `ui` (valeurs par défaut d'affichage) |
| `PUT /api/v1/settings/global` | `settings.admin` | modification : `{global?, ui?}` ; journalisée |
| `GET /api/v1/push/config` | session | clé publique VAPID et état du Web Push |
| `POST /api/v1/push/subscribe` | session | enregistre l'abonnement du navigateur (URL contrôlée contre le SSRF) |
| `POST /api/v1/push/unsubscribe` | session | supprime un abonnement |
| `GET /api/v1/push/subscriptions` | session | ses abonnements |
| `POST /api/v1/push/test` | session | notification de test |
| `GET /api/v1/push/deliveries` | session | historique de ses envois push |
| `POST /api/v1/email/test` | session | e-mail de test vers sa propre adresse |

## Import / export

| Route | Droit | Rôle de la route |
|---|---|---|
| `GET /api/v1/export/appointments` | `data.export` | `format` = `csv` \| `xlsx` \| `pdf` \| `ics` ; filtres `from`, `to`, `calendars`, `status` ; 5 000 lignes maximum (422 au-delà) ; téléchargement `attachment` |
| `GET /api/v1/export/contacts` | `data.export` + `contacts.read` | `format` = `csv` \| `xlsx` |
| `POST /api/v1/import/appointments` | `data.import` + droit de création | `{format: csv\|ics, content, calendar_id?, dry_run?}` ; 2 Mo, 1 000 rendez-vous ; rapport `{total, created, would_create, duplicates, ignored, error_count, errors[], warnings[]}` |
| `POST /api/v1/import/contacts` | `data.import` + `contacts.write` | `{content, dry_run?}` (CSV) ; 2 000 contacts |

Les cellules CSV/XLSX/PDF qui commencent par `=`, `+`, `-`, `@`, une tabulation ou un retour chariot reçoivent une apostrophe de tête (protection contre l'injection de formules) ; elle est retirée à l'import.

## Administration

| Route | Droit | Rôle de la route |
|---|---|---|
| `GET /api/v1/users` | `users.manage` | liste (`q`, `role`, `active`, `sort`, `dir`, `page`, `per_page`) |
| `POST /api/v1/users` | `users.manage` | création ; renvoie **une seule fois** `temporary_password` |
| `GET /api/v1/users/{id}` | `users.manage` | détail |
| `PUT /api/v1/users/{id}` | `users.manage` | modification (e-mail, nom, fuseau, rôle) ; changer le rôle ferme les sessions |
| `POST /api/v1/users/{id}/activate` | `users.manage` | réactive |
| `POST /api/v1/users/{id}/deactivate` | `users.manage` | désactive et ferme les sessions (jamais soi-même, jamais le dernier administrateur) |
| `POST /api/v1/users/{id}/reset-password` | `users.manage` | mot de passe provisoire, sessions fermées, compte débloqué |
| `POST /api/v1/users/{id}/unlock` | `users.manage` | efface les échecs de connexion |
| `POST /api/v1/users/{id}/revoke-sessions` | `users.manage` | déconnexion forcée |
| `GET /api/v1/roles` | `users.manage` | rôles, permissions, effectifs |
| `GET /api/v1/audit` | `audit.view` | journal : `action` (ou préfixe `auth.`), `user_id`, `entity_type`, `entity_id`, `from`, `to`, `q`, `page`, `per_page` |
| `GET /api/v1/backups` | `backups.manage` | sauvegardes existantes |
| `POST /api/v1/backups` | `backups.manage` | crée, vérifie et fait la rotation |
| `GET /api/v1/backups/{name}/download` | `backups.manage` | télécharge le `.sql.gz` |
| `POST /api/v1/backups/{name}/verify` | `backups.manage` | contrôle d'intégrité |
| `DELETE /api/v1/backups/{name}` | `backups.manage` | supprime |

## Supervision (publiques)

| Route | Rôle de la route |
|---|---|
| `GET /api/v1/health` | `{status, database}` (200 ou 503) ; aucun détail interne |
| `GET /api/v1/version` | `{name, version}` |
