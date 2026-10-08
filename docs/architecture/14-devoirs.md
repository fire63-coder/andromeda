# 14. Devoirs

Un responsable d'organisation, ou un administrateur, assigne une liste d'exercices aux membres de son organisation, avec une échéance facultative.

## Données

- `assignments` :
  - `organization_id`, `created_by`, `title`, `instructions`, `due_at` ;
  - `published_at` : `NULL` = brouillon, invisible des élèves. La date d'origine est conservée lors des modifications.
- `assignment_exercise` : `exercise_id`, `position`.
- Seuls les **exercices d'entraînement publiés** (rattachés à une leçon) peuvent être assignés : jamais ceux réservés aux certifications.

## Écrans

| Qui | Où | Quoi |
|---|---|---|
| Responsable | page de l'organisation → « Devoirs » | liste des devoirs, « Nouveau devoir » |
| Responsable | `/admin/organisations/{org}/devoirs/creer` et `…/{devoir}/modifier` | titre, consignes, échéance, publication ; exercices ajoutés depuis une liste filtrable puis ordonnés |
| Responsable | `/admin/organisations/{org}/devoirs/{devoir}` | grille élèves × exercices : ✓ à temps, ✓ en retard, … essayé, vide = pas commencé. Total par exercice et par élève |
| Élève | tableau de bord → « Devoirs à rendre » | devoirs publiés non terminés, les plus urgents d'abord (échéance en orange à moins de 2 jours, en rouge si dépassée) |
| Élève | `/devoirs/{devoir}` | consignes, avancement, état de chaque exercice, liens vers l'exercice |

## Règles

- **Exercice résolu** : un exercice est résolu dès que la progression de l'élève est « terminée » (`user_progress`), y compris s'il l'a été **avant** le devoir.
- **Retard** : « en retard » si `completed_at` est postérieur à l'échéance.
- **Accès** (`AssignmentPolicy`) : les membres voient les devoirs publiés de leur organisation. La gestion suit `OrganizationPolicy::update`. Un devoir appelé par l'URL d'une autre organisation renvoie 404.
