# Guide d'utilisation

## Se connecter

Ouvrez l'adresse de l'application et saisissez votre identifiant (ou e-mail) et votre mot de passe. Après **5 échecs** d'affilée, la connexion est bloquée quelques minutes. Si un administrateur vous a créé un compte, le mot de passe qu'il vous a donné est **provisoire** : l'application vous demande d'en choisir un nouveau avant tout autre usage (12 caractères minimum, avec majuscule, minuscule et chiffre).

Menu rond en haut à droite : paramètres, thème (clair, sombre, automatique), déconnexion. Le voyant de synchronisation indique « À jour », « Synchronisation… », « Hors ligne » ou « Erreur » ; cliquez dessus pour actualiser.

## Tableau de bord

Compteurs réels de vos calendriers : total, aujourd'hui, semaine, mois, à venir (30 jours), terminés et annulés sur la période choisie, rappels des 24 prochaines heures ; prochain rendez-vous (cliquez pour l'ouvrir) ; répartition par statut et par catégorie (avec une alternative « tableau ») ; charge des 14 prochains jours ; raccourcis. Le choix de période est mémorisé.

## Le calendrier

- **Vues** : Jour, Semaine, Mois, Planning (liste) ; flèches ou mini-calendrier pour naviguer ; « Aujourd'hui » pour revenir.
- **Créer** : cliquez sur un créneau, ou faites glisser pour choisir une durée, ou bouton **Nouveau rendez-vous**, ou touche **C** au clavier. Cliquez dans la zone « Journée » pour un événement de toute la journée.
- **Déplacer / redimensionner** : glissez le rendez-vous ou son bord inférieur ; au tactile, appui long. Pour une série, l'application demande ce qu'il faut modifier.
- **Ouvrir** : cliquez sur un rendez-vous : détails, historique des modifications, actions (modifier, dupliquer, reporter d'un jour ou d'une semaine, annuler, terminer, supprimer).
- **Calendriers** : la barre latérale liste les calendriers ; cochez/décochez pour les afficher ou les masquer.
- **Conflits** : si deux rendez-vous d'un même calendrier se chevauchent, l'application vous le signale et vous laisse enregistrer quand même.
- **Modifications simultanées** : si quelqu'un modifie le même rendez-vous avant vous, l'application le détecte et propose de recharger ou d'écraser en connaissance de cause.

## Rendez-vous

Champs : titre, début et fin (ou « journée entière »), fuseau horaire, calendrier, catégorie, contact, lieu, adresse, description, notes, statut (planifié, confirmé, provisoire, terminé, annulé), priorité, couleur, **rappel**.

**Récurrence** : tous les jours/semaines/mois/ans, intervalle, jours de la semaine, jour du mois, fin (jamais, après N occurrences, à une date). Pour une série, on choisit de modifier **cette occurrence**, **celle-ci et les suivantes**, ou **toute la série** ; on peut annuler une seule occurrence.

**Suppression** : un rendez-vous supprimé va à la corbeille (page *Tous les rendez-vous* → case « Corbeille ») et peut être restauré.

**Listes** : *Mes rendez-vous*, *Tous les rendez-vous*, *Aujourd'hui*, *À venir*, avec recherche, filtres (statut, priorité, catégorie, période), tri et pagination.

## Contacts, catégories, calendriers

- **Contacts** : fiche (nom, société, téléphone, e-mail, adresse, notes) avec ses rendez-vous liés. Chacun gère ses propres contacts.
- **Catégories** : nom unique et couleur ; réservées aux responsables et administrateurs. Supprimer une catégorie détache ses rendez-vous.
- **Calendriers** : créez vos calendriers (personnel, équipe…), choisissez leur visibilité et **partagez-les** avec d'autres utilisateurs en cochant leurs droits (lire, créer, modifier, supprimer, partager).

## Paramètres personnels

Page **Paramètres** (menu rond en haut à droite). Tout est enregistré **sur le serveur** : vous retrouvez les mêmes préférences sur chaque appareil.
- **Profil** : prénom, nom, fuseau horaire (l'identifiant et l'adresse e-mail se changent par un administrateur).
- **Affichage et calendrier** : thème (clair, sombre, automatique), vue d'ouverture du calendrier, premier jour de la semaine, heures de début et de fin de la grille, pas horaire, week-end, format 12/24 heures, durée par défaut d'un nouveau rendez-vous, calendrier proposé par défaut. Le bouton « Réglages » du calendrier modifie les mêmes réglages.
- Si vous n'avez rien choisi, ce sont les valeurs par défaut de l'**organisation** qui s'appliquent.
- **Organisation** (administrateur) : nom affiché (titre, en-tête, page de connexion), fuseau horaire par défaut des nouveaux comptes et valeurs par défaut d'affichage.

Vos anciens réglages de calendrier mémorisés dans le navigateur (avant cette version) sont repris automatiquement une fois, puis supprimés du navigateur.

## Notifications

- **Dans l'application** : cloche en haut (pastille = non lues) ; page *Notifications* (non lues, tout marquer lu, rappels à venir).
- **Web Push** : dans *Paramètres*, activez les notifications du navigateur (nécessite HTTPS) ; un bouton envoie un test.
- **E-mail** : si l'administrateur a configuré l'envoi ; bouton de test dans les paramètres.
- Réglages : activer/désactiver, rappel par défaut, heures silencieuses (hors « dans l'application »), confidentialité (texte complet ou minimal), rattrapage des rappels manqués.
- Les rappels sont envoyés par le serveur même si vous n'êtes pas connecté ; leur ponctualité dépend de la tâche planifiée (cron) de l'hébergeur (une minute au mieux).

## Import / export

Page **Import / export**. 
- **Exporter** les rendez-vous (CSV, Excel, PDF, iCalendar) avec période, calendrier et statut, ou les contacts (CSV, Excel). Les valeurs qui ressembleraient à une formule sont précédées d'une apostrophe.
- **Importer** : choisissez le type de fichier, un calendrier par défaut, le fichier, puis **Vérifier sans importer** (simulation) et **Importer**. Les lignes en erreur sont listées avec leur numéro ; les doublons sont ignorés (réimporter est sans effet). L'import Excel n'existe pas : enregistrez en CSV.
- Colonnes CSV reconnues : Titre\*, Début\*, Fin, Journée entière, Calendrier, Fuseau, Statut, Priorité, Catégorie, Lieu, Adresse, Description, Notes, Récurrence. Dates : `AAAA-MM-JJ HH:MM` ou `JJ/MM/AAAA HH:MM`.

## Administration (administrateur)

- **Utilisateurs** : créer un compte (le mot de passe provisoire s'affiche **une seule fois** : notez-le et transmettez-le de façon sûre), modifier, changer le rôle, désactiver/réactiver, réinitialiser un mot de passe, débloquer, déconnecter de force.
- **Rôles** : Administrateur (tout), Responsable (tous les calendriers, catégories, rapports, journal, import/export), Utilisateur (ses calendriers et ceux partagés), Lecture seule (consultation seulement).
- **Historique** : journal d'audit filtrable (qui, quoi, quand).
- **Sauvegardes** : créer, vérifier, télécharger, supprimer ; la restauration se fait en ligne de commande par l'exploitant (`docs/installation.md`).

## Raccourcis et accessibilité

Touche **C** : nouveau rendez-vous ; **Échap** : fermer une fenêtre. Tout est utilisable au clavier (formulaires en alternative au glisser-déposer) ; le thème sombre et le mode clair suivent votre système.

## Limites connues

L'application est en français uniquement. Aucune application mobile native ; la page fonctionne sur téléphone et tablette. Voir le `README.md` pour la liste précise de ce qui est testé ou non.
