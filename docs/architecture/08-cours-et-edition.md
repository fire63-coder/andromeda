# 08 — Cours : lecture et édition

## Côté élève
- `/cours` : catalogue par niveau, avec la progression de chaque cours.
- `/cours/{cours}` : chapitres, leçons (✅ celles terminées), schéma Mermaid de chaque chapitre, bouton « Commencer »
  ou « Continuer ».
- `/cours/{cours}/{leçon}` (`Learn\LessonViewer`). La liaison des routes est limitée au cours.
  - Le Markdown est rendu sans HTML brut et sans lien `javascript:`.
  - Chaque bloc ```` ```sql runnable ```` devient un éditeur CodeMirror modifiable et exécutable contre le jeu de
    données de la leçon, avec choix du moteur, réinitialisation et résultat sous le bloc. Ces blocs autorisent les
    SELECT et le DML, toujours annulé, et la limite d'exécutions de la sandbox s'applique.
  - Chaque bloc ```` ```mermaid ```` devient un diagramme. Mermaid est chargé à la demande, en niveau de sécurité `strict`.
  - La page liste aussi les exercices de la leçon et permet de naviguer vers la leçon précédente ou suivante.
  - « J'ai terminé » donne `xp_reward` une seule fois et recalcule le pourcentage du cours (`CourseProgress`).

## Côté équipe pédagogique (`/admin/cours`)
- `CourseEditor` :
  - fiche du cours : titre, identifiant, niveau, dialecte, résumé, présentation, durée, statut ;
  - chapitres : ajout, modification (le schéma Mermaid peut être repris d'un jeu de données), ordre, suppression
    s'ils sont vides ;
  - leçons : ajout, puis ouverture dans l'éditeur, et ordre.
- `LessonEditor` :
  - Markdown avec aperçu en direct ;
  - choix du jeu des exemples, de la durée, de l'XP et du statut ;
  - **« Tester les exemples »** exécute chaque bloc exécutable dans la sandbox et signale ceux qui échouent.
- Identifiants des leçons : uniques dans le cours entier, puisque l'URL ne contient pas le chapitre.

## Droits (`CoursePolicy`)
| | Formateur | Administrateur |
|---|---|---|
| Créer un cours | ✓, il en devient l'auteur | ✓ |
| Modifier | ses cours | tous |
| Statuts possibles | Brouillon, En relecture (plus le statut actuel s'il est déjà publié) | tous, y compris Publié et Archivé |
| Supprimer | — | ✓ |

Les élèves ne voient que les cours et leçons publiés. L'équipe pédagogique peut prévisualiser les brouillons.
