<?php

namespace Database\Seeders;

use App\Enums\ChallengeType;
use App\Enums\ContentStatus;
use App\Enums\DatasetFormat;
use App\Enums\DatasetRole;
use App\Enums\DatasetStatus;
use App\Enums\ExerciseType;
use App\Enums\ValidationStrategy;
use App\Models\Certification;
use App\Models\Challenge;
use App\Models\Course;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Skill;
use App\Models\SqlDialect;
use App\Services\Datasets\SchemaIntrospector;
use Illuminate\Database\Seeder;

/**
 * Contenu de démonstration : jeu « Boutique » (+ jeu de test caché),
 * deux cours et cinq exercices couvrant chaque type et stratégie de validation.
 */
class DemoContentSeeder extends Seeder
{
    public function run(SchemaIntrospector $introspector): void
    {
        $directory = database_path('datasets/boutique');
        $schema = file_get_contents("{$directory}/schema.sql");

        $boutique = $this->dataset($introspector, 'boutique', 'Boutique en ligne', 'Clients, produits, commandes et lignes de commande d\'une petite boutique.', $schema, file_get_contents("{$directory}/seed.sql"), true);
        $hidden = $this->dataset($introspector, 'boutique-tests', 'Boutique en ligne (tests cachés)', 'Mêmes tables, autres données : sert à valider les exercices sans dévoiler les données.', $schema, file_get_contents("{$directory}/seed-hidden.sql"), false);

        $beginner = Level::where('position', 1)->firstOrFail();
        $intermediate = Level::where('position', 2)->firstOrFail();

        $basics = $this->lesson($beginner, 'fondamentaux-du-sql', 'Fondamentaux du SQL', 'interroger-une-table', 'Interroger une table', 'filtrer-et-trier', 'Filtrer et trier', $boutique, <<<'MD'
            # Filtrer et trier

            `WHERE` ne garde que les lignes qui respectent une condition, `ORDER BY` les trie.
            Modifiez la requête ci-dessous puis exécutez-la (`Ctrl` + `Entrée`) :

            ```sql runnable
            SELECT name, price
            FROM products
            WHERE category = 'Livres'
            ORDER BY price DESC;
            ```

            Sans `ORDER BY`, **l'ordre des lignes n'est jamais garanti**, quel que soit le moteur.

            Les conditions se combinent avec `AND` / `OR`, et `IN` teste une liste de valeurs :

            ```sql runnable
            SELECT name, city
            FROM customers
            WHERE city IN ('Lyon', 'Paris') AND created_at >= '2024-02-01'
            ORDER BY city, name;
            ```

            MD);

        $joins = $this->lesson($intermediate, 'requetes-intermediaires', 'Requêtes intermédiaires', 'jointures-et-agregats', 'Jointures et agrégats', 'grouper-et-filtrer-les-groupes', 'Grouper et filtrer les groupes', $boutique, <<<'MD'
            # Grouper et filtrer les groupes

            Les commandes, leurs lignes et les produits sont reliés par des clés étrangères :

            ```mermaid
            erDiagram
                customers ||--o{ orders : passe
                orders ||--o{ order_items : contient
                products ||--o{ order_items : figure
            ```

            `GROUP BY` regroupe les lignes, les fonctions d'agrégat (`COUNT`, `SUM`, `AVG`...) les résument.
            Pour filtrer **les groupes**, on utilise `HAVING` (évalué après le regroupement), et non `WHERE`
            (évalué avant) :

            ```sql runnable
            SELECT category, COUNT(*) AS nb, AVG(price) AS prix_moyen
            FROM products
            GROUP BY category
            HAVING AVG(price) > 30;
            ```

            MD);

        $this->exercise($basics, $beginner, [$boutique, $hidden], ['select', 'sorting'], [
            'slug' => 'clients-de-lyon',
            'title' => 'Les clients lyonnais',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Affichez le **nom** et l'**email** des clients qui habitent à **Lyon**, triés par nom (ordre alphabétique).",
            'starter_sql' => "SELECT \nFROM customers\n",
            'solution_sql' => "SELECT name, email\nFROM customers\nWHERE city = 'Lyon'\nORDER BY name;",
            'validation_strategy' => ValidationStrategy::OrderedResultSet,
            'hints' => [
                ['text' => 'La ville est stockée dans la colonne `city`.', 'xp_penalty' => 2],
                ['text' => "Filtrez avec `WHERE city = 'Lyon'`, puis triez avec `ORDER BY name`.", 'xp_penalty' => 5],
            ],
            'difficulty' => 1,
            'xp_reward' => 20,
        ]);

        $this->exercise($joins, $intermediate, [$boutique, $hidden], ['joins', 'aggregation'], [
            'slug' => 'chiffre-affaires-par-client',
            'title' => 'Chiffre d\'affaires par client',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Pour chaque client ayant au moins une commande **livrée**, affichez son nom (`name`) et le montant total de ses commandes livrées (`total` = somme de `quantity × unit_price`).\n\nL'ordre des lignes n'a pas d'importance.",
            'starter_sql' => "SELECT c.name, \nFROM customers c\n",
            'solution_sql' => "SELECT c.name, SUM(oi.quantity * oi.unit_price) AS total\nFROM customers c\nJOIN orders o ON o.customer_id = c.id\nJOIN order_items oi ON oi.order_id = o.id\nWHERE o.status = 'livrée'\nGROUP BY c.id, c.name;",
            'validation_strategy' => ValidationStrategy::ResultSet,
            'validation_options' => ['required_keywords' => ['JOIN']],
            'hints' => [
                ['text' => 'Il faut relier trois tables : `customers` → `orders` → `order_items`.', 'xp_penalty' => 5],
                ['text' => 'Regroupez par client avec `GROUP BY c.id, c.name` et additionnez `oi.quantity * oi.unit_price`.', 'xp_penalty' => 10],
            ],
            'difficulty' => 3,
            'xp_reward' => 40,
        ]);

        $this->exercise($joins, $intermediate, [$boutique, $hidden], ['aggregation'], [
            'slug' => 'categories-bien-fournies',
            'title' => 'Chasse au bug : les catégories bien fournies',
            'type' => ExerciseType::BugFix,
            'statement' => 'Cette requête doit lister les catégories qui comptent **au moins 3 produits**, avec leur nombre de produits (`nb_produits`)... mais elle provoque une erreur. Corrigez-la.',
            'starter_sql' => "SELECT category, COUNT(*) AS nb_produits\nFROM products\nWHERE COUNT(*) >= 3\nGROUP BY category;",
            'solution_sql' => "SELECT category, COUNT(*) AS nb_produits\nFROM products\nGROUP BY category\nHAVING COUNT(*) >= 3;",
            'validation_strategy' => ValidationStrategy::ResultSet,
            'hints' => [
                ['text' => 'Une fonction d\'agrégat ne peut pas apparaître dans un `WHERE`.', 'xp_penalty' => 5],
            ],
            'difficulty' => 2,
            'xp_reward' => 30,
        ]);

        $mcq = $this->exercise($joins, $intermediate, [], ['aggregation'], [
            'slug' => 'qcm-filtrer-des-groupes',
            'title' => 'QCM : filtrer des groupes',
            'type' => ExerciseType::MultipleChoice,
            'statement' => 'Après un `GROUP BY`, quelle clause permet de ne garder que certains **groupes** ?',
            'validation_strategy' => ValidationStrategy::Choices,
            'difficulty' => 1,
            'xp_reward' => 10,
        ]);
        $mcq->choices()->delete();
        $mcq->choices()->createMany([
            ['body' => 'WHERE', 'is_correct' => false, 'explanation' => 'WHERE filtre les lignes AVANT le regroupement.', 'position' => 1],
            ['body' => 'HAVING', 'is_correct' => true, 'explanation' => 'HAVING est évalué après GROUP BY et peut utiliser des agrégats.', 'position' => 2],
            ['body' => 'ORDER BY', 'is_correct' => false, 'explanation' => 'ORDER BY trie, il ne filtre pas.', 'position' => 3],
            ['body' => 'LIMIT', 'is_correct' => false, 'explanation' => 'LIMIT tronque le résultat, sans condition sur les groupes.', 'position' => 4],
        ]);

        $this->certification($intermediate, [$boutique, $hidden], $mcq);
        $this->sprint();

        $this->exercise($joins, $intermediate, [$boutique, $hidden], ['dml'], [
            'slug' => 'hausse-prix-livres',
            'title' => 'Inflation sur les livres',
            'type' => ExerciseType::QueryWrite,
            'statement' => "Augmentez de **10 %** le prix de tous les produits de la catégorie `Livres` (arrondi au centime).\n\nVos modifications sont annulées après chaque exécution : seul l'état final des données est comparé.",
            'starter_sql' => "UPDATE products\n",
            'solution_sql' => "UPDATE products\nSET price = ROUND(price * 1.10, 2)\nWHERE category = 'Livres';",
            'validation_strategy' => ValidationStrategy::StateCheck,
            'validation_options' => [
                'allowed_statements' => ['dml'],
                'check_queries' => ['products' => 'SELECT id, name, price FROM products ORDER BY id'],
                'float_tolerance' => 0.01,
            ],
            'hints' => [
                ['text' => 'Utilisez `UPDATE ... SET price = ... WHERE ...`.', 'xp_penalty' => 5],
            ],
            'difficulty' => 2,
            'xp_reward' => 30,
        ]);
    }

    /**
     * Exercices réservés à la certification (sans leçon : invisibles en entraînement) et certification.
     *
     * @param  list<Dataset>  $datasets
     */
    private function certification(Level $level, array $datasets, Exercise $mcq): void
    {
        $exercises = collect([
            [
                'slug' => 'cert-clients-sans-commande',
                'title' => 'Clients sans commande',
                'statement' => 'Affichez le nom (`name`) des clients qui n\'ont **jamais** passé de commande.',
                'solution_sql' => 'SELECT name FROM customers WHERE id NOT IN (SELECT customer_id FROM orders);',
                'validation_strategy' => ValidationStrategy::ResultSet,
                'skills' => ['subqueries', 'joins'],
            ],
            [
                'slug' => 'cert-panier-moyen',
                'title' => 'Montant moyen d\'une ligne de commande',
                'statement' => 'Calculez le montant moyen d\'une ligne de commande (`quantity × unit_price`), arrondi à 2 décimales, dans une colonne `montant_moyen`.',
                'solution_sql' => 'SELECT ROUND(AVG(quantity * unit_price), 2) AS montant_moyen FROM order_items;',
                'validation_strategy' => ValidationStrategy::ResultSet,
                'validation_options' => ['float_tolerance' => 0.01],
                'skills' => ['aggregation', 'functions'],
            ],
            [
                'slug' => 'cert-commandes-par-statut',
                'title' => 'Commandes par statut',
                'statement' => 'Affichez chaque statut de commande avec son nombre de commandes (`nb`), du plus fréquent au moins fréquent, puis par statut en ordre alphabétique.',
                'solution_sql' => 'SELECT status, COUNT(*) AS nb FROM orders GROUP BY status ORDER BY nb DESC, status;',
                'validation_strategy' => ValidationStrategy::OrderedResultSet,
                'skills' => ['aggregation', 'sorting'],
            ],
        ])->map(function (array $attributes) use ($level, $datasets) {
            $skills = $attributes['skills'];
            unset($attributes['skills']);

            $exercise = Exercise::updateOrCreate(['slug' => $attributes['slug']], [
                'validation_options' => null,
                ...$attributes,
                'lesson_id' => null,
                'level_id' => $level->id,
                'type' => ExerciseType::QueryWrite,
                'difficulty' => 2,
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

        $certification = Certification::updateOrCreate(['slug' => 'sql-intermediaire'], [
            'level_id' => $level->id,
            'title' => 'Certification SQL — Intermédiaire',
            'description' => 'Jointures, sous-requêtes, agrégats et tris : 3 questions tirées au sort, 20 minutes.',
            'passing_score' => 70,
            'duration_minutes' => 20,
            'exercises_count' => 3,
            'max_attempts' => 3,
            'cooldown_hours' => 1,
            'xp_reward' => 150,
            'status' => ContentStatus::Published,
        ]);

        $certification->exercisePool()->sync([...$exercises->pluck('id'), $mcq->id]);
    }

    /**
     * Contre-la-montre de démonstration : 10 minutes de chrono individuel.
     */
    private function sprint(): void
    {
        $challenge = Challenge::updateOrCreate(['slug' => 'sprint-sql-10-minutes'], [
            'type' => ChallengeType::Timed,
            'title' => 'Sprint SQL — 10 minutes',
            'description' => 'Trois exercices à enchaîner le plus vite possible : le chrono démarre quand vous cliquez.',
            'starts_at' => now()->subDay()->startOfDay(),
            'ends_at' => now()->addMonths(2)->startOfDay(),
            'duration_seconds' => 600,
            'xp_multiplier' => 1.5,
            'status' => ContentStatus::Published,
        ]);

        $challenge->exercises()->sync(
            Exercise::whereIn('slug', ['clients-de-lyon', 'categories-bien-fournies', 'chiffre-affaires-par-client'])
                ->orderBy('difficulty')
                ->get()
                ->values()
                ->mapWithKeys(fn (Exercise $exercise, int $index) => [$exercise->id => ['points' => 100 * $exercise->difficulty, 'position' => $index]])
                ->all()
        );
    }

    private function dataset(SchemaIntrospector $introspector, string $slug, string $name, string $description, string $schema, string $seed, bool $public): Dataset
    {
        $tables = $introspector->describe($schema, $seed);

        $dataset = Dataset::updateOrCreate(['slug' => $slug], [
            'name' => $name,
            'description' => $description,
            'domain' => 'e-commerce',
            'source_format' => DatasetFormat::SqlDump,
            'source_dialect_id' => SqlDialect::where('slug', 'sqlite')->value('id'),
            'tables_meta' => $tables,
            'schema_diagram' => $introspector->mermaid($tables),
            'total_rows' => array_sum(array_column($tables, 'rows')),
            'size_bytes' => strlen($schema) + strlen($seed),
            'checksum' => hash('sha256', $schema.$seed),
            'status' => DatasetStatus::Ready,
            'is_public' => $public,
        ]);

        // Le SQL de ce jeu est portable : le même script sert pour chaque moteur.
        foreach (SqlDialect::whereIn('slug', ['sqlite', 'pgsql', 'mysql'])->get() as $dialect) {
            $dataset->builds()->updateOrCreate(['sql_dialect_id' => $dialect->id], [
                'schema_sql' => $schema,
                'seed_sql' => $seed,
                'status' => DatasetStatus::Ready,
                'built_at' => now(),
            ]);
        }

        return $dataset;
    }

    private function lesson(Level $level, string $courseSlug, string $courseTitle, string $chapterSlug, string $chapterTitle, string $lessonSlug, string $lessonTitle, Dataset $dataset, string $content): Lesson
    {
        $course = Course::updateOrCreate(['slug' => $courseSlug], [
            'level_id' => $level->id,
            'title' => $courseTitle,
            'summary' => $level->position === 1
                ? 'Interroger une table : sélectionner, filtrer, trier.'
                : 'Relier les tables, regrouper et résumer les données.',
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);

        $chapter = $course->chapters()->updateOrCreate(['slug' => $chapterSlug], [
            'title' => $chapterTitle,
            'schema_diagram' => $dataset->schema_diagram,
        ]);

        return $chapter->lessons()->updateOrCreate(['slug' => $lessonSlug], [
            'title' => $lessonTitle,
            'dataset_id' => $dataset->id,
            'content_markdown' => $content,
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * @param  list<Dataset>  $datasets  le premier est le jeu visible, les suivants des jeux de test cachés
     * @param  list<string>  $skills
     * @param  array<string, mixed>  $attributes
     */
    private function exercise(Lesson $lesson, Level $level, array $datasets, array $skills, array $attributes): Exercise
    {
        $exercise = Exercise::updateOrCreate(['slug' => $attributes['slug']], [
            'starter_sql' => null,
            'solution_sql' => null,
            'validation_options' => null,
            'hints' => null,
            ...$attributes,
            'lesson_id' => $lesson->id,
            'level_id' => $level->id,
            'status' => ContentStatus::Published,
            'published_at' => now(),
        ]);

        $exercise->datasets()->sync(collect($datasets)->mapWithKeys(fn (Dataset $dataset, int $index) => [
            $dataset->id => [
                'role' => $index === 0 ? DatasetRole::Primary->value : DatasetRole::HiddenTest->value,
                'position' => $index,
            ],
        ])->all());

        $exercise->skills()->sync(Skill::whereIn('slug', $skills)->pluck('id'));

        return $exercise;
    }
}
