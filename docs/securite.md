# Sécurité

Ce document décrit ce qui est **réellement** en place, comment c'est vérifié, ce qui reste à la charge de l'hébergement, et ce qui n'est **pas** fait. Aucun audit de sécurité externe ni test d'intrusion n'a été réalisé : les vérifications ci-dessous sont des tests automatisés écrits avec le code.

## Modèle de menace

Utilisateurs authentifiés avec des droits différents (administrateur, responsable, utilisateur, lecture seule) ; Internet public pour la page de connexion ; un hébergement partagé possible. Les données sensibles : rendez-vous, contacts (données personnelles), condensats de mots de passe, clés (VAPID, SMTP).

## Authentification et sessions

| Mesure | Détail |
|---|---|
| Mots de passe | Argon2id ; 12 caractères minimum avec majuscule, minuscule et chiffre ; ne contient pas l'identifiant ; condensat recalculé si les paramètres évoluent |
| Connexion | message identique pour compte inconnu et mot de passe faux ; temps de calcul identique (condensat factice) ; 5 échecs/15 min par identifiant et 20 par adresse IP (429 + `Retry-After`) |
| Session | jeton aléatoire de 256 bits ; **seul son SHA-256 est stocké** ; cookie `HttpOnly`, `SameSite=Lax`, `Secure` en production ; durée absolue 12 h, inactivité 2 h ; nouvelle session à chaque connexion |
| Révocation | changement de mot de passe (autres sessions), réinitialisation, désactivation, changement de rôle et « déconnexion forcée » ferment les sessions |
| Mot de passe provisoire | généré aléatoirement, affiché une fois, jamais journalisé, **changement imposé** avant tout autre accès |
| Comptes | pas de suppression (désactivation) ; le dernier administrateur actif est protégé ; on ne peut ni se désactiver ni changer son propre rôle |

## Autorisation

- **Tout est vérifié côté serveur**, à chaque requête : droit du rôle, puis droit sur le calendrier (propriétaire, visibilité, partage). Le navigateur n'est jamais cru.
- Les ressources non autorisées répondent **404** (l'existence n'est pas révélée).
- La lecture seule ne peut pas écrire, même avec un partage en écriture.
- Le test `AccessMatrixTest` énumère **automatiquement toutes les routes** : aucune ne peut être ajoutée sans session ni contrôle CSRF sans faire échouer la suite ; une matrice figée vérifie les quatre rôles.

## Requêtes et entrées

| Menace | Mesure |
|---|---|
| Injection SQL | requêtes préparées PDO partout ; colonnes de tri et filtres en liste blanche ; jokers `LIKE` échappés |
| XSS | aucune insertion de HTML dans le JavaScript (`textContent` uniquement) ; sorties des gabarits échappées (`$e()`) ; **CSP stricte** : `script-src 'self'`, `style-src 'self'`, aucun script, style ni gestionnaire en ligne ; `X-Frame-Options: DENY` ; `Referrer-Policy: same-origin` ; `X-Content-Type-Options: nosniff` |
| CSRF | jeton par session (`X-CSRF-Token`) sur toute écriture + contrôle de l'en-tête `Origin` ; cookie `SameSite=Lax` |
| Redirection ouverte | le paramètre `next` n'accepte que des chemins locaux |
| Injection de formules | cellules CSV/XLSX/PDF commençant par `= + - @`, tabulation ou retour chariot préfixées d'une apostrophe |
| Injection de propriétés ICS | texte échappé, lignes repliées |
| Validation | longueurs, formats, fuseaux, énumérations contrôlés ; caractères de contrôle refusés ; limites d'import (2 Mo, 1 000 lignes) et d'export (5 000) |
| Traversée de dossier | noms de sauvegarde validés par expression stricte, jamais de chemin fourni par l'utilisateur |

Ces points sont couverts par `CodeHygieneTest` (aucun `innerHTML`, `eval`, script ou style en ligne, commande système, `unserialize`, secret en dur, vérification TLS désactivée…) et par les tests d'intégration.

## Réseau sortant

- **Web Push** : l'adresse de l'abonnement est contrôlée (HTTPS, services connus FCM/Mozilla/Apple par défaut, adresses privées refusées) et la connexion est épinglée sur l'IP vérifiée (anti-SSRF) ; messages chiffrés (RFC 8291), jetons VAPID signés (ES256).
- **SMTP** : TLS vérifié (STARTTLS ou implicite), **jamais de repli en clair** ; injections d'en-têtes refusées.
- **Base de données** : `DB_SSL_MODE=verify` vérifie le certificat et l'identité ; la vérification TLS n'est jamais désactivée dans le code.

## Secrets et données

- `.env` (droits 0600, hors zone publique, ignoré par git) contient les accès base, SMTP et la clé VAPID privée, **en clair**. Les journaux masquent les champs sensibles ; le journal d'audit ne contient jamais de mot de passe ni de jeton.
- Les sauvegardes contiennent **toutes les données et les condensats de mots de passe**. Elles sont **chiffrées** (AES-256-GCM) quand `BACKUP_KEY` est renseignée (`php scripts/generate_backup_key.php`) : **la clé doit être conservée hors du serveur**, sans elle les sauvegardes sont illisibles ; sans `BACKUP_KEY` elles restent en clair (`deploy_check` le signale). Le dossier `storage/` est interdit au web (`.htaccess`) et les fichiers sont en 0600. La clé, comme les autres secrets de `.env`, est stockée en clair sur le serveur : un attaquant qui y accède lit aussi `.env`, le chiffrement protège surtout les copies exportées.
- L'assistant d'installation est protégé par un jeton à usage unique (2 h) et **verrouillé** une fois l'installation terminée.
- Seul `public/index.php` est exécutable depuis le web (vérifié par un test).

## Journal et traçabilité

Authentification, rendez-vous (avec ancienne/nouvelle valeur des champs), comptes, imports/exports, sauvegardes : consultables (page *Historique*, droit `audit.view`). Le journal **n'est pas signé** : un accès direct à la base permet de le modifier.

## À la charge de l'hébergement

- **HTTPS** valide partout ; redirection HTTP → HTTPS ; **HSTS** (`Strict-Transport-Security`), non envoyé par l'application.
- Racine web = `public/` ; `.env`, `storage/`, `app/`… inaccessibles (voir `docs/installation.md`).
- Mises à jour de PHP et du serveur de base ; sauvegardes copiées **hors du serveur**.
- Limitation de débit globale (anti-déni de service) : seule la connexion est limitée par l'application.

## Ce qui n'est pas fait

- Pas de double authentification, pas de réinitialisation du mot de passe par e-mail.
- Pas de limitation de débit générale de l'API, pas de CAPTCHA.
- Pas de chiffrement des secrets de `.env` au repos ; les sauvegardes ne sont chiffrées que si `BACKUP_KEY` est renseignée.
- Pas de signature du journal d'audit ; l'audit couvre aussi calendriers, contacts, catégories, changements de droits et réglages d'organisation.
- Pas d'audit externe ; pas de test contre un vrai serveur MySQL distant en TLS `verify` ni contre de vrais services Web Push / SMTP.
- L'assistant ne vérifie pas que la racine web est `public/` ni que HTTPS est actif.

## Liste de contrôle pour l'exploitant

- [ ] HTTPS actif, HSTS configuré ; `APP_ENV=production`, `APP_DEBUG=false`, `COOKIE_SECURE=true`.
- [ ] `/.env`, `/app/`, `/storage/`, `/install` inaccessibles depuis Internet.
- [ ] Mot de passe administrateur long et unique ; comptes inutiles désactivés.
- [ ] Utilisateur de base dédié, privilèges limités à la base de l'application.
- [ ] Cron de sauvegarde actif et une copie des sauvegardes conservée hors du serveur.
- [ ] `php scripts/healthcheck.php` sans erreur ; journaux `storage/logs` relus de temps en temps.
- [ ] PHP et serveur de base à jour.

## Signaler une vulnérabilité

Ne la publiez pas : prévenez l'administrateur de votre installation, avec les étapes pour la reproduire (sans y joindre de données personnelles réelles).
