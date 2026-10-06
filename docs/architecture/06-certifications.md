# 06 — Certifications

## Parcours candidat
1. `/certifications` (`Certification\CertificationList`) affiche les épreuves ouvertes, avec leur durée, leur nombre
   de questions, le seuil de réussite, les tentatives restantes et le délai avant la prochaine tentative.
2. **Commencer** (`StartCertificationAttempt`) :
   - tire au sort `exercises_count` exercices dans le pool (`certification_exercise`), puis fige le sujet
     dans `certification_attempts.exercise_ids` ;
   - démarre le chronomètre (`expires_at`) ;
   - si une tentative est déjà en cours, elle est reprise au lieu d'en créer une nouvelle.
3. `/certifications/tentatives/{id}` (`CertificationRunner`) :
   - chronomètre et navigation entre les questions ;
   - chaque question est un `ExercisePlayer` en mode `certification`.
     - « Exécuter » reste disponible.
     - « Valider » enregistre la réponse (`RecordCertificationAnswer`) sans révéler le verdict. C'est la dernière
       réponse qui compte.
     - Pas d'indices et pas d'XP par question.
4. **Clôture** (`FinishCertificationAttempt`) : par le bouton « Terminer », à la fin du chronomètre côté navigateur,
   ou par `php artisan certifications:expire`, planifiée chaque minute.
   - Le score est la moyenne des scores de la dernière réponse à chaque question (0 sans réponse). Un résultat juste
     sur le jeu visible mais faux sur un jeu caché compte 50.
   - En cas de réussite : code de certificat unique (`AND-XXXX-XXXX-XXXX`), `xp_reward` et évaluation des badges
     (badge « Expert certifié » au niveau 4).
   - La correction détaillée par question devient alors visible.
5. `/certificats/{code}` est une page **publique**, imprimable, qui permet de vérifier l'authenticité d'un certificat.

## Règles
- Les exercices sans leçon (`lesson_id = NULL`) sont **réservés** aux certifications et aux défis. Ils
  n'apparaissent pas en entraînement (`Exercise::practice()`, `ExercisePolicy`) et ne s'ouvrent que dans le contexte
  d'une épreuve dont ils font partie, appartenant à l'utilisateur.
- Les réponses arrivées après l'échéance sont refusées côté serveur, quel que soit l'état du navigateur.
- Limites par certification : `max_attempts`, `cooldown_hours` entre deux tentatives, et aucune nouvelle tentative
  une fois la certification obtenue.
- `certifications.sql_dialect_id` impose un moteur, par exemple pour une certification « PostgreSQL ».

## Modes du composant `ExercisePlayer`
| Mode | Accès | Verdict | XP / indices | Enregistrement |
|---|---|---|---|---|
| `practice` | `ExercisePolicy` | immédiat | oui | `SubmitAnswer` |
| `certification` | contexte `CertificationAttempt` | à la fin | non | `RecordCertificationAnswer` |
| `challenge` | contexte `ChallengeParticipation` | immédiat, en points | bonus en fin de défi | voir 07 |

Le contexte (`App\Contracts\ExerciseContext`) indique si l'exercice fait partie de l'épreuve, quel moteur est imposé,
et enregistre la réponse.
