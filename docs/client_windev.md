# Client Windows (WinDev)

> **Le client Windows livré est décrit dans `docs/client_windows.md` (programme .exe en C#, sans WinDev).** Ce document-ci ne concerne que la variante WinDev.
>
> **État : guide non testé.** Ce dépôt ne contient pas de projet WinDev (`.WDP`) : WinDev est un outil
> propriétaire qui ne peut ni être installé ni exécuté dans l'environnement de développement du serveur.
> Ce document décrit la marche à suivre et le code WLanguage à saisir ; **la syntaxe exacte doit être
> vérifiée dans votre version de WinDev** (noms de propriétés selon la version).

## Principe

Le client de bureau est une **fenêtre native qui affiche l'application web** (`https://agenda.nicolasphilmon.fr`).
Toute la logique reste sur le serveur : permissions, synchronisation, notifications, sauvegardes.
Aucune clé, aucun mot de passe SQL et aucune donnée n'est stocké dans le client.
Avantage : le client n'a jamais de retard fonctionnel sur le site, et il n'y a rien à dupliquer.

## 1. Créer le projet

1. WinDev → **Nouveau projet** → application Windows, nom `RendezVousManager`.
2. Créez une fenêtre `WIN_Principale` (plein écran, redimensionnable).
3. Ajoutez un **champ Navigateur** (`NAV_Agenda`), ancré aux 4 bords de la fenêtre.
4. Choisissez le moteur **Chromium / Edge WebView2** si votre version le propose (nécessaire pour les
   `<dialog>`, `fetch` et les en-têtes modernes). Le moteur Internet Explorer n'est **pas** supporté.

## 2. Code WLanguage

Constante du projet : `URL_AGENDA = "https://agenda.nicolasphilmon.fr/"`.

Initialisation de `WIN_Principale` :

```
// Ne charger que le domaine de l'agenda ; HTTPS obligatoire.
NAV_Agenda..URL = URL_AGENDA
Titre fenêtre : "RendezVous Manager"
```

Blocage de la navigation hors de l'application (événement « Avant navigation » du champ) :

```
// Autoriser uniquement le domaine de l'agenda ; ouvrir le reste dans le navigateur par défaut.
SI PAS (MesSousChaînes ... commence par URL_AGENDA) ALORS
    LanceAppliAssociée(UrlAOuvrir)
    // annuler la navigation
FIN
```

Règles à respecter :

- **Ne jamais désactiver la vérification TLS** (pas de `httpParamètre`/option « ignorer le certificat »).
- Ne pas écrire de mot de passe ni de jeton dans le code ou dans un fichier `.ini`.
- Laisser le navigateur gérer le cookie `rvm_session` : ne pas le lire depuis WLanguage.

## 3. Fonctions recommandées

| Fonction | Réalisation |
|---|---|
| Icône dans la zone de notification | `SystrayCrée`, menu « Ouvrir / Quitter » |
| Démarrage avec Windows | raccourci dans `shell:startup` créé par l'installeur |
| Une seule instance | `ApplicationEstOuverte` puis ramener la fenêtre au premier plan |
| Notifications | **Déjà assurées par le Web Push du site** si le moteur WebView2 les autorise ; sinon, utiliser `ToastAffiche` à partir d'un appel `GET /api/v1/reminders` (voir ci-dessous). À tester. |
| Hors connexion | afficher une page locale « Serveur injoignable — Réessayer » sur échec de chargement |

## 4. Variante : appeler directement l'API REST

À n'utiliser que si vous voulez des écrans WinDev natifs. Contraintes imposées par le serveur
(voir `docs/api.md`) :

1. `POST /api/v1/auth/login` avec `{"identifier":"…","password":"…"}` (JSON) ; conserver le **cookie**
   `rvm_session` (`HTTPCréeRequête` + conteneur de cookies activé) et lire `csrf_token` dans la réponse.
2. Pour tout `POST`/`PUT`/`DELETE` : envoyer l'en-tête `X-CSRF-Token: <csrf_token>`.
3. Ne pas envoyer d'en-tête `Origin` d'un autre hôte (sinon 403).
4. Gérer `401` (reconnexion), `409` (conflit de version : recharger), `429` (attendre `Retry-After`).
5. Synchronisation : `GET /api/v1/sync/changes?cursor=…`, toutes les 30 à 60 secondes au plus.
6. Dates en UTC ISO 8601 ; une journée entière est une date `AAAA-MM-JJ` à fin exclusive.

Le mot de passe saisi n'est jamais enregistré ; ne conserver aucun secret sur le poste.

## 5. Livraison

1. Générer l'exécutable (assistant « Générer l'installation » de WinDev) ; signature du code recommandée.
2. Installer sur un poste propre et dérouler la liste ci-dessous.

## 6. Recette du client (à cocher après essai réel)

- [ ] Connexion, déconnexion, session conservée après fermeture.
- [ ] Création, déplacement et redimensionnement d'un rendez-vous.
- [ ] Les liens externes s'ouvrent dans le navigateur par défaut.
- [ ] Notification de rappel reçue fenêtre réduite.
- [ ] Message clair si le serveur est injoignable.
- [ ] Aucun avertissement de certificat.
