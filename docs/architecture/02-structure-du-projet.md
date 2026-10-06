# 02 — Structure du projet

Principes :
- **Composants Livewire minces** : ils gèrent l'état de l'écran et délèguent toute logique métier
  aux **services** (`app/Services`) — testables sans navigateur et réutilisables (API, commandes Artisan).
- **Événements de domaine** (`SubmissionEvaluated`, `ExerciseSolved`…) pour découpler l'évaluation
  de la gamification : l'éditeur n'a pas à connaître XP, badges ni classements.
- **Tâches lourdes en file d'attente** (import de datasets, builds par dialecte, snapshots de classements).

## Arborescence cible

```text
app/
├── Enums/                         # ✅ créés — UserRole, ExerciseType, ValidationStrategy, SubmissionStatus…
├── Models/                        # ✅ créés — un modèle par table (relations, casts, scopes)
│
├── Livewire/                      # Composants Livewire (namespace App\Livewire)
│   ├── Learn/                     # ─── Espace étudiant
│   │   ├── Dashboard.php          #   XP, rang, série, progression par niveau, prochaine leçon
│   │   ├── CourseCatalog.php      #   filtres niveau / dialecte / compétence
│   │   ├── CourseShow.php
│   │   ├── LessonViewer.php       #   Markdown rendu + blocs SQL exécutables + schéma Mermaid
│   │   └── LessonPlayground.php   #   mini-éditeur embarqué dans une leçon
│   ├── Exercises/
│   │   ├── ExercisePlayer.php     #   ✅ page d'exercice : éditeur, Exécuter / Valider, verdict, schéma, indices, QCM
│   │   ├── ResultGrid.php         #   tableau de résultats + diff attendu/obtenu
│   │   ├── SchemaExplorer.php     #   tables / colonnes / clés du dataset (panneau latéral)
│   │   ├── HintPanel.php
│   │   └── McqPlayer.php
│   ├── Arena/
│   │   ├── DailyChallenge.php
│   │   ├── ChallengeLobby.php
│   │   └── TimedChallengeRunner.php  # chrono côté serveur, wire:poll pour l'affichage
│   ├── Certification/
│   │   ├── CertificationList.php
│   │   ├── CertificationRunner.php
│   │   └── CertificateVerify.php     # page publique /certificats/{code}
│   ├── Gamification/
│   │   ├── Leaderboard.php           # global / organisation × semaine / mois / tout
│   │   ├── BadgeShowcase.php
│   │   └── XpToast.php               # écoute les événements navigateur « xp-gained », « badge-unlocked »
│   ├── Profile/
│   │   └── LearningStats.php         # taux de réussite, compétences (radar), historique
│   └── Admin/                        # ─── Back-office (admin + formateur selon Policies)
│       ├── Dashboard.php
│       ├── Courses/{CourseIndex, CourseForm, ChapterManager, LessonEditor}.php
│       ├── Exercises/{ExerciseIndex, ExerciseForm, SolutionTester, ReviewQueue}.php
│       ├── Datasets/
│       │   ├── DatasetIndex.php
│       │   ├── DatasetImportWizard.php   # upload SQL/CSV/JSON → aperçu → mapping → import en file
│       │   ├── DatasetShow.php           # tables, aperçu des données, builds par dialecte, exercices liés
│       │   └── DatasetBuildStatus.php    # wire:poll sur l'état des jobs
│       ├── Badges/{BadgeIndex, BadgeForm}.php
│       ├── Certifications/{CertificationIndex, CertificationForm}.php
│       ├── Challenges/{ChallengeIndex, ChallengeForm}.php
│       └── Users/{UserIndex, UserForm}.php
│
├── Services/
│   ├── Sandbox/                              # ✅ SQLite + PostgreSQL (voir 03-sandbox-et-evaluation.md)
│   │   ├── Contracts/SandboxDriver.php       # provision(), execute(), reset(), destroy()
│   │   ├── Drivers/{Sqlite, Postgres, MySql, SqlServer, Oracle}Driver.php
│   │   ├── SandboxManager.php                # choisit le driver selon le dialecte, réutilise / crée la session
│   │   ├── QueryGuard.php                    # liste blanche d'instructions, mots interdits, multi-statements
│   │   └── QueryResult.php                   # DTO : columns, rows, rowCount, durationMs, error
│   ├── Evaluation/                           # ✅
│   │   ├── SubmissionEvaluator.php           # orchestre : guard → exécution sur chaque dataset → comparaison
│   │   ├── Comparators/{ResultSet, OrderedResultSet, StateCheck, Choices}Comparator.php
│   │   ├── ResultNormalizer.php              # types, NULL, flottants, casse, noms de colonnes
│   │   └── EvaluationResult.php              # DTO : verdict, score, diff, feedback pédagogique
│   ├── Datasets/
│   │   ├── Importers/{SqlDump, Csv, Json}Importer.php
│   │   ├── SchemaIntrospector.php            # remplit tables_meta + diagramme Mermaid
│   │   └── DialectTranslator.php             # DDL canonique → DDL par dialecte (types, quoting, identity)
│   ├── Gamification/
│   │   ├── XpService.php                     # ✅ écrit xp_transactions, met à jour users.xp et le rang
│   │   ├── BadgeEvaluator.php                # évalue badges.criteria
│   │   ├── StreakService.php                 # ✅
│   │   └── LeaderboardService.php
│   └── Content/
│       └── MarkdownRenderer.php              # Markdown → HTML (+ blocs ```sql runnable, mermaid)
│
├── Actions/                       # Actions unitaires (Fortify/Jetstream existants + métier)
│   └── Certification/{StartAttempt, SubmitAttempt, IssueCertificate}.php
├── Events/                        # SubmissionEvaluated, ExerciseSolved, LessonCompleted, BadgeUnlocked, CertificationPassed
├── Listeners/                     # AwardXp, UpdateProgress, EvaluateBadges, UpdateStreak
├── Jobs/                          # ImportDataset, BuildDatasetForDialect, ComputeExpectedResult,
│                                  # SnapshotLeaderboards, DestroyExpiredSandboxes
├── Policies/                      # CoursePolicy, ExercisePolicy, DatasetPolicy… (rôles admin / trainer / student)
├── Http/Middleware/EnsureUserHasRole.php   # route middleware `role:admin,trainer`
└── Console/Commands/              # sandbox:prune, datasets:rebuild, leaderboard:snapshot

config/
└── sandbox.php                    # connexions sandbox par dialecte, timeouts, limites de lignes, TTL

database/
├── migrations/                    # ✅ créées
├── seeders/                       # ✅ dialectes, niveaux, rangs, compétences, badges (+ comptes de démo en local)
├── factories/
└── datasets/                      # jeux de données officiels livrés avec l'app (northwind-like, rh, e-commerce)
    ├── ecommerce/{schema.sql, data/*.csv, dataset.json}
    └── …

resources/
├── views/
│   ├── layouts/{app, learn, admin}.blade.php
│   ├── livewire/                  # vues des composants (miroir de app/Livewire)
│   └── components/                # composants Blade : sql-editor, result-table, xp-bar, badge, rank-chip, schema-diagram
├── js/
│   ├── app.js
│   ├── editor/sql-editor.js       # CodeMirror 6 : @codemirror/lang-sql (dialectes MySQL, PostgreSQL, MSSQL, PL/SQL, SQLite),
│   │                              #   thème sombre, Ctrl+Entrée = exécuter, autocomplétion alimentée par tables_meta
│   └── diagrams/mermaid.js
└── markdown/

routes/
├── web.php                        # pages étudiant
└── admin.php                      # back-office, préfixe /admin, middleware auth + role:admin,trainer

tests/
├── Unit/Evaluation/               # comparateurs, normaliseur — le cœur doit être couvert à 100 %
├── Unit/Sandbox/QueryGuardTest.php
└── Feature/Livewire/ExercisePlayerTest.php
```

## Flux d'une soumission (aperçu de l'étape 3)

```text
ExercisePlayer (Livewire)
  └─ validate()
       └─ SubmissionEvaluator::evaluate(user, exercise, sql)
            ├─ QueryGuard::check(sql, exercise.validation_options)      → rejet immédiat si interdit
            ├─ pour chaque dataset (primary + hidden_test) :
            │     SandboxManager::for(user, dataset, dialect)->execute(sql, timeout)
            │     Comparator::compare(résultat, expected_result)
            └─ EvaluationResult (verdict, score, diff)
       ├─ UserSubmission::create(...)
       └─ event(SubmissionEvaluated) ──► AwardXp, UpdateProgress, EvaluateBadges, UpdateStreak
  └─ dispatch navigateur « xp-gained » / « badge-unlocked » → XpToast
```

## Pourquoi CodeMirror 6 plutôt que Monaco

Monaco pèse plusieurs Mo, cohabite mal avec le DOM-diffing de Livewire et n'a pas de vrais modes par dialecte SQL.
CodeMirror 6 est modulaire (~150 Ko), propose `@codemirror/lang-sql` avec les dialectes MySQL, PostgreSQL,
MSSQL, PL/SQL et SQLite, et s'intègre proprement via `wire:ignore` + `$wire.entangle` / Alpine.

## Version de la stack

| Composant | Version |
|---|---|
| PHP | 8.3+ |
| Laravel | 13.x (structure allégée : `bootstrap/app.php`, `bootstrap/providers.php`, plus de Kernels) |
| Livewire | 4.x — composants en classe dans `app/Livewire` (préférables ici pour tester la logique d'évaluation) |
| Jetstream | 5.x (stack Livewire) + Fortify — authentification, 2FA, profil, suppression de compte |
| Sanctum | 4.x |
| PHPUnit | 12.x |
| Front | Vite 8, laravel-vite-plugin 3, Tailwind CSS 3.4 (vues Jetstream) |

Alpine.js est fourni par Livewire : `resources/js/app.js` ne doit pas le réimporter.
Les files d'attente et le cache utilisent la base de données (`QUEUE_CONNECTION=database`,
`CACHE_STORE=database`) : lancer `composer dev` démarre serveur, worker de file et Vite ensemble.
