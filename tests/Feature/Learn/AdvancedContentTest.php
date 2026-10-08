<?php

namespace Tests\Feature\Learn;

use App\Enums\SubmissionStatus;
use App\Livewire\Learn\LessonViewer;
use App\Models\Course;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\User;
use App\Services\Content\LessonRenderer;
use App\Services\Evaluation\SubmissionEvaluator;
use App\Services\Sandbox\SandboxManager;
use App\Services\Sandbox\Scenario\ScenarioParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

/**
 * Contenu des niveaux 3 et 4 : exemples des leçons, solutions et erreurs typiques.
 * Les parties PostgreSQL sont ignorées sans serveur de sandbox.
 */
class AdvancedContentTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private SubmissionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->evaluator = app(SubmissionEvaluator::class);
    }

    private function requirePostgres(): SqlDialect
    {
        $config = config('sandbox.drivers.pgsql');

        try {
            new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout=2", $config['runner_username'], $config['runner_password']);
        } catch (PDOException) {
            $this->markTestSkipped('Serveur PostgreSQL de sandbox indisponible.');
        }

        return SqlDialect::where('slug', 'pgsql')->firstOrFail();
    }

    private function exercise(string $slug): Exercise
    {
        return Exercise::where('slug', $slug)->firstOrFail();
    }

    #[Test]
    public function every_runnable_example_of_the_advanced_courses_succeeds(): void
    {
        $this->requirePostgres();
        $renderer = app(LessonRenderer::class);
        $sandbox = app(SandboxManager::class);

        foreach (Course::whereIn('slug', ['sql-avance', 'programmation-postgresql', 'optimisation-des-requetes', 'transactions-et-concurrence'])->with('chapters.lessons.dataset', 'dialect')->get() as $course) {
            $dialects = $course->dialect ? collect([$course->dialect]) : SqlDialect::query()->executable()->get();

            foreach ($course->chapters->flatMap->lessons as $lesson) {
                foreach ($renderer->snippets($lesson->content_markdown) as $index => $sql) {
                    foreach ($dialects as $dialect) {
                        if (! $sandbox->isExecutable($lesson->dataset, $dialect)) {
                            continue;
                        }

                        $result = ScenarioParser::isScenario($sql)
                            ? $sandbox->runScenario($lesson->dataset, $dialect, $sql)
                            : $sandbox->run($lesson->dataset, $dialect, $sql, LessonRenderer::SNIPPET_GUARD);
                        $this->assertTrue($result->success, "{$lesson->slug} #{$index} ({$dialect->slug}) : {$result->error}");
                    }
                }
            }
        }
    }

    #[Test]
    public function row_number_misses_ties_hidden_in_the_test_dataset(): void
    {
        $exercise = $this->exercise('meilleur-salaire-par-departement');
        $sqlite = SqlDialect::where('slug', 'sqlite')->firstOrFail();

        $result = $this->evaluator->evaluate($exercise, $sqlite, <<<'SQL'
            WITH c AS (
                SELECT d.name AS departement, e.name AS nom, e.salary AS salaire,
                       ROW_NUMBER() OVER (PARTITION BY e.department_id ORDER BY e.salary DESC) AS rang
                FROM employees e JOIN departments d ON d.id = e.department_id
            )
            SELECT departement, nom, salaire FROM c WHERE rang = 1
            SQL);

        $this->assertSame(SubmissionStatus::Wrong, $result->status);
        $this->assertTrue($result->feedback['hidden_dataset_failed'] ?? false, $result->message);
    }

    #[Test]
    public function stored_code_exercises_are_only_offered_on_postgresql(): void
    {
        $this->requirePostgres();

        $this->assertSame(['pgsql'], $this->evaluator->availableDialects($this->exercise('fonction-salaire-annuel'))->pluck('slug')->all());
        $this->assertContains('sqlite', $this->evaluator->availableDialects($this->exercise('equipe-du-dsi'))->pluck('slug')->all());
    }

    #[Test]
    public function a_function_is_checked_through_the_control_queries(): void
    {
        $pgsql = $this->requirePostgres();
        $exercise = $this->exercise('fonction-salaire-annuel');

        $this->assertSame(SubmissionStatus::Correct, $this->evaluator->evaluate($exercise, $pgsql, $exercise->solution_sql)->status);

        // Oubli de la prime des managers.
        $wrong = $this->evaluator->evaluate($exercise, $pgsql, $exercise->starter_sql);
        $this->assertSame(SubmissionStatus::Wrong, $wrong->status);
        $this->assertStringContainsString('salaire_annuel(id)', $wrong->message);

        // Fonction absente : l'erreur du contrôle est remontée telle quelle.
        $missing = $this->evaluator->evaluate($exercise, $pgsql, 'CREATE FUNCTION autre_nom(emp_id integer) RETURNS numeric LANGUAGE sql AS $$ SELECT 1 $$');
        $this->assertStringContainsString('salaire_annuel', (string) $missing->message);
    }

    #[Test]
    public function the_audit_trigger_must_ignore_unchanged_salaries(): void
    {
        $pgsql = $this->requirePostgres();
        $exercise = $this->exercise('trigger-audit-salaires');

        $this->assertSame(SubmissionStatus::Correct, $this->evaluator->evaluate($exercise, $pgsql, $exercise->solution_sql)->status);

        $withoutCondition = str_replace("WHEN (OLD.salary IS DISTINCT FROM NEW.salary)\n", '', $exercise->solution_sql);
        $result = $this->evaluator->evaluate($exercise, $pgsql, $withoutCondition);

        $this->assertSame(SubmissionStatus::Wrong, $result->status);
        $this->assertSame('salary_audit', $result->feedback['check'] ?? null, $result->message);
    }

    #[Test]
    public function the_postgresql_course_only_offers_postgresql_in_its_lessons(): void
    {
        $this->requirePostgres();
        $course = Course::where('slug', 'programmation-postgresql')->firstOrFail();
        $lesson = $course->chapters->first()->lessons->first();

        $admin = User::where('email', 'admin@example.com')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(LessonViewer::class, ['course' => $course, 'lesson' => $lesson])
            ->assertSet('dialect', 'pgsql')
            ->call('runSnippet', 0)
            ->assertSet('results.0.success', true);
    }

    #[Test]
    public function index_exercises_read_the_execution_plan_on_each_engine(): void
    {
        $exercise = $this->exercise('index-jointure-par-ville');

        foreach (['sqlite', 'pgsql'] as $slug) {
            $dialect = $slug === 'pgsql' ? $this->requirePostgres() : SqlDialect::where('slug', $slug)->firstOrFail();

            $this->assertSame(SubmissionStatus::Correct, $this->evaluator->evaluate($exercise, $dialect, $exercise->solution_sql)->status, $slug);

            // Un seul des deux index : la jointure parcourt encore employees.
            $half = $this->evaluator->evaluate($exercise, $dialect, 'CREATE INDEX idx_city ON departments (city)');
            $this->assertSame(SubmissionStatus::Wrong, $half->status, $slug);
            $this->assertStringContainsString('« employees »', $half->message);
            $this->assertNotEmpty($half->feedback['plan']);

            // Index sur la mauvaise colonne.
            $wrong = $this->evaluator->evaluate($this->exercise('index-subordonnes'), $dialect, 'CREATE INDEX ix ON employees (department_id)');
            $this->assertSame(SubmissionStatus::Wrong, $wrong->status, $slug);
        }

        $this->assertNotContains('mysql', $this->evaluator->availableDialects($exercise)->pluck('slug')->all());
    }

    #[Test]
    public function a_rewritten_query_must_keep_its_result_and_use_the_index(): void
    {
        $exercise = $this->exercise('bug-condition-non-indexable');
        $sqlite = SqlDialect::where('slug', 'sqlite')->firstOrFail();

        $this->assertSame(SubmissionStatus::Correct, $this->evaluator->evaluate($exercise, $sqlite, $exercise->solution_sql)->status);

        // Indexable mais faux : la borne de fin manque.
        $result = $this->evaluator->evaluate($exercise, $sqlite, "SELECT name, hired_at FROM employees WHERE hired_at >= '2021-01-01'");
        $this->assertSame(SubmissionStatus::Wrong, $result->status);
        $this->assertArrayNotHasKey('plan', $result->feedback);
    }

    #[Test]
    public function index_creation_on_postgresql_does_not_touch_the_dataset(): void
    {
        $pgsql = $this->requirePostgres();
        $sandbox = app(SandboxManager::class);
        $dataset = Dataset::where('slug', 'entreprise')->firstOrFail();

        $result = $sandbox->run($dataset, $pgsql, 'CREATE INDEX ix_tmp ON employees (manager_id); ALTER TABLE employees ADD COLUMN prime numeric; UPDATE employees SET prime = 1', ['allowed_statements' => ['ddl', 'dml'], 'max_statements' => 3]);
        $this->assertTrue($result->success, (string) $result->error);

        $after = $sandbox->run($dataset, $pgsql, "SELECT COUNT(*) FROM pg_indexes WHERE indexname = 'ix_tmp'");
        $this->assertSame([[0]], $after->rows);
        $this->assertStringContainsString('prime', (string) $sandbox->run($dataset, $pgsql, 'SELECT prime FROM employees')->error);
    }

    #[Test]
    public function concurrency_scenarios_run_on_real_sessions(): void
    {
        $pgsql = $this->requirePostgres();
        $sandbox = app(SandboxManager::class);
        $shop = Dataset::where('slug', 'boutique')->firstOrFail();
        $stock = ['stock' => 'SELECT stock FROM products WHERE id = 1'];

        // B attend le verrou de A, puis lit la valeur validée par A.
        $locked = $sandbox->runScenario($shop, $pgsql, "-- A\nBEGIN;\nSELECT stock FROM products WHERE id = 1 FOR UPDATE;\n-- B\nBEGIN;\nSELECT stock FROM products WHERE id = 1 FOR UPDATE;\n-- A\nUPDATE products SET stock = stock - 1 WHERE id = 1;\nCOMMIT;\n-- B\nUPDATE products SET stock = stock - 1 WHERE id = 1;\nCOMMIT;", [], null, $stock);
        $this->assertTrue($locked->success, (string) $locked->error);
        $this->assertTrue($locked->timeline[1]['waited']);
        $this->assertSame(3, $locked->timeline[1]['completed_at_step']);
        $this->assertSame([['39']], $locked->timeline[1]['rows']);
        $this->assertSame([[38]], $locked->checks['stock']['rows']);

        // Interblocage détecté par PostgreSQL.
        $deadlock = $sandbox->runScenario($shop, $pgsql, "-- A\nBEGIN;\nUPDATE products SET stock = 1 WHERE id = 1;\n-- B\nBEGIN;\nUPDATE products SET stock = 2 WHERE id = 2;\n-- A\nUPDATE products SET stock = 1 WHERE id = 2;\n-- B\nUPDATE products SET stock = 2 WHERE id = 1;\n-- A\nCOMMIT;\n-- B\nCOMMIT;");
        $this->assertContains('40P01', array_column($deadlock->timeline, 'sqlstate'));

        // Les COMMIT des sessions ne touchent jamais le jeu de données ; aucune copie ne reste.
        $this->assertSame([[40]], $sandbox->run($shop, $pgsql, 'SELECT stock FROM products WHERE id = 1')->rows);
        $this->assertSame([[0]], $sandbox->run($shop, $pgsql, "SELECT COUNT(*) FROM pg_namespace WHERE nspname LIKE 't\\_sc\\_%'")->rows);

        // SQLite : refusé avec une explication.
        $this->assertStringContainsString('PostgreSQL', (string) $sandbox->runScenario($shop, SqlDialect::where('slug', 'sqlite')->first(), "-- A\nSELECT 1")->error);
    }

    #[Test]
    public function concurrency_exercises_check_interleaving_steps_and_final_state(): void
    {
        $pgsql = $this->requirePostgres();
        $lost = $this->exercise('mise-a-jour-perdue');

        // Autre correction valable : verrouiller la ligne lue.
        $forUpdate = str_replace(['SELECT stock FROM products WHERE id = 1;', 'SET stock = 39'], ['SELECT stock FROM products WHERE id = 1 FOR UPDATE;', 'SET stock = stock - 1'], $lost->starter_sql);
        $this->assertSame(SubmissionStatus::Correct, $this->evaluator->evaluate($lost, $pgsql, $forUpdate)->status);

        // Mise à jour relative : correct ; valeur finale codée en dur (38) : juste sur le jeu visible, faux sur le jeu caché.
        $this->assertSame(SubmissionStatus::Correct, $this->evaluator->evaluate($lost, $pgsql, str_replace('SET stock = 39', 'SET stock = stock - 1', $lost->starter_sql))->status);
        $hardcoded = $this->evaluator->evaluate($lost, $pgsql, preg_replace('/SET stock = 39(.*)SET stock = 39/s', 'SET stock = 39$1SET stock = 38', $lost->starter_sql));
        $this->assertSame(SubmissionStatus::Wrong, $hardcoded->status);
        $this->assertSame(50, $hardcoded->score);

        // Réordonner les étapes pour éviter le problème est refusé.
        $serial = "-- A\nBEGIN;\nUPDATE products SET stock = stock - 1 WHERE id = 1;\nCOMMIT;\n-- B\nBEGIN;\nUPDATE products SET stock = stock - 1 WHERE id = 1;\nCOMMIT;";
        $rejected = $this->evaluator->evaluate($lost, $pgsql, $serial);
        $this->assertSame(SubmissionStatus::Rejected, $rejected->status);
        $this->assertStringContainsString('A → B → A → B', $rejected->message);

        // Lecture non répétable : l'étape 3 diffère en READ COMMITTED.
        $inventory = $this->exercise('inventaire-coherent');
        $wrong = $this->evaluator->evaluate($inventory, $pgsql, $inventory->starter_sql);
        $this->assertSame(SubmissionStatus::Wrong, $wrong->status);
        $this->assertSame(3, $wrong->feedback['step']);
        $this->assertSame(SubmissionStatus::Correct, $this->evaluator->evaluate($inventory, $pgsql, str_replace('BEGIN;', 'BEGIN ISOLATION LEVEL SERIALIZABLE;', $inventory->starter_sql))->status);
    }
}
