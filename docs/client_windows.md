# Client Windows (programme .exe)

> **État :** compilé avec succès par GitHub Actions (exécution du 21/11/2026, .NET 8) et essayé par le propriétaire du projet sur son poste Windows : la fenêtre s'ouvre, l'application fonctionne et les autres points de la recette en fin de document ont été confirmés fonctionnels (déclaration de l'utilisateur, sans détail par point). Essayé sur un seul poste ; le code n'a jamais été exécuté par son auteur (pas de Windows ni de .NET dans son environnement).

> **Installeur :** compilé par GitHub Actions (exécution du 21/11/2026) et installé avec succès par le propriétaire du projet sur son poste Windows (déclaration de l'utilisateur, sans détail par option ; un seul poste, non testé sur « installation pour tous les utilisateurs » ni sur la désinstallation).

## Ce que c'est

Un vrai programme Windows (`RendezVousManager.exe`, .NET 8 + Microsoft Edge WebView2) dont la fenêtre affiche
l'application web. Aucune logique métier n'est dupliquée : droits, synchronisation, notifications et données
restent sur le serveur. Le programme ne stocke **aucun** mot de passe, jeton ou clé.

Fonctions : fenêtre native, icône dans la zone de notification (la croix réduit, « Quitter » ferme),
une seule instance, liens externes ouverts dans le navigateur par défaut (seul le site configuré s'affiche),
page « Serveur injoignable » avec bouton Réessayer, HTTPS obligatoire (http n'est accepté que pour localhost),
vérification TLS jamais désactivée, outils de développement désactivés.

Sources : dossier `client-windows/` (`MainForm.cs`, `Program.cs`, `AppSettings.cs`, `RendezVousManager.csproj`).

## Obtenir le .exe et l'installeur

1. Sur GitHub, ouvrez le dépôt → onglet **Actions** → **Client Windows** → **Run workflow**.
2. Attendez la fin (quelques minutes), ouvrez l'exécution : deux artefacts sont proposés.
   - **`RendezVousManager-Setup`** : l'**installeur** `RendezVousManager-Setup-4.0.0.exe` (recommandé).
   - **`RendezVousManager-win-x64`** : le programme seul en version portable (zip).
3. Décompressez l'artefact téléchargé (GitHub fournit un zip) puis lancez l'installeur.

### L'installeur

Assistant en français (Inno Setup) : installation **pour l'utilisateur courant par défaut, sans droits administrateur** (choix « pour tous les utilisateurs » possible), raccourci dans le menu Démarrer, icône de Bureau et lancement à l'ouverture de session en option, désinstallation par « Applications installées » de Windows. Une réinstallation par-dessus met à jour le programme ; les réglages (`settings.json`) et les données WebView2 sont conservés à la désinstallation.

Prérequis : Windows 10/11 64 bits ; le composant **WebView2 Runtime** est déjà présent sur les Windows à jour (sinon le programme l'indique et donne le lien).

Windows SmartScreen peut afficher « Windows a protégé votre PC » : le programme n'est pas signé numériquement
(une signature de code est payante). Cliquez sur **Informations complémentaires → Exécuter quand même**
uniquement si vous avez téléchargé le fichier vous-même depuis votre dépôt.

## Changer d'adresse de serveur

Par défaut : `https://agenda.nicolasphilmon.fr/`. Pour une autre adresse, créez le fichier
`%APPDATA%\RendezVousManager\settings.json` :

```json
{ "url": "https://agenda.exemple.fr/" }
```

## Limites connues

- **Web Push** : le moteur WebView2 ne reçoit pas les notifications Web Push lorsque le programme est fermé.
  Pour les rappels en arrière-plan, utilisez en plus l'application installée depuis Chrome (PWA, voir
  `docs/utilisation.md`), qui reçoit le Web Push.
- Pas de mode hors ligne ; pas de mise à jour automatique (le site, lui, est toujours à jour : seul le
  « cadre » du programme est figé).
- Programme non signé (voir ci-dessus).

## Recette (confirmée sur un poste Windows)

- [ ] Le programme se lance, affiche la page de connexion sans avertissement de certificat.
- [ ] Connexion, création, déplacement et suppression d'un rendez-vous.
- [ ] Export (CSV, ICS) : le téléchargement fonctionne.
- [ ] Un lien externe s'ouvre dans le navigateur par défaut, pas dans la fenêtre.
- [ ] Un second lancement ramène la fenêtre existante.
- [ ] La croix réduit dans la zone de notification ; « Quitter » ferme.
- [ ] Réseau coupé : la page « Serveur injoignable » apparaît ; « Réessayer » fonctionne.
