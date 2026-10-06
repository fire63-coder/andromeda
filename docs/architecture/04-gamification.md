# 04 — Gamification : XP, rangs, badges, classements

## XP et rangs
- Tout gain ou perte d'XP passe par `XpService::award()`, qui écrit une ligne immuable dans `xp_transactions`
  puis incrémente `users.xp`, qui n'en est qu'un cache. Le rang est recalculé à chaque fois (`ranks.min_xp`).
- Sources de gain :
  - **exercice réussi**, uniquement la première fois : `xp_reward` moins les indices consultés, avec un plancher de 20 % ;
  - **badge débloqué** : `badges.xp_bonus`.
- La série de jours d'activité est tenue par `StreakService` (`current_streak`, `longest_streak`).

## Badges
Les règles sont déclaratives (`badges.criteria`) et évaluées par `App\Services\Gamification\BadgeEvaluator`,
appelé de façon synchrone par le listener `EvaluateBadges` sur l'événement `SubmissionEvaluated`.

| `type` | Paramètres | Exemple |
|---|---|---|
| `exercises_solved` | `count` | Première requête |
| `skill_exercises_solved` | `skill`, `count` | Maître des Jointures |
| `exercise_type_solved` | `exercise_type`, `count` | Chasseur de Bugs (`bug_fix`) |
| `first_try_streak` | `count` | Sans faute |
| `daily_streak` | `days` | Régularité |
| `distinct_dialects_solved` | `count` | Polyglotte SQL (QCM exclus) |
| `certification_passed` | `level` | Expert certifié |

Ajouter un badge revient à insérer une ligne en base. Ajouter un type de règle demande une branche de plus dans
`BadgeEvaluator::progress()`, qui sert aussi à afficher l'avancement (« 3 / 25 ») sur le tableau de bord.

Une fois la soumission traitée, l'interface reçoit les événements navigateur `xp-gained`, `badge-unlocked` et `rank-up`,
affichés sous forme de notifications.

## Classements (`/classement`)
- Périodes : semaine, mois (somme de `xp_transactions` sur la période) et depuis toujours (`users.xp`).
- Portée : globale ou par organisation, limitée à celles dont l'utilisateur est membre.
- Les utilisateurs ayant `leaderboard_visible = false` ou `is_active = false` sont exclus.
- En cas d'égalité, les apprenants partagent la même position (1, 2, 2, 4).
- `php artisan leaderboard:snapshot`, planifié chaque jour à 00 h 05, fige les positions dans
  `leaderboard_snapshots`. Le classement s'en sert pour afficher la progression d'un jour à l'autre (▲ ▼).
  Il faut donc que le planificateur tourne : `* * * * * php artisan schedule:run`.
