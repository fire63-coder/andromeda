# 15. Transactions et concurrence

## Scénarios à plusieurs sessions

Un **scénario** est un script dont chaque étape commence par une ligne `-- A`, `-- B` ou `-- C` (ou `-- Session A : commentaire`). L'étape appartient à la session indiquée, et les étapes s'exécutent dans l'ordre du texte :

```sql
-- A
BEGIN ISOLATION LEVEL REPEATABLE READ;
SELECT stock FROM products WHERE id = 1;
-- B
UPDATE products SET stock = stock - 1 WHERE id = 1;
-- A
SELECT stock FROM products WHERE id = 1;
COMMIT;
```

- **Où** : dans une leçon, un bloc ```` ```sql runnable ```` qui contient ces marqueurs est exécuté comme scénario. PostgreSQL uniquement.
- **Analyse** (`ScenarioParser`) : au plus 30 étapes. Chaque étape passe le `QueryGuard`, qui accepte les lectures, les modifications et le contrôle de transaction (`StatementKind::Transaction`).
- **Contrôle de transaction** : les formes exactes `BEGIN` / `START TRANSACTION [ISOLATION LEVEL …]`, `SET TRANSACTION ISOLATION LEVEL …`, `COMMIT`, `ROLLBACK [TO SAVEPOINT x]`, `SAVEPOINT`, `RELEASE` et `LOCK TABLE … IN … MODE [NOWAIT]`. Tout autre `SET`, le DDL et le code stocké restent refusés. Hors scénario, ces instructions restent interdites.

## Exécution (`Scenario\PostgresScenarioRunner`)

1. **Copie jetable** : le compte propriétaire crée un schéma `sc_<horodatage>_<aléa>` (tables, index et données du build), puis donne au compte d'exécution les droits de lecture et d'écriture.
2. **Sessions** : chaque session est une vraie connexion du compte d'exécution, ouverte avec l'extension PHP `pgsql`. Le pilote fixe lui-même `statement_timeout`, `lock_timeout` et `search_path`.
3. **Pilotage asynchrone** (`pg_send_query`) :
   - une instruction qui ne répond pas en 300 ms est **bloquée** (verrou) ;
   - les étapes suivantes de sa session attendent leur tour, celles des autres sessions continuent ;
   - la frise indique quand l'instruction a repris.
4. **Fin** :
   - les instructions encore bloquées ont droit à un délai, puis sont annulées ;
   - les sessions sont fermées, ce qui annule toute transaction ouverte ;
   - les requêtes de contrôle lisent l'**état final** ;
   - le schéma est supprimé.
5. **Filet de sécurité** : `sandbox:purge-scenarios`, planifiée toutes les 10 minutes, supprime les copies orphelines de plus de 10 minutes.

Les `COMMIT` des sessions sont réels, mais ne touchent que la copie : le jeu de données n'est jamais modifié.

La frise (`livewire/partials/scenario-timeline`) affiche :
- une colonne par session ;
- le résultat de chaque étape ;
- l'attente d'un verrou et l'étape après laquelle la session a repris ;
- les erreurs, expliquées : 40001 sérialisation, 40P01 interblocage, 55P03 verrou, 57014 délai, 25P02 transaction en échec.

## Correction (`ValidationStrategy::Concurrency`)

| Contrôle | Option |
|---|---|
| Même **enchaînement des sessions** que la solution (« A → B → A → B ») : l'élève corrige le contenu des étapes, pas leur ordre | toujours |
| Aucune étape en erreur (interblocage, sérialisation…) | `no_errors` |
| Résultat de certaines étapes identique à celui de la solution (ex. les deux lectures de A) | `compare_steps` |
| État final identique à celui de la solution, sur le jeu visible **et** les jeux cachés | `check_queries` |

## Contenu : « Transactions et concurrence » (niveau 4, PostgreSQL, jeu Boutique)

- **Leçons** :
  - transactions ACID (tout ou rien, pas de lecture sale, `SAVEPOINT`) ;
  - niveaux d'isolation et anomalies (lecture non répétable, mise à jour perdue, échec de sérialisation) ;
  - verrous et interblocages (`FOR UPDATE`, ordre de verrouillage).
- **Exercices** :
  - **la vente perdue** : mise à jour relative ou `FOR UPDATE` ; une valeur codée en dur échoue sur le jeu caché ;
  - **un inventaire cohérent** : `REPEATABLE READ` ou `SERIALIZABLE` ;
  - **le transfert qui s'interbloque** : même ordre de verrouillage ;
  - deux QCM : READ UNCOMMITTED en PostgreSQL, réagir à une erreur 40001.

## Relecture des exercices

Un formateur qui modifie réellement un exercice **publié** le renvoie « En relecture » : il n'est plus proposé aux élèves jusqu'à sa republication par un administrateur, après le test de la solution. Les modifications d'un administrateur, ou un enregistrement sans changement, le laissent publié.
