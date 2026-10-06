# 09 — Édition des exercices et relecture

## Éditeur (`/admin/exercices`)
- **Liste** (`Admin\Exercises\ExerciseIndex`) : recherche, filtre par statut, « Mes exercices ». Les exercices
  **à relire** apparaissent en tête, avec un compteur.
- **Formulaire** (`Admin\Exercises\ExerciseEditor`) :
  - identité : titre, identifiant, type, niveau, moteur imposé, leçon (ou « réservé » pour les certifications
    et les défis), difficulté, XP, statut ;
  - énoncé en Markdown, avec aperçu ;
  - SQL : code de départ (la requête boguée pour une « correction de bug ») et solution de référence ;
  - validation :
    - stratégie ;
    - instructions autorisées ;
    - mots-clés imposés ou interdits ;
    - tolérance numérique, délai, noms de colonnes imposés ;
    - requêtes de contrôle (`nom: SELECT …`) pour la validation par état des données ;
  - jeux de données : un jeu visible, et des jeux de test cachés ;
  - indices (texte et pénalité d'XP), compétences travaillées ;
  - QCM : réponses, bonne(s) réponse(s), explications.

## « Tester la solution » (`App\Services\Authoring\SolutionTester`)
L'exercice est enregistré, puis passe par le **vrai** moteur d'évaluation (`SubmissionEvaluator`) sur chaque moteur
disponible :
- la solution de référence doit être jugée **correcte** sur le jeu visible et sur tous les jeux cachés ;
- pour une correction de bug, le code de départ doit **échouer**, sinon il n'y a rien à corriger ;
- pour un QCM, il faut au moins 2 réponses, dont au moins une correcte.

## Circuit de relecture
| Étape | Qui | Effet |
|---|---|---|
| Brouillon | auteur (formateur ou admin) | invisible des élèves |
| En relecture | auteur | apparaît dans « à relire » |
| Publié | **administrateur uniquement** | refusé si le test de la solution échoue ; enregistre `reviewer_id` et `reviewed_at` |
| Archivé | administrateur | retiré du catalogue |

Un formateur ne modifie que ses propres exercices. Les exercices sans auteur, comme ceux de démo, sont réservés
aux administrateurs.

## Traductions
`lang/fr/validation.php` donne des messages de validation en français, pour les règles utilisées par l'application.
