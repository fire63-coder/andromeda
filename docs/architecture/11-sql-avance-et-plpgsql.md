# 11. SQL avancé et programmation PostgreSQL (niveaux 3 et 4)

## Contenu

Les deux cours s'appuient sur le jeu de données **Entreprise** (`database/datasets/entreprise`) :
- `departments` ;
- `employees`, avec la hiérarchie `manager_id` ;
- `salary_audit`, vide, alimentée par un trigger.

Sa variante cachée contient :
- des **ex aequo** au sommet d'un département (un `ROW_NUMBER` y échoue là où `RANK` réussit) ;
- une hiérarchie plus profonde.

Contenu installé par `Database\Seeders\AdvancedContentSeeder`.

| Cours | Niveau | Moteurs | Leçons | Exercices |
|---|---|---|---|---|
| SQL avancé | 3 · Avancé | SQLite, MySQL, PostgreSQL | Classer et cumuler · Parcourir une hiérarchie | RANK par département, écart à la moyenne, cumul, CTE récursive, QCM |
| Programmation PostgreSQL | 4 · Expert | PostgreSQL (`courses.sql_dialect_id`) | Fonctions et procédures · Triggers | fonction SQL, fonction PL/pgSQL, procédure, chasse au bug, trigger d'audit, QCM |

- **Certification SQL — Avancé** : un sujet de 3 questions tirées parmi 3 exercices réservés et un QCM.
- **Correction des exercices de code stocké** : stratégie *état des données*. Le code de l'élève est exécuté, puis les **requêtes de contrôle** de l'exercice, par exemple :
  - `SELECT id, salaire_annuel(id) FROM employees` ;
  - `CALL augmenter_departement(2, 5)` ;
  - `WITH maj AS (UPDATE ...) SELECT ...` puis `SELECT * FROM salary_audit`.

  Elles sont comparées aux mêmes requêtes lancées après la solution de référence, sur chaque jeu de données.

## Famille d'instructions `routine`

`StatementKind::Routine` couvre :
- `CREATE [OR REPLACE] FUNCTION | PROCEDURE` ;
- `DROP FUNCTION | PROCEDURE` ;
- `CALL`.

Un trigger (`CREATE TRIGGER`) reste du DDL. Un exercice de trigger autorise donc `routine` **et** `ddl`.

- **Moteurs** : seul `PostgresDriver::supportsRoutines()` renvoie vrai. Les exercices `routine` ne sont proposés que sur PostgreSQL, et SQLite et MySQL refusent ces instructions avec un message clair.
- **Leçons** : les exemples exécutables acceptent `select`, `dml`, `ddl` et `routine` (`LessonRenderer::SNIPPET_GUARD`), toujours annulés. Un cours lié à un moteur ne propose que ce moteur.
- **Jeux de données** : un script de jeu de données ne peut pas contenir de routine (`inspectScript`). Il est chargé par le compte propriétaire, et aucun code ne doit s'exécuter avec ses droits.

## Sécurité du code stocké

Trois couches indépendantes protègent le serveur.

### 1. Analyse lexicale (`QueryGuard::checkRoutine`)

- **Langages** : `plpgsql` et `sql` uniquement, sans guillemets.
- **Corps** : obligatoirement entre `$$`. La forme `AS '...'` et `BEGIN ATOMIC` sont refusées.
- **En-tête** : clause `SET` refusée, car elle lèverait `statement_timeout`.
- **Corps analysé instruction par instruction** :
  - pas de `SET`, `RESET`, `COMMIT`, `ROLLBACK`, `LOCK`, etc. en début d'instruction (après `;`, `BEGIN`, `THEN`, `ELSE`, `LOOP`, `DECLARE`, `>>`) ;
  - pas de SQL dynamique (`EXECUTE`, y compris `RETURN QUERY EXECUTE`) ;
  - pas de création d'objets interdits ;
  - pas d'interception de `query_canceled` ni de `SQLSTATE '57…'`.
- **Mots interdits** (`pg_terminate_backend`...) cherchés aussi dans les **identifiants délimités** (`"pg_terminate_backend"(...)`) et dans les **indices de tableau** (`arr[...]`). Cette vérification vaut pour toutes les requêtes, pas seulement les routines.

### 2. Droits PostgreSQL (`php artisan sandbox:setup-pgsql`)

- `pg_cancel_backend` et `pg_terminate_backend` sont retirés de `PUBLIC`. Sans cela, un compte peut couper les connexions de son propre rôle, partagé par tous les élèves.
- Le compte propriétaire reçoit `pg_signal_backend`. Comme il est `NOINHERIT`, il doit l'endosser par `SET ROLE` : seul le chien de garde le fait.

### 3. Chien de garde (`Runners/pg-watchdog.php`)

- Pour toute requête qui contient une routine, le pilote lance un petit processus PHP avec un délai égal au délai de la requête plus 1 s. Ce processus coupe la connexion d'exécution (`pg_terminate_backend`, non interceptable) si elle tourne encore.
- Le pilote l'arrête dès la fin de la requête. S'il a dû couper, l'élève voit « La requête a dépassé le temps limite ».
- Les identifiants passent par une variable d'environnement : jamais en argument (visible dans `ps`), ni sur l'entrée standard (que Symfony Process n'écrit qu'au fil de l'eau).

> Après une mise à jour, relancer `php artisan sandbox:setup-pgsql` pour appliquer les nouveaux droits.

## Éditeur d'exercices

La case « Fonctions et procédures (PostgreSQL) » ajoute `routine` à `allowed_statements`. Les mots-clés imposés ou interdits (`IF`, `LOOP`...) sont aussi cherchés dans le corps des fonctions.
