# 07 — Arène : défi du jour et contre-la-montre

## Types de défis (`challenges.type`)
| Type | Fenêtre | Chrono | Création |
|---|---|---|---|
| `daily` | La journée (00 h 00 à 24 h 00) | — | `DailyChallengeGenerator`, via `php artisan challenges:daily` planifié à 00 h 01. Le défi est aussi créé à la volée à l'ouverture de l'arène s'il manque. |
| `timed` | `starts_at` → `ends_at` | `duration_seconds` par joueur, à partir de son inscription | Admin ou seeder |
| `event`, `arena` | `starts_at` → `ends_at` | optionnel | Admin |

Un défi peut être réservé à une organisation (`organization_id`) : seuls ses membres le voient et peuvent y participer.

## Règles de jeu
- **Défi du jour :**
  - 3 exercices d'entraînement, triés par difficulté ;
  - si possible différents de ceux des 7 derniers jours ;
  - valeur : `50 × difficulté + 50` points chacun ;
  - XP ×2.
- **Inscription** (`JoinChallenge`) : elle crée la participation et lance le chrono personnel d'un contre-la-montre.
- **Réponses** (`SubmitChallengeAnswer`) :
  - verdict immédiat ;
  - points à la **première** réussite de chaque exercice ;
  - XP = points / 10 × `xp_multiplier`, sans l'XP d'entraînement de l'exercice ;
  - la série de jours et les badges sont mis à jour.
- **Clôture :** quand tous les exercices sont résolus (`finished_at`), ou à l'échéance personnelle
  (`ChallengeParticipation::deadline()` : fin du chrono ou fin du défi, la plus proche). Après l'échéance,
  les réponses sont refusées côté serveur.
- **Classement du défi :** points décroissants, puis temps écoulé jusqu'à la dernière réussite (`total_time_ms`).
  Il est rafraîchi toutes les 15 s sur la page du défi.

## Pages
- `/arene` (`Arena\ArenaIndex`) : défi du jour, défis et contre-la-montre en cours, avec la progression du joueur.
- `/arene/{slug}` (`Arena\ChallengeRunner`) :
  - inscription ;
  - score et chronomètre ;
  - navigation entre exercices, avec leurs points ;
  - `ExercisePlayer` en mode `challenge` ;
  - classement du défi en direct.
