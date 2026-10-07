# 10. Administration : utilisateurs, organisations, certifications et défis

## Qui voit quoi

| Écran | Route | Accès |
|---|---|---|
| Cours, exercices, jeux de données | `/admin/cours`, `/admin/exercices`, `/admin/datasets` | admin, formateur |
| Certifications | `/admin/certifications` | admin (`CertificationPolicy`) |
| Défis | `/admin/defis` | admin (`ChallengePolicy`) |
| Utilisateurs | `/admin/utilisateurs` | admin (`UserPolicy`) |
| Organisations | `/admin/organisations` | admin : toutes. Responsable d'organisation : les siennes (`OrganizationPolicy`) |

Les routes des organisations sont **hors** du groupe `role:admin,trainer`. Un élève responsable d'une classe peut
donc gérer ses membres. Le lien « Administration » du menu mène à ses organisations, et les onglets du back-office
sont filtrés selon les policies.

## Utilisateurs (`Admin\Users\UserIndex`)
- Recherche par nom ou e-mail, filtre par rôle, pagination.
- **Changement de rôle** : élève, formateur ou administrateur.
- **Désactivation** : `users.is_active = false`.
  - À la connexion, `Fortify::authenticateUsing` refuse le compte avec un message explicite.
  - Pendant une session déjà ouverte, le middleware `EnsureUserIsActive` (groupe `web`) déconnecte immédiatement l'utilisateur.
- **Garde-fous** :
  - on ne modifie jamais son propre compte depuis cet écran (403) ;
  - il reste toujours au moins un administrateur actif.

## Organisations
- Un administrateur crée une organisation. Son identifiant est dérivé du nom et doit être unique. Un **code d'invitation** `XXXX-XXXX` lui est attribué.
- **Rejoindre** : profil → *Organisations* → saisir le code, sans tenir compte de la casse. Ressaisir le code ne rétrograde pas un responsable.
- **Régénérer** le code invalide l'ancien, par exemple après une fuite.
- Un responsable peut :
  - ajouter un membre par e-mail (compte existant) ;
  - nommer d'autres responsables ;
  - retirer des membres.
- Une organisation **garde toujours un responsable** : ni rétrogradation, ni retrait, ni départ du dernier responsable tant que d'autres membres restent. Un administrateur peut passer outre.
- Seul un administrateur supprime une organisation.

Les organisations alimentent le classement par organisation (`LeaderboardService`) et les défis privés (`challenges.organization_id`).

## Certifications (`Admin\Certifications\*`)
- **Liste** : taille du sujet par rapport au pool (en rouge s'il est insuffisant), nombre de tentatives, taux de réussite et score moyen.
- **Éditeur** : niveau, moteur imposé ou non, seuil, durée, nombre de questions, tentatives maximales, délai entre tentatives, XP, statut.
- **Pool d'exercices** : les exercices *réservés* (sans leçon) apparaissent en tête, car les élèves n'ont pas pu s'y entraîner.
- **Publication refusée** si le pool contient moins d'exercices **publiés** que de questions par sujet.

## Défis (`Admin\Challenges\*`)
- **Champs** :
  - type (quotidien, arène, contre-la-montre, événement) ;
  - organisation (défi privé) ;
  - début et fin ;
  - chrono individuel en minutes, stocké en `duration_seconds` ;
  - multiplicateur d'XP.
- **Épreuves** : liste ordonnée (↑/↓), avec les **points** de chaque exercice. Elle est stockée dans `challenge_exercise.points` et `position`.
- **Publication** : au moins un exercice, et tous les exercices publiés.
- **Statistiques** : participants, classés, score moyen et top 10 (`Challenge::standings`).
- Modifier un défi déjà commencé **ne recalcule pas** les scores acquis : l'éditeur l'affiche en avertissement.
- Les défis quotidiens restent générés automatiquement (`challenges:daily`). L'éditeur sert à les corriger ou à créer des événements.
