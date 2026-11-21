# Client Windows (programme .exe)

> **État : code écrit, non compilé et non exécuté par son auteur.** L'environnement de développement ne
> fournit ni .NET ni Windows : ni la compilation ni le fonctionnement n'ont pu être vérifiés. La première
> compilation se fait sur un serveur Windows de GitHub (workflow ci-dessous) ; un échec de compilation est
> possible et se corrige à partir du journal. Faites la recette du dernier paragraphe avant tout usage.

## Ce que c'est

Un vrai programme Windows (`RendezVousManager.exe`, .NET 8 + Microsoft Edge WebView2) dont la fenêtre affiche
l'application web. Aucune logique métier n'est dupliquée : droits, synchronisation, notifications et données
restent sur le serveur. Le programme ne stocke **aucun** mot de passe, jeton ou clé.

Fonctions : fenêtre native, icône dans la zone de notification (la croix réduit, « Quitter » ferme),
une seule instance, liens externes ouverts dans le navigateur par défaut (seul le site configuré s'affiche),
page « Serveur injoignable » avec bouton Réessayer, HTTPS obligatoire (http n'est accepté que pour localhost),
vérification TLS jamais désactivée, outils de développement désactivés.

Sources : dossier `client-windows/` (`MainForm.cs`, `Program.cs`, `AppSettings.cs`, `RendezVousManager.csproj`).

## Obtenir le .exe

1. Sur GitHub, ouvrez le dépôt → onglet **Actions** → **Client Windows** → **Run workflow**.
2. Attendez la fin (quelques minutes), ouvrez l'exécution, téléchargez l'artefact `RendezVousManager-win-x64` (un zip).
3. Décompressez, lancez `RendezVousManager.exe`. Windows 10/11 requis ; le composant **WebView2 Runtime**
   est déjà présent sur les Windows à jour (sinon le programme l'indique et donne le lien).

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

## Recette à faire sur un poste Windows

- [ ] Le programme se lance, affiche la page de connexion sans avertissement de certificat.
- [ ] Connexion, création, déplacement et suppression d'un rendez-vous.
- [ ] Export (CSV, ICS) : le téléchargement fonctionne.
- [ ] Un lien externe s'ouvre dans le navigateur par défaut, pas dans la fenêtre.
- [ ] Un second lancement ramène la fenêtre existante.
- [ ] La croix réduit dans la zone de notification ; « Quitter » ferme.
- [ ] Réseau coupé : la page « Serveur injoignable » apparaît ; « Réessayer » fonctionne.
