# 12. Optimisation : index et plans d'exécution

## Cours « Optimisation des requêtes » (niveau 4, PostgreSQL)

- **Leçon « Index et plans d'exécution »** :
  - `EXPLAIN` et `EXPLAIN ANALYZE` sur 200 000 lignes générées, avec puis sans index ;
  - conditions indexables ;
  - index composites.
- **Exercices** (SQLite et PostgreSQL) :

| Exercice | Ce que l'élève écrit | Ce qui est vérifié |
|---|---|---|
| Index : retrouver les subordonnés | `CREATE INDEX` | plan de `WHERE manager_id = 5` |
| Chasse au bug : l'index ignoré | une requête réécrite | même résultat que la solution (jeux cachés compris) **et** recherche indexée |
| Index : une jointure sans parcours complet | deux `CREATE INDEX` | aucune des deux tables lue en entier |
| QCM : ordre des colonnes d'un index | — | — |

Le jeu « Entreprise » contient désormais l'index `idx_employees_hired_at`.

## Certification SQL — Expert

Un sujet de 3 questions, tirées parmi :
- une fonction (masse salariale, `COALESCE`) ;
- un trigger `BEFORE` (un salaire ne baisse jamais) ;
- un index (recherche par intitulé) ;
- le QCM BEFORE/AFTER.

## Stratégie « plan d'exécution » (`ValidationStrategy::QueryPlan`)

Options de l'exercice :
- **`index_tables`** : les tables qui doivent être atteintes par une **recherche** dans un index ;
- **`plan_query`** :
  - renseigné : le code de l'élève (par exemple `CREATE INDEX`) est exécuté, puis le plan de cette requête est lu ;
  - vide : la requête de l'élève doit d'abord donner le même résultat que la solution sur tous les jeux, puis son propre plan est lu.

`App\Services\Evaluation\PlanInspector` lit le plan :

| Moteur | Requête | Compte comme recherche indexée |
|---|---|---|
| SQLite | `EXPLAIN QUERY PLAN` | `SEARCH t USING …` ; `SCAN t USING INDEX` est un parcours complet. Les alias sont ramenés aux tables. |
| PostgreSQL | `SET LOCAL enable_seqscan = off` puis `EXPLAIN (FORMAT JSON)` | `Index Scan` / `Index Only Scan` **avec** `Index Cond`, `Bitmap Heap Scan`. Un `Index Scan` qui ne fait que filtrer est un parcours complet. |

MySQL n'est pas proposé pour ces exercices : il refuse le DDL annulable, et son optimiseur ignore les index sur de petites tables.

En cas d'échec, l'élève voit le plan obtenu, par exemple `SCAN e` ou `Seq Scan on employees (Filter: …)`.

## DDL sur les tables du jeu de données (PostgreSQL)

Le compte d'exécution n'est pas propriétaire des tables. Il ne doit pas l'être : il pourrait alors lire les schémas des jeux cachés.
- **Ce qui est concerné** : `CREATE INDEX`, `ALTER TABLE`, `DROP TABLE`, `TRUNCATE`, etc.
- **Ce que fait le pilote** : il copie les tables du build dans le schéma **temporaire** de la session (`LIKE … INCLUDING ALL`, sans les clés étrangères), et place ce schéma en tête du `search_path`.
- **Résultat** : l'élève en est propriétaire, et tout disparaît au `ROLLBACK`.
- **Fonctions** : les fonctions créées dans la même requête vont dans le schéma du build. PostgreSQL ne cherche jamais de fonction dans `pg_temp`, mais leurs requêtes lisent bien les copies à l'exécution.

## Corrections annexes

- `EXPLAIN ANALYZE INSERT/UPDATE/DELETE` est classé comme une modification, car il exécute l'instruction.
- **Éditeur d'exercices** :
  - les noms de requêtes de contrôle peuvent contenir espaces et parenthèses (`salaire_annuel(id): SELECT …`) ;
  - `max_statements` est éditable et n'est plus perdu à l'enregistrement.
