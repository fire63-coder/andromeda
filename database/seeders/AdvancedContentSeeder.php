<?php

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\DatasetRole;
use App\Enums\ExerciseType;
use App\Enums\ValidationStrategy;
use App\Models\Certification;
use App\Models\Course;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\Level;
use App\Models\Skill;
use App\Models\SqlDialect;
use App\Services\Datasets\SchemaIntrospector;

/**
 * Contenu des niveaux 3 et 4 sur le jeu « Entreprise » :
 * - Avancé : fonctions de fenêtrage, requêtes récursives, certification ;
 * - Expert : programmation PostgreSQL (fonctions SQL et PL/pgSQL, procédures, triggers),
 *   optimisation (index, plans d'exécution) et certification SQL — Expert.
 */
class AdvancedContentSeeder extends DemoContentSeeder
{
    public function run(SchemaIntrospector $introspector): void
    {
        $directory = database_path('datasets/entreprise');
        $schema = file_get_contents("{$directory}/schema.sql");

        $company = $this->dataset($introspector, 'entreprise', 'Entreprise', 'Départements, employés, hiérarchie managériale et historique des salaires.', $schema, file_get_contents("{$directory}/seed.sql"), true, 'ressources humaines');
        $hidden = $this->dataset($introspector, 'entreprise-tests', 'Entreprise (tests cachés)', 'Mêmes tables, autres données : ex aequo, hiérarchie plus profonde.', $schema, file_get_contents("{$directory}/seed-hidden.sql"), false, 'ressources humaines');

        $advanced = Level::where('position', 3)->firstOrFail();
        $expert = Level::where('position', 4)->firstOrFail();

        $this->advancedCourse($advanced, $company, $hidden);
        $this->expertCourse($expert, $company, $hidden);
        $this->optimizationCourse($expert, $company, $hidden);
        $this->expertCertification($expert, $company, $hidden);
    }

    private function advancedCourse(Level $level, Dataset $company, Dataset $hidden): void
    {
        $summary = 'Fonctions de fenêtrage et requêtes récursives : classer, cumuler, parcourir une hiérarchie.';

        $windows = $this->lesson($level, 'sql-avance', 'SQL avancé', 'fenetrage', 'Fonctions de fenêtrage', 'classer-et-cumuler', 'Classer et cumuler', $company, <<<'MD'
            # Classer et cumuler

            Une **fonction de fenêtrage** calcule une valeur pour chaque ligne à partir d'un ensemble de lignes
            voisines (la *fenêtre*), **sans regrouper** le résultat comme le ferait `GROUP BY`.

            ```sql runnable
            SELECT name, department_id, salary,
                   AVG(salary) OVER (PARTITION BY department_id) AS moyenne_departement
            FROM employees
            ORDER BY department_id, salary DESC;
            ```

            - `PARTITION BY` découpe les lignes en groupes indépendants ;
            - `ORDER BY` (dans `OVER`) ordonne la fenêtre : indispensable pour classer ou cumuler.

            ## Classer : ROW_NUMBER, RANK, DENSE_RANK

            ```sql runnable
            SELECT name, salary,
                   ROW_NUMBER() OVER (ORDER BY salary DESC) AS numero,
                   RANK()       OVER (ORDER BY salary DESC) AS rang,
                   DENSE_RANK() OVER (ORDER BY salary DESC) AS rang_dense
            FROM employees;
            ```

            Observez Thomas et Hugo (3 900 €) : `ROW_NUMBER` les départage arbitrairement, `RANK` leur donne le même
            rang puis **saute** le suivant, `DENSE_RANK` ne saute pas.

            ## Cumuler

            Avec un `ORDER BY` dans la fenêtre, `SUM` devient un **cumul** :

            ```sql runnable
            SELECT name, hired_at, salary,
                   SUM(salary) OVER (ORDER BY hired_at) AS masse_salariale
            FROM employees
            ORDER BY hired_at;
            ```

            MD, $summary);

        $recursive = $this->lesson($level, 'sql-avance', 'SQL avancé', 'recursivite', 'Requêtes récursives', 'parcourir-une-hierarchie', 'Parcourir une hiérarchie', $company, <<<'MD'
            # Parcourir une hiérarchie

            Chaque employé a un `manager_id` qui pointe vers un autre employé : c'est une **hiérarchie**.

            ```mermaid
            flowchart TD
                H[Hélène · PDG] --> M[Marc · DSI]
                H --> S[Sophie · Ventes]
                H --> J[Julien · RH]
                M --> I[Inès · Architecte]
                M --> Hu[Hugo · Admin sys]
                I --> T[Thomas]
                I --> L[Léa]
                L --> Z[Zoé]
            ```

            Une CTE **récursive** se compose d'une requête de départ (l'*ancre*) et d'une requête qui se réfère à la
            CTE elle-même, répétée tant qu'elle produit de nouvelles lignes :

            ```sql runnable
            WITH RECURSIVE chaine AS (
                SELECT id, name, manager_id, 0 AS profondeur
                FROM employees
                WHERE manager_id IS NULL                      -- ancre : le sommet
                UNION ALL
                SELECT e.id, e.name, e.manager_id, c.profondeur + 1
                FROM employees e
                JOIN chaine c ON e.manager_id = c.id          -- étape : les subordonnés
            )
            SELECT name, profondeur FROM chaine ORDER BY profondeur, name;
            ```

            Pensez toujours à la **condition d'arrêt** : ici, la récursion s'arrête quand plus personne n'a de
            subordonné. Une hiérarchie qui boucle (A encadre B qui encadre A) tournerait jusqu'au délai maximal.

            MD, $summary, chapterPosition: 1);

        $datasets = [$company, $hidden];

        $this->exercise($windows, $level, $datasets, ['window-functions'], [
            'slug' => 'meilleur-salaire-par-departement',
            'title' => 'Les mieux payés de chaque département',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Pour chaque département, affichez le ou les employés les **mieux payés** : nom du département (`departement`), nom de l'employé (`nom`) et salaire (`salaire`).\n\nEn cas d'égalité, **tous** les ex aequo doivent apparaître. L'ordre des lignes n'a pas d'importance.",
            'starter_sql' => "SELECT d.name AS departement, e.name AS nom, e.salary AS salaire\nFROM employees e\nJOIN departments d ON d.id = e.department_id\n",
            'solution_sql' => "WITH classement AS (\n    SELECT d.name AS departement, e.name AS nom, e.salary AS salaire,\n           RANK() OVER (PARTITION BY e.department_id ORDER BY e.salary DESC) AS rang\n    FROM employees e\n    JOIN departments d ON d.id = e.department_id\n)\nSELECT departement, nom, salaire FROM classement WHERE rang = 1;",
            'validation_strategy' => ValidationStrategy::ResultSet,
            'validation_options' => ['required_keywords' => ['OVER'], 'float_tolerance' => 0.01],
            'hints' => [
                ['text' => 'Classez les employés **dans chaque département** avec `OVER (PARTITION BY ... ORDER BY salary DESC)`.', 'xp_penalty' => 5],
                ['text' => '`ROW_NUMBER` ne garde qu\'un seul employé en cas d\'égalité : `RANK` donne le rang 1 à tous les ex aequo.', 'xp_penalty' => 10],
                ['text' => 'Une fonction de fenêtrage ne peut pas apparaître dans `WHERE` : calculez le rang dans une CTE, puis filtrez.', 'xp_penalty' => 10],
            ],
            'difficulty' => 3,
            'xp_reward' => 50,
            'position' => 1,
        ]);

        $this->exercise($windows, $level, $datasets, ['window-functions'], [
            'slug' => 'ecart-a-la-moyenne',
            'title' => 'Écart à la moyenne du département',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Pour chaque employé, affichez son nom (`nom`), son salaire (`salaire`), le salaire moyen de son département arrondi au centime (`moyenne_departement`) et l'écart entre les deux, arrondi au centime (`ecart` = salaire − moyenne).\n\nL'ordre des lignes n'a pas d'importance.",
            'starter_sql' => "SELECT name AS nom, salary AS salaire\nFROM employees;",
            'solution_sql' => "SELECT name AS nom, salary AS salaire,\n       ROUND(AVG(salary) OVER (PARTITION BY department_id), 2) AS moyenne_departement,\n       ROUND(salary - AVG(salary) OVER (PARTITION BY department_id), 2) AS ecart\nFROM employees;",
            'validation_strategy' => ValidationStrategy::ResultSet,
            'validation_options' => ['required_keywords' => ['OVER'], 'float_tolerance' => 0.01],
            'hints' => [
                ['text' => '`AVG(salary) OVER (PARTITION BY department_id)` donne la moyenne du département sur chaque ligne, sans regrouper.', 'xp_penalty' => 5],
            ],
            'difficulty' => 2,
            'xp_reward' => 40,
            'position' => 2,
        ]);

        $this->exercise($windows, $level, $datasets, ['window-functions', 'sorting'], [
            'slug' => 'masse-salariale-cumulee',
            'title' => 'Masse salariale cumulée',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Listez les employés **par date d'embauche** croissante avec leur nom (`nom`), leur date d'embauche (`embauche`) et la masse salariale mensuelle cumulée à cette date (`masse_cumulee` : somme des salaires de toutes les personnes embauchées jusque-là, elle comprise).",
            'starter_sql' => "SELECT name AS nom, hired_at AS embauche\nFROM employees\nORDER BY hired_at;",
            'solution_sql' => "SELECT name AS nom, hired_at AS embauche,\n       SUM(salary) OVER (ORDER BY hired_at) AS masse_cumulee\nFROM employees\nORDER BY hired_at;",
            'validation_strategy' => ValidationStrategy::OrderedResultSet,
            'validation_options' => ['required_keywords' => ['OVER'], 'float_tolerance' => 0.01],
            'hints' => [
                ['text' => 'Un `ORDER BY` à l\'intérieur de `OVER (...)` transforme `SUM` en cumul.', 'xp_penalty' => 5],
            ],
            'difficulty' => 2,
            'xp_reward' => 40,
            'position' => 3,
        ]);

        $this->exercise($recursive, $level, $datasets, ['cte'], [
            'slug' => 'equipe-du-dsi',
            'title' => 'Toute l\'équipe du DSI',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Listez **tous** les employés placés sous l'autorité du DSI (`job_title = 'DSI'`), directement ou indirectement : nom (`nom`) et niveau (`niveau` : 1 pour ses subordonnés directs, 2 pour les leurs, etc.).\n\nTriez par niveau, puis par nom.",
            'starter_sql' => "WITH RECURSIVE equipe AS (\n    -- ancre : les subordonnés directs du DSI\n\n    UNION ALL\n    -- étape : les subordonnés des membres de l'équipe\n\n)\nSELECT nom, niveau FROM equipe ORDER BY niveau, nom;",
            'solution_sql' => "WITH RECURSIVE equipe AS (\n    SELECT id, name AS nom, 1 AS niveau\n    FROM employees\n    WHERE manager_id = (SELECT id FROM employees WHERE job_title = 'DSI')\n    UNION ALL\n    SELECT e.id, e.name, eq.niveau + 1\n    FROM employees e\n    JOIN equipe eq ON e.manager_id = eq.id\n)\nSELECT nom, niveau FROM equipe ORDER BY niveau, nom;",
            'validation_strategy' => ValidationStrategy::OrderedResultSet,
            'validation_options' => ['required_keywords' => ['RECURSIVE']],
            'hints' => [
                ['text' => 'L\'ancre sélectionne les employés dont `manager_id` est l\'identifiant du DSI (sous-requête sur `job_title`).', 'xp_penalty' => 5],
                ['text' => 'L\'étape joint `employees` à la CTE : `JOIN equipe eq ON e.manager_id = eq.id`, avec `eq.niveau + 1`.', 'xp_penalty' => 10],
            ],
            'difficulty' => 4,
            'xp_reward' => 60,
            'position' => 1,
        ]);

        $mcq = $this->exercise($windows, $level, [], ['window-functions'], [
            'slug' => 'qcm-rank-dense-rank',
            'title' => 'QCM : RANK ou DENSE_RANK ?',
            'type' => ExerciseType::MultipleChoice,
            'statement' => 'Quatre salaires : 5 000, 4 000, 4 000 et 3 000. Quel rang `RANK() OVER (ORDER BY salaire DESC)` donne-t-il au salaire de **3 000** ?',
            'validation_strategy' => ValidationStrategy::Choices,
            'difficulty' => 2,
            'xp_reward' => 15,
            'position' => 4,
        ]);
        $mcq->choices()->delete();
        $mcq->choices()->createMany([
            ['body' => '3', 'is_correct' => false, 'explanation' => 'C\'est ce que donnerait DENSE_RANK, qui ne laisse pas de trou après les ex aequo.', 'position' => 1],
            ['body' => '4', 'is_correct' => true, 'explanation' => 'Les deux 4 000 partagent le rang 2 ; RANK saute alors le rang 3.', 'position' => 2],
            ['body' => '2', 'is_correct' => false, 'explanation' => 'Le rang 2 est celui des deux salaires à 4 000.', 'position' => 3],
            ['body' => 'Cela dépend du moteur', 'is_correct' => false, 'explanation' => 'RANK est normalisé (SQL:2003) : tous les moteurs donnent le même résultat.', 'position' => 4],
        ]);

        $this->certify($level, $datasets, $mcq, 'sql-avance', 'Certification SQL — Avancé', 'Fenêtrage et requêtes récursives : 3 questions tirées au sort, 25 minutes.', 25, 250, [
            [
                'slug' => 'cert-deuxieme-salaire',
                'title' => 'Le deuxième salaire de chaque département',
                'statement' => "Pour chaque département, affichez le ou les employés qui ont le **deuxième salaire le plus élevé** (en ignorant les doublons de salaire) : `departement`, `nom`, `salaire`. Un département où tout le monde gagne autant n'apparaît pas.",
                'solution_sql' => "WITH r AS (\n    SELECT department_id, name, salary,\n           DENSE_RANK() OVER (PARTITION BY department_id ORDER BY salary DESC) AS rang\n    FROM employees\n)\nSELECT d.name AS departement, r.name AS nom, r.salary AS salaire\nFROM r JOIN departments d ON d.id = r.department_id\nWHERE rang = 2;",
                'validation_strategy' => ValidationStrategy::ResultSet,
                'validation_options' => ['float_tolerance' => 0.01],
                'skills' => ['window-functions'],
            ],
            [
                'slug' => 'cert-rang-anciennete',
                'title' => 'Rang d\'ancienneté',
                'statement' => "Pour chaque employé, affichez son département (`departement`), son nom (`nom`) et son rang d'ancienneté **dans son département** (`rang` : 1 pour le plus ancien). L'ordre des lignes n'a pas d'importance.",
                'solution_sql' => "SELECT d.name AS departement, e.name AS nom,\n       ROW_NUMBER() OVER (PARTITION BY e.department_id ORDER BY e.hired_at) AS rang\nFROM employees e\nJOIN departments d ON d.id = e.department_id;",
                'validation_strategy' => ValidationStrategy::ResultSet,
                'skills' => ['window-functions'],
            ],
            [
                'slug' => 'cert-chaine-hierarchique',
                'title' => 'La chaîne hiérarchique de la dernière recrue',
                'statement' => "Listez les supérieurs hiérarchiques de l'employé embauché le **plus récemment** : nom (`nom`) et niveau (`niveau` : 1 pour son manager direct, 2 pour le manager de celui-ci, etc.), triés par niveau.",
                'solution_sql' => "WITH RECURSIVE chaine AS (\n    SELECT manager_id AS id, 1 AS niveau\n    FROM employees\n    WHERE hired_at = (SELECT MAX(hired_at) FROM employees)\n    UNION ALL\n    SELECT e.manager_id, c.niveau + 1\n    FROM employees e\n    JOIN chaine c ON e.id = c.id\n    WHERE e.manager_id IS NOT NULL\n)\nSELECT e.name AS nom, c.niveau\nFROM chaine c JOIN employees e ON e.id = c.id\nORDER BY c.niveau;",
                'validation_strategy' => ValidationStrategy::OrderedResultSet,
                'skills' => ['cte'],
            ],
        ]);
    }

    private function expertCourse(Level $level, Dataset $company, Dataset $hidden): void
    {
        $pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();
        $summary = 'Fonctions SQL et PL/pgSQL, procédures et triggers, exécutés dans un PostgreSQL isolé.';

        $functions = $this->lesson($level, 'programmation-postgresql', 'Programmation PostgreSQL', 'code-stocke', 'Code stocké', 'fonctions-et-procedures', 'Fonctions et procédures', $company, <<<'MD'
            # Fonctions et procédures

            PostgreSQL permet d'enregistrer du code **dans la base** : des fonctions, appelées dans une requête, et
            des procédures, appelées avec `CALL`. Dans le bac à sable, tout ce que vous créez est **annulé** après
            chaque exécution : chaque exemple crée donc sa fonction puis l'utilise.

            ## Une fonction en SQL

            ```sql runnable
            CREATE FUNCTION salaire_annuel_simple(mensuel numeric) RETURNS numeric
            LANGUAGE sql AS $$
                SELECT mensuel * 12
            $$;

            SELECT name, salary, salaire_annuel_simple(salary) AS annuel
            FROM employees ORDER BY id LIMIT 5;
            ```

            Le corps est écrit entre `$$ ... $$` : c'est une chaîne de caractères, sans avoir à doubler les apostrophes.

            ## Une fonction en PL/pgSQL

            PL/pgSQL ajoute des variables, des conditions et des boucles :

            ```sql runnable
            CREATE FUNCTION effectif(dept integer) RETURNS integer
            LANGUAGE plpgsql AS $$
            DECLARE
                n integer;
            BEGIN
                SELECT COUNT(*) INTO n FROM employees WHERE department_id = dept;
                IF n >= 5 THEN
                    RAISE NOTICE 'Le département % est un gros service', dept;
                END IF;
                RETURN n;
            END
            $$;

            SELECT name, effectif(id) AS effectif FROM departments ORDER BY id;
            ```

            ## Une procédure

            Une procédure ne renvoie pas de valeur : elle **agit** sur les données.

            ```sql runnable
            CREATE PROCEDURE embaucher(p_nom text, p_dept integer, p_salaire numeric)
            LANGUAGE sql AS $$
                INSERT INTO employees (id, name, department_id, job_title, salary, hired_at)
                SELECT MAX(id) + 1, p_nom, p_dept, 'Stagiaire', p_salaire, CURRENT_DATE FROM employees
            $$;

            CALL embaucher('Nina Petit', 2, 1800);

            SELECT id, name, job_title, salary FROM employees ORDER BY id DESC LIMIT 3;
            ```

            > **Règles du bac à sable** : langages `sql` et `plpgsql` uniquement, pas de SQL dynamique (`EXECUTE`),
            > pas de `SET` ni de contrôle de transaction dans les fonctions, et un temps d'exécution limité.

            MD, $summary);

        $triggers = $this->lesson($level, 'programmation-postgresql', 'Programmation PostgreSQL', 'code-stocke', 'Code stocké', 'triggers', 'Les triggers', $company, <<<'MD'
            # Les triggers

            Un **trigger** (déclencheur) exécute une fonction automatiquement lors d'un `INSERT`, `UPDATE` ou `DELETE`.

            ```mermaid
            flowchart LR
                A[UPDATE employees] --> B{BEFORE<br/>FOR EACH ROW}
                B -->|peut modifier NEW<br/>ou annuler la ligne| C[(Écriture)]
                C --> D{AFTER<br/>FOR EACH ROW}
                D -->|journalisation,<br/>tables liées| E[Fin de l'instruction]
            ```

            Dans la fonction, `OLD` contient la ligne avant modification et `NEW` la ligne après.
            Un trigger `BEFORE` peut **modifier** `NEW` avant l'écriture :

            ```sql runnable
            CREATE FUNCTION arrondir_salaire() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                NEW.salary := ROUND(NEW.salary, -1);
                RETURN NEW;
            END
            $$;

            CREATE TRIGGER trg_arrondi_salaire
            BEFORE INSERT OR UPDATE OF salary ON employees
            FOR EACH ROW EXECUTE FUNCTION arrondir_salaire();

            UPDATE employees SET salary = 3456.78 WHERE id = 6;
            SELECT id, name, salary FROM employees WHERE id = 6;
            ```

            Un trigger `AFTER` voit la ligne telle qu'elle a été écrite : c'est le bon moment pour **journaliser**.
            La clause `WHEN (...)` évite d'appeler la fonction quand ce n'est pas utile.

            MD, $summary, lessonPosition: 1);

        Course::where('slug', 'programmation-postgresql')->update(['sql_dialect_id' => $pgsql->id]);

        $datasets = [$company, $hidden];
        $postgresOnly = ['sql_dialect_id' => $pgsql->id];

        $this->exercise($functions, $level, $datasets, ['stored-procedures'], [
            ...$postgresOnly,
            'slug' => 'fonction-salaire-annuel',
            'title' => 'Fonction : salaire annuel',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Écrivez la fonction `salaire_annuel(emp_id integer) RETURNS numeric` qui renvoie le salaire annuel de l'employé (12 × salaire mensuel), majoré de **10 %** pour les managers (les employés qui encadrent au moins une personne).\n\nPour un identifiant inconnu, la fonction renvoie `NULL`.",
            'starter_sql' => "CREATE FUNCTION salaire_annuel(emp_id integer) RETURNS numeric\nLANGUAGE sql AS $$\n    SELECT salary * 12\n    FROM employees\n    WHERE id = emp_id\n$$;",
            'solution_sql' => "CREATE FUNCTION salaire_annuel(emp_id integer) RETURNS numeric\nLANGUAGE sql AS $$\n    SELECT salary * 12 * CASE WHEN EXISTS (SELECT 1 FROM employees s WHERE s.manager_id = e.id) THEN 1.10 ELSE 1 END\n    FROM employees e\n    WHERE e.id = emp_id\n$$;",
            'validation_strategy' => ValidationStrategy::StateCheck,
            'validation_options' => [
                'allowed_statements' => ['routine'],
                'max_statements' => 1,
                'float_tolerance' => 0.01,
                'check_queries' => [
                    'salaire_annuel(id)' => 'SELECT id, salaire_annuel(id) AS annuel FROM employees ORDER BY id',
                    'salaire_annuel(-1)' => 'SELECT salaire_annuel(-1) AS annuel',
                ],
            ],
            'hints' => [
                ['text' => 'Un manager est un employé dont l\'identifiant apparaît dans le `manager_id` d\'un autre : `EXISTS (SELECT 1 FROM employees s WHERE s.manager_id = e.id)`.', 'xp_penalty' => 5],
                ['text' => 'Multipliez par `CASE WHEN ... THEN 1.10 ELSE 1 END`.', 'xp_penalty' => 10],
            ],
            'difficulty' => 3,
            'xp_reward' => 60,
            'position' => 1,
        ]);

        $this->exercise($functions, $level, $datasets, ['stored-procedures'], [
            ...$postgresOnly,
            'slug' => 'fonction-tranche-salariale',
            'title' => 'PL/pgSQL : tranches salariales',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Écrivez en **PL/pgSQL** la fonction `tranche_salaire(montant numeric) RETURNS text` qui renvoie :\n\n- `'A'` en dessous de 3 000 ;\n- `'B'` de 3 000 à moins de 5 000 ;\n- `'C'` de 5 000 à moins de 7 000 ;\n- `'D'` à partir de 7 000 ;\n- `NULL` si le montant est `NULL`.",
            'starter_sql' => "CREATE FUNCTION tranche_salaire(montant numeric) RETURNS text\nLANGUAGE plpgsql AS $$\nBEGIN\n    \nEND\n$$;",
            'solution_sql' => "CREATE FUNCTION tranche_salaire(montant numeric) RETURNS text\nLANGUAGE plpgsql AS $$\nBEGIN\n    IF montant IS NULL THEN\n        RETURN NULL;\n    ELSIF montant < 3000 THEN\n        RETURN 'A';\n    ELSIF montant < 5000 THEN\n        RETURN 'B';\n    ELSIF montant < 7000 THEN\n        RETURN 'C';\n    END IF;\n    RETURN 'D';\nEND\n$$;",
            'validation_strategy' => ValidationStrategy::StateCheck,
            'validation_options' => [
                'allowed_statements' => ['routine'],
                'max_statements' => 1,
                'required_keywords' => ['PLPGSQL', 'IF'],
                'check_queries' => [
                    'employés' => 'SELECT id, tranche_salaire(salary) AS tranche FROM employees ORDER BY id',
                    'bornes' => 'SELECT tranche_salaire(2999.99) AS a, tranche_salaire(3000) AS b, tranche_salaire(4999.99) AS c, tranche_salaire(5000) AS d, tranche_salaire(7000) AS e, tranche_salaire(NULL) AS f',
                ],
            ],
            'hints' => [
                ['text' => 'Enchaînez `IF ... THEN ... ELSIF ... THEN ... END IF;` et terminez chaque branche par `RETURN`.', 'xp_penalty' => 5],
                ['text' => 'Attention aux bornes : 3 000 est en tranche B, 5 000 en C, 7 000 en D.', 'xp_penalty' => 5],
            ],
            'difficulty' => 3,
            'xp_reward' => 60,
            'position' => 2,
        ]);

        $this->exercise($functions, $level, $datasets, ['stored-procedures', 'dml'], [
            ...$postgresOnly,
            'slug' => 'procedure-augmentation',
            'title' => 'Procédure : augmenter un département',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Écrivez la procédure `augmenter_departement(dept_id integer, pourcentage numeric)` qui augmente de `pourcentage` % le salaire de tous les employés du département, arrondi au centime.\n\nElle sera testée avec `CALL augmenter_departement(2, 5)`.",
            'starter_sql' => "CREATE PROCEDURE augmenter_departement(dept_id integer, pourcentage numeric)\nLANGUAGE plpgsql AS $$\nBEGIN\n    \nEND\n$$;",
            'solution_sql' => "CREATE PROCEDURE augmenter_departement(dept_id integer, pourcentage numeric)\nLANGUAGE plpgsql AS $$\nBEGIN\n    UPDATE employees\n    SET salary = ROUND(salary * (1 + pourcentage / 100), 2)\n    WHERE department_id = dept_id;\nEND\n$$;",
            'validation_strategy' => ValidationStrategy::StateCheck,
            'validation_options' => [
                'allowed_statements' => ['routine'],
                'max_statements' => 1,
                'float_tolerance' => 0.01,
                'check_queries' => [
                    'appel' => 'CALL augmenter_departement(2, 5)',
                    'employees' => 'SELECT id, salary FROM employees ORDER BY id',
                ],
            ],
            'hints' => [
                ['text' => 'Le corps contient un simple `UPDATE ... SET salary = ... WHERE department_id = dept_id;`.', 'xp_penalty' => 5],
                ['text' => 'Pour 5 %, multipliez par `1 + pourcentage / 100` puis arrondissez avec `ROUND(..., 2)`.', 'xp_penalty' => 5],
            ],
            'difficulty' => 3,
            'xp_reward' => 60,
            'position' => 3,
        ]);

        $this->exercise($functions, $level, $datasets, ['stored-procedures'], [
            ...$postgresOnly,
            'slug' => 'bug-nombre-de-subordonnes',
            'title' => 'Chasse au bug : le nombre de subordonnés',
            'type' => ExerciseType::BugFix,
            'statement' => 'La fonction `nb_subordonnes(emp_id)` doit renvoyer le nombre de subordonnés **directs** d\'un employé, et **0** s\'il n\'encadre personne... mais elle renvoie `NULL` pour la plupart des employés. Corrigez-la.',
            'starter_sql' => "CREATE FUNCTION nb_subordonnes(emp_id integer) RETURNS integer\nLANGUAGE plpgsql AS $$\nDECLARE\n    n integer;\nBEGIN\n    SELECT COUNT(*) INTO n\n    FROM employees\n    WHERE manager_id = emp_id\n    GROUP BY manager_id;\n    RETURN n;\nEND\n$$;",
            'solution_sql' => "CREATE FUNCTION nb_subordonnes(emp_id integer) RETURNS integer\nLANGUAGE plpgsql AS $$\nDECLARE\n    n integer;\nBEGIN\n    SELECT COUNT(*) INTO n\n    FROM employees\n    WHERE manager_id = emp_id;\n    RETURN n;\nEND\n$$;",
            'validation_strategy' => ValidationStrategy::StateCheck,
            'validation_options' => [
                'allowed_statements' => ['routine'],
                'max_statements' => 1,
                'check_queries' => [
                    'nb_subordonnes(id)' => 'SELECT id, nb_subordonnes(id) AS nb FROM employees ORDER BY id',
                ],
            ],
            'hints' => [
                ['text' => 'Que renvoie une requête avec `GROUP BY` quand aucune ligne ne correspond au `WHERE` ?', 'xp_penalty' => 5],
                ['text' => 'Sans ligne, `SELECT ... INTO n` laisse `n` à `NULL`. Sans `GROUP BY`, `COUNT(*)` renvoie toujours une ligne (0).', 'xp_penalty' => 10],
            ],
            'difficulty' => 2,
            'xp_reward' => 50,
            'position' => 4,
        ]);

        $this->exercise($triggers, $level, $datasets, ['triggers'], [
            ...$postgresOnly,
            'slug' => 'trigger-audit-salaires',
            'title' => 'Trigger : journaliser les salaires',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Chaque changement de salaire doit être enregistré dans `salary_audit (employee_id, old_salary, new_salary)`.\n\nÉcrivez la fonction de trigger et le trigger sur `employees`. Seuls les **vrais changements** de salaire sont journalisés : un `UPDATE` qui laisse le salaire identique, ou qui ne modifie que d'autres colonnes, n'ajoute rien.",
            'starter_sql' => "CREATE FUNCTION journaliser_salaire() RETURNS trigger\nLANGUAGE plpgsql AS $$\nBEGIN\n    \n    RETURN NEW;\nEND\n$$;\n\nCREATE TRIGGER trg_audit_salaire\n",
            'solution_sql' => "CREATE FUNCTION journaliser_salaire() RETURNS trigger\nLANGUAGE plpgsql AS $$\nBEGIN\n    INSERT INTO salary_audit (employee_id, old_salary, new_salary)\n    VALUES (OLD.id, OLD.salary, NEW.salary);\n    RETURN NEW;\nEND\n$$;\n\nCREATE TRIGGER trg_audit_salaire\nAFTER UPDATE OF salary ON employees\nFOR EACH ROW\nWHEN (OLD.salary IS DISTINCT FROM NEW.salary)\nEXECUTE FUNCTION journaliser_salaire();",
            'validation_strategy' => ValidationStrategy::StateCheck,
            'validation_options' => [
                'allowed_statements' => ['routine', 'ddl'],
                'max_statements' => 3,
                'check_queries' => [
                    'augmentation' => 'WITH maj AS (UPDATE employees SET salary = salary + 100 WHERE department_id = 3 RETURNING id) SELECT COUNT(*) AS nb FROM maj',
                    'salaire inchangé' => 'WITH maj AS (UPDATE employees SET salary = salary WHERE department_id = 4 RETURNING id) SELECT COUNT(*) AS nb FROM maj',
                    'autre colonne' => "WITH maj AS (UPDATE employees SET job_title = job_title || ' (senior)' WHERE id = 6 RETURNING id) SELECT COUNT(*) AS nb FROM maj",
                    'salary_audit' => 'SELECT employee_id, old_salary, new_salary FROM salary_audit ORDER BY employee_id',
                ],
            ],
            'hints' => [
                ['text' => 'La fonction insère `(OLD.id, OLD.salary, NEW.salary)` dans `salary_audit`.', 'xp_penalty' => 5],
                ['text' => '`AFTER UPDATE OF salary ON employees FOR EACH ROW EXECUTE FUNCTION journaliser_salaire()`.', 'xp_penalty' => 10],
                ['text' => 'Pour ignorer les salaires inchangés : `WHEN (OLD.salary IS DISTINCT FROM NEW.salary)`, ou un `IF` dans la fonction.', 'xp_penalty' => 10],
            ],
            'difficulty' => 4,
            'xp_reward' => 80,
            'position' => 1,
        ]);

        $mcq = $this->exercise($triggers, $level, [], ['triggers'], [
            'slug' => 'qcm-before-ou-after',
            'title' => 'QCM : BEFORE ou AFTER ?',
            'type' => ExerciseType::MultipleChoice,
            'statement' => 'Vous voulez qu\'un e-mail soit **toujours enregistré en minuscules**, quelle que soit la façon dont il a été saisi. Quel trigger choisir ?',
            'validation_strategy' => ValidationStrategy::Choices,
            'difficulty' => 2,
            'xp_reward' => 15,
            'position' => 2,
        ]);
        $mcq->choices()->delete();
        $mcq->choices()->createMany([
            ['body' => 'BEFORE INSERT OR UPDATE ... FOR EACH ROW, qui modifie NEW', 'is_correct' => true, 'explanation' => 'Seul un trigger BEFORE de niveau ligne peut modifier NEW avant l\'écriture.', 'position' => 1],
            ['body' => 'AFTER INSERT OR UPDATE ... FOR EACH ROW, qui modifie NEW', 'is_correct' => false, 'explanation' => 'Dans un trigger AFTER, la ligne est déjà écrite : modifier NEW n\'a aucun effet.', 'position' => 2],
            ['body' => 'AFTER INSERT OR UPDATE ... FOR EACH STATEMENT', 'is_correct' => false, 'explanation' => 'Un trigger d\'instruction n\'a ni OLD ni NEW : il ne voit pas les lignes.', 'position' => 3],
            ['body' => 'Aucun : seule une contrainte CHECK le permet', 'is_correct' => false, 'explanation' => 'Une contrainte CHECK refuserait les majuscules, elle ne les convertirait pas.', 'position' => 4],
        ]);
    }

    private function optimizationCourse(Level $level, Dataset $company, Dataset $hidden): void
    {
        $pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();

        $lesson = $this->lesson($level, 'optimisation-des-requetes', 'Optimisation des requêtes', 'index', 'Index et plans d\'exécution', 'index-et-plans', 'Index et plans d\'exécution', $company, <<<'MD'
            # Index et plans d'exécution

            Avant d'exécuter une requête, le moteur choisit un **plan** : dans quel ordre lire les tables, et
            comment. `EXPLAIN` affiche ce plan sans exécuter la requête :

            ```sql runnable
            EXPLAIN SELECT name FROM employees WHERE manager_id = 5;
            ```

            `Seq Scan` signifie que **toute la table** est lue. Sur 13 lignes, c'est le meilleur choix ; sur
            des millions, c'est catastrophique.

            ## Mesurer avec EXPLAIN ANALYZE

            `EXPLAIN ANALYZE` exécute vraiment la requête et affiche les temps mesurés. Créons une table de
            200 000 mesures, puis cherchons celles d'un capteur :

            ```sql runnable
            CREATE TABLE mesures AS
            SELECT g AS id, g % 1000 AS capteur, round((random() * 100)::numeric, 2) AS valeur
            FROM generate_series(1, 200000) AS g;

            EXPLAIN ANALYZE SELECT * FROM mesures WHERE capteur = 42;
            ```

            Ajoutons un **index** sur la colonne filtrée, et comparez le temps d'exécution :

            ```sql runnable
            CREATE TABLE mesures AS
            SELECT g AS id, g % 1000 AS capteur, round((random() * 100)::numeric, 2) AS valeur
            FROM generate_series(1, 200000) AS g;

            CREATE INDEX idx_mesures_capteur ON mesures (capteur);

            EXPLAIN ANALYZE SELECT * FROM mesures WHERE capteur = 42;
            ```

            Le moteur passe par l'index (`Bitmap Index Scan` / `Index Scan`) : il ne lit plus que les 200 lignes utiles.

            ## Une condition « indexable »

            Un index sur `hired_at` existe déjà. Il n'est utilisable que si la condition porte sur la colonne
            **telle quelle** :

            | Condition | Index utilisable ? |
            |---|---|
            | `hired_at >= '2021-01-01' AND hired_at < '2022-01-01'` | oui (plage) |
            | `CAST(hired_at AS VARCHAR(10)) LIKE '2021%'` | non : la colonne est transformée |
            | `EXTRACT(YEAR FROM hired_at) = 2021` | non, sauf index sur l'expression |

            ## Index composites

            Un index sur `(department_id, job_title)` sert aux recherches sur `department_id`, ou sur
            `department_id` **et** `job_title` ; pas, en général, à une recherche sur `job_title` seul :
            l'ordre des colonnes compte.

            > Dans les exercices, le correcteur lit le plan d'exécution. Sur ces petites tables, il interdit à
            > PostgreSQL le parcours séquentiel (`enable_seqscan = off`) pour révéler s'il **existe** un index utilisable.

            MD, 'Index, plans d\'exécution et conditions indexables : lire EXPLAIN et faire les bons choix.');

        Course::where('slug', 'optimisation-des-requetes')->update(['sql_dialect_id' => $pgsql->id]);

        $datasets = [$company, $hidden];

        $this->exercise($lesson, $level, $datasets, ['indexing'], [
            'slug' => 'index-subordonnes',
            'title' => 'Index : retrouver les subordonnés',
            'type' => ExerciseType::QueryWrite,
            'statement' => "L'application affiche très souvent l'équipe d'un manager :\n\n```sql\nSELECT name FROM employees WHERE manager_id = 5;\n```\n\nCréez l'index qui permet au moteur de trouver ces lignes **sans parcourir toute la table**.",
            'starter_sql' => 'CREATE INDEX ',
            'solution_sql' => 'CREATE INDEX idx_employees_manager ON employees (manager_id);',
            'validation_strategy' => ValidationStrategy::QueryPlan,
            'validation_options' => [
                'allowed_statements' => ['ddl'],
                'max_statements' => 2,
                'forbidden_keywords' => ['DROP', 'ALTER'],
                'plan_query' => 'SELECT name FROM employees WHERE manager_id = 5',
                'index_tables' => ['employees'],
            ],
            'hints' => [
                ['text' => 'La syntaxe : `CREATE INDEX nom_index ON table (colonne);`', 'xp_penalty' => 5],
                ['text' => 'Indexez la colonne qui apparaît dans le `WHERE` : `manager_id`.', 'xp_penalty' => 5],
            ],
            'difficulty' => 2,
            'xp_reward' => 40,
            'position' => 1,
        ]);

        $this->exercise($lesson, $level, $datasets, ['indexing', 'query-tuning'], [
            'slug' => 'bug-condition-non-indexable',
            'title' => 'Chasse au bug : l\'index ignoré',
            'type' => ExerciseType::BugFix,
            'statement' => "Cette requête liste les embauches de **2021**. Elle donne le bon résultat, mais n'utilise pas l'index `idx_employees_hired_at` : sur une grosse table, elle lit toutes les lignes.\n\nRéécrivez la condition pour que l'index soit utilisable, sans changer le résultat.",
            'starter_sql' => "SELECT name, hired_at\nFROM employees\nWHERE CAST(hired_at AS VARCHAR(10)) LIKE '2021%';",
            'solution_sql' => "SELECT name, hired_at\nFROM employees\nWHERE hired_at >= '2021-01-01' AND hired_at < '2022-01-01';",
            'validation_strategy' => ValidationStrategy::QueryPlan,
            'validation_options' => [
                'index_tables' => ['employees'],
            ],
            'hints' => [
                ['text' => 'Une fonction ou une conversion appliquée à la colonne empêche d\'utiliser son index.', 'xp_penalty' => 5],
                ['text' => 'Exprimez « en 2021 » comme une plage : `hired_at >= \'2021-01-01\' AND hired_at < \'2022-01-01\'`.', 'xp_penalty' => 10],
            ],
            'difficulty' => 3,
            'xp_reward' => 50,
            'position' => 2,
        ]);

        $this->exercise($lesson, $level, $datasets, ['indexing', 'joins'], [
            'slug' => 'index-jointure-par-ville',
            'title' => 'Index : une jointure sans parcours complet',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Créez les index nécessaires pour que cette requête n'ait à parcourir **aucune** des deux tables en entier :\n\n```sql\nSELECT d.name, e.name\nFROM departments d\nJOIN employees e ON e.department_id = d.id\nWHERE d.city = 'Paris';\n```",
            'starter_sql' => "CREATE INDEX \n",
            'solution_sql' => "CREATE INDEX idx_departments_city ON departments (city);\nCREATE INDEX idx_employees_department ON employees (department_id);",
            'validation_strategy' => ValidationStrategy::QueryPlan,
            'validation_options' => [
                'allowed_statements' => ['ddl'],
                'max_statements' => 4,
                'forbidden_keywords' => ['DROP', 'ALTER'],
                'plan_query' => "SELECT d.name, e.name FROM departments d JOIN employees e ON e.department_id = d.id WHERE d.city = 'Paris'",
                'index_tables' => ['departments', 'employees'],
            ],
            'hints' => [
                ['text' => 'Deux accès à rendre indexables : le filtre `d.city = \'Paris\'` et la jointure `e.department_id = d.id`.', 'xp_penalty' => 5],
                ['text' => '`departments.id` est déjà indexé (clé primaire), mais pas `employees.department_id`.', 'xp_penalty' => 10],
            ],
            'difficulty' => 3,
            'xp_reward' => 50,
            'position' => 3,
        ]);

        $mcq = $this->exercise($lesson, $level, [], ['indexing'], [
            'slug' => 'qcm-index-composite',
            'title' => 'QCM : l\'ordre des colonnes d\'un index',
            'type' => ExerciseType::MultipleChoice,
            'statement' => 'La table `employees` possède un index sur `(department_id, job_title)`. Quelle requête peut **chercher** dans cet index (et pas seulement le parcourir) ?',
            'validation_strategy' => ValidationStrategy::Choices,
            'difficulty' => 2,
            'xp_reward' => 15,
            'position' => 4,
        ]);
        $mcq->choices()->delete();
        $mcq->choices()->createMany([
            ['body' => 'WHERE department_id = 2', 'is_correct' => true, 'explanation' => 'La première colonne de l\'index suffit à cibler une plage de l\'index.', 'position' => 1],
            ['body' => "WHERE job_title = 'DSI'", 'is_correct' => false, 'explanation' => 'Sans la première colonne, les entrées recherchées sont dispersées dans tout l\'index.', 'position' => 2],
            ['body' => "WHERE UPPER(job_title) = 'DSI' AND department_id + 0 = 2", 'is_correct' => false, 'explanation' => 'Les deux colonnes sont transformées : aucune n\'est utilisable telle quelle.', 'position' => 3],
            ['body' => "WHERE department_id = 2 OR job_title = 'DSI'", 'is_correct' => false, 'explanation' => 'Le OR porte aussi sur job_title seul : ces lignes peuvent être n\'importe où dans l\'index.', 'position' => 4],
        ]);
    }

    /**
     * Certification SQL — Expert : code stocké PostgreSQL et indexation.
     */
    private function expertCertification(Level $level, Dataset $company, Dataset $hidden): void
    {
        $pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();
        $mcq = Exercise::where('slug', 'qcm-before-ou-after')->firstOrFail();

        $this->certify($level, [$company, $hidden], $mcq, 'sql-expert', 'Certification SQL — Expert', 'Fonctions, triggers et indexation sur PostgreSQL : 3 questions tirées au sort, 40 minutes.', 40, 400, [
            [
                'slug' => 'cert-fonction-masse-salariale',
                'title' => 'Fonction : masse salariale d\'un département',
                'sql_dialect_id' => $pgsql->id,
                'statement' => 'Écrivez la fonction `masse_salariale(dept_id integer) RETURNS numeric` qui renvoie la somme des salaires mensuels du département, et **0** (pas `NULL`) pour un département sans employé ou inconnu.',
                'starter_sql' => "CREATE FUNCTION masse_salariale(dept_id integer) RETURNS numeric\nLANGUAGE sql AS $$\n    \n$$;",
                'solution_sql' => "CREATE FUNCTION masse_salariale(dept_id integer) RETURNS numeric\nLANGUAGE sql AS $$\n    SELECT COALESCE(SUM(salary), 0) FROM employees WHERE department_id = dept_id\n$$;",
                'validation_strategy' => ValidationStrategy::StateCheck,
                'validation_options' => [
                    'allowed_statements' => ['routine'],
                    'max_statements' => 1,
                    'float_tolerance' => 0.01,
                    'check_queries' => [
                        'masse_salariale(id)' => 'SELECT id, masse_salariale(id) AS masse FROM departments ORDER BY id',
                        'masse_salariale(-1)' => 'SELECT masse_salariale(-1) AS masse',
                    ],
                ],
                'skills' => ['stored-procedures'],
            ],
            [
                'slug' => 'cert-trigger-salaire-plancher',
                'title' => 'Trigger : un salaire ne baisse jamais',
                'sql_dialect_id' => $pgsql->id,
                'statement' => "Écrivez un trigger sur `employees` qui empêche toute **baisse** de salaire : si une mise à jour propose un salaire inférieur à l'ancien, l'ancien salaire est conservé (sans erreur). Les hausses s'appliquent normalement.",
                'solution_sql' => "CREATE FUNCTION salaire_plancher() RETURNS trigger\nLANGUAGE plpgsql AS $$\nBEGIN\n    IF NEW.salary < OLD.salary THEN\n        NEW.salary := OLD.salary;\n    END IF;\n    RETURN NEW;\nEND\n$$;\n\nCREATE TRIGGER trg_salaire_plancher\nBEFORE UPDATE OF salary ON employees\nFOR EACH ROW EXECUTE FUNCTION salaire_plancher();",
                'validation_strategy' => ValidationStrategy::StateCheck,
                'validation_options' => [
                    'allowed_statements' => ['routine', 'ddl'],
                    'max_statements' => 3,
                    'check_queries' => [
                        'baisse' => 'WITH maj AS (UPDATE employees SET salary = salary - 500 WHERE department_id = 2 RETURNING id, salary) SELECT id, salary FROM maj ORDER BY id',
                        'hausse' => 'WITH maj AS (UPDATE employees SET salary = salary + 50 WHERE department_id = 3 RETURNING id, salary) SELECT id, salary FROM maj ORDER BY id',
                        'employees' => 'SELECT id, salary FROM employees ORDER BY id',
                    ],
                ],
                'skills' => ['triggers'],
            ],
            [
                'slug' => 'cert-index-intitule',
                'title' => 'Index : recherche par intitulé de poste',
                'statement' => "Créez l'index qui permet d'exécuter cette requête sans parcourir toute la table :\n\n```sql\nSELECT name, salary FROM employees WHERE job_title = 'Développeur';\n```",
                'solution_sql' => 'CREATE INDEX idx_employees_job_title ON employees (job_title);',
                'validation_strategy' => ValidationStrategy::QueryPlan,
                'validation_options' => [
                    'allowed_statements' => ['ddl'],
                    'max_statements' => 2,
                    'forbidden_keywords' => ['DROP', 'ALTER'],
                    'plan_query' => "SELECT name, salary FROM employees WHERE job_title = 'Développeur'",
                    'index_tables' => ['employees'],
                ],
                'skills' => ['indexing'],
            ],
        ]);
    }

    /**
     * Certification : exercices réservés (sans leçon) + un QCM d'entraînement.
     *
     * @param  list<Dataset>  $datasets
     * @param  list<array<string, mixed>>  $reserved
     */
    private function certify(Level $level, array $datasets, Exercise $mcq, string $slug, string $title, string $description, int $minutes, int $xp, array $reserved): void
    {
        $exercises = collect($reserved)->map(function (array $attributes) use ($level, $datasets) {
            $skills = $attributes['skills'];
            unset($attributes['skills']);

            $exercise = Exercise::updateOrCreate(['slug' => $attributes['slug']], [
                'validation_options' => null,
                ...$attributes,
                'lesson_id' => null,
                'level_id' => $level->id,
                'type' => ExerciseType::QueryWrite,
                'difficulty' => 3,
                'xp_reward' => 0,
                'status' => ContentStatus::Published,
                'published_at' => now(),
            ]);

            $exercise->datasets()->sync(collect($datasets)->mapWithKeys(fn (Dataset $dataset, int $index) => [
                $dataset->id => ['role' => $index === 0 ? DatasetRole::Primary->value : DatasetRole::HiddenTest->value, 'position' => $index],
            ])->all());
            $exercise->skills()->sync(Skill::whereIn('slug', $skills)->pluck('id'));

            return $exercise;
        });

        $certification = Certification::updateOrCreate(['slug' => $slug], [
            'level_id' => $level->id,
            'title' => $title,
            'description' => $description,
            'passing_score' => 70,
            'duration_minutes' => $minutes,
            'exercises_count' => 3,
            'max_attempts' => 3,
            'cooldown_hours' => 24,
            'xp_reward' => $xp,
            'status' => ContentStatus::Published,
        ]);

        $certification->exercisePool()->sync([...$exercises->pluck('id'), $mcq->id]);
    }
}
