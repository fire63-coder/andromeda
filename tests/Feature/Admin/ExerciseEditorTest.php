<?php

namespace Tests\Feature\Admin;

use App\Enums\ContentStatus;
use App\Enums\DatasetRole;
use App\Enums\UserRole;
use App\Livewire\Admin\Exercises\ExerciseEditor;
use App\Livewire\Admin\Exercises\ExerciseIndex;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class ExerciseEditorTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private User $trainer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->trainer = $this->user(UserRole::Trainer);
        $this->admin = $this->user(UserRole::Admin);
        $this->actingAs($this->trainer);
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function dataset(string $slug): int
    {
        return Dataset::where('slug', $slug)->value('id');
    }

    private function draft(array $overrides = []): Testable
    {
        $component = Livewire::test(ExerciseEditor::class)
            ->set('title', 'Produits les plus chers')
            ->set('statement', 'Affichez les 3 produits les plus chers (nom, prix).')
            ->set('solutionSql', 'SELECT name, price FROM products ORDER BY price DESC LIMIT 3')
            ->set('strategy', 'ordered_result_set')
            ->set('primaryDatasetId', $this->dataset('boutique'))
            ->set('hiddenDatasetIds', [$this->dataset('boutique-tests')])
            ->set('hints', [['text' => 'Pensez à `LIMIT`.', 'xp_penalty' => 5]]);

        foreach ($overrides as $property => $value) {
            $component->set($property, $value);
        }

        return $component;
    }

    #[Test]
    public function a_trainer_creates_an_exercise_with_datasets_hints_and_skills(): void
    {
        $this->draft(['requiredKeywords' => 'order, limit', 'skillIds' => [Skill::where('slug', 'sorting')->value('id')]])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.exercises.edit', Exercise::where('slug', 'produits-les-plus-chers')->first()));

        $exercise = Exercise::where('slug', 'produits-les-plus-chers')->firstOrFail();
        $this->assertSame($this->trainer->id, $exercise->author_id);
        $this->assertSame(ContentStatus::Draft, $exercise->status);
        $this->assertSame(['ORDER', 'LIMIT'], $exercise->validation_options['required_keywords']);
        $this->assertSame([['text' => 'Pensez à `LIMIT`.', 'xp_penalty' => 5]], $exercise->hints);
        $this->assertSame(
            [DatasetRole::Primary->value, DatasetRole::HiddenTest->value],
            $exercise->datasets()->orderByPivot('position')->get()->pluck('pivot.role')->all(),
        );
        $this->assertSame(['sorting'], $exercise->skills->pluck('slug')->all());
    }

    #[Test]
    public function the_solution_test_runs_the_real_evaluator_on_every_engine(): void
    {
        $component = $this->draft()->call('runTest');

        $component->assertSet('test.passed', true)->assertSee('Solution · SQLite (ANSI)');
        $this->assertGreaterThanOrEqual(1, count($component->get('test.checks')));

        $component->set('solutionSql', 'SELECT nope FROM products')->call('runTest')
            ->assertSet('test.passed', false);
    }

    #[Test]
    public function a_bug_fix_exercise_must_start_from_failing_code(): void
    {
        $this->draft([
            'type' => 'bug_fix',
            'starterSql' => 'SELECT name, price FROM products ORDER BY price DESC LIMIT 3', // déjà correct !
        ])->call('runTest')
            ->assertSet('test.passed', false)
            ->assertSee('Le code de départ est déjà correct');
    }

    #[Test]
    public function trainers_submit_for_review_and_admins_publish_only_tested_exercises(): void
    {
        $this->draft()->set('status', 'published')->call('save')->assertHasErrors('status');
        $this->draft()->set('status', 'in_review')->call('save')->assertHasNoErrors();

        $exercise = Exercise::where('slug', 'produits-les-plus-chers')->firstOrFail();
        $this->assertSame(ContentStatus::InReview, $exercise->status);

        Livewire::actingAs($this->admin)->test(ExerciseIndex::class)->assertSee('1 à relire');

        // Solution cassée : publication refusée.
        $exercise->update(['solution_sql' => 'SELECT nope FROM products']);
        Livewire::actingAs($this->admin)->test(ExerciseEditor::class, ['exercise' => $exercise->fresh()])
            ->set('status', 'published')->call('save')
            ->assertHasErrors('status')
            ->assertSet('test.passed', false);
        $this->assertSame(ContentStatus::InReview, $exercise->fresh()->status);

        // Solution corrigée : publication avec traçabilité de la relecture.
        Livewire::actingAs($this->admin)->test(ExerciseEditor::class, ['exercise' => $exercise->fresh()])
            ->set('solutionSql', 'SELECT name, price FROM products ORDER BY price DESC LIMIT 3')
            ->set('status', 'published')->call('save')
            ->assertHasNoErrors();

        $exercise->refresh();
        $this->assertSame(ContentStatus::Published, $exercise->status);
        $this->assertSame($this->admin->id, $exercise->reviewer_id);
        $this->assertNotNull($exercise->reviewed_at);
    }

    #[Test]
    public function multiple_choice_questions_need_a_correct_answer(): void
    {
        Livewire::test(ExerciseEditor::class)
            ->set('title', 'QCM index')
            ->set('statement', 'Un index accélère…')
            ->set('type', 'mcq')
            ->assertSet('strategy', 'choices')
            ->set('choices', [['body' => 'Les lectures', 'is_correct' => false, 'explanation' => ''], ['body' => 'Rien', 'is_correct' => false, 'explanation' => '']])
            ->call('save')
            ->assertHasErrors('choices')
            ->set('choices.0.is_correct', true)
            ->call('save')
            ->assertHasNoErrors();

        $exercise = Exercise::where('slug', 'qcm-index')->firstOrFail();
        $this->assertSame([true, false], $exercise->choices()->orderBy('position')->pluck('is_correct')->all());
        $this->assertNull($exercise->solution_sql);
    }

    #[Test]
    public function trainers_only_edit_their_own_exercises(): void
    {
        $demo = Exercise::where('slug', 'clients-de-lyon')->firstOrFail(); // sans auteur

        $this->get(route('admin.exercises.edit', $demo))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.exercises.edit', $demo))->assertOk()->assertSee('Les clients lyonnais');
    }

    #[Test]
    public function saving_a_seeded_exercise_keeps_its_control_queries_and_plan_options(): void
    {
        foreach (['fonction-salaire-annuel', 'trigger-audit-salaires', 'index-jointure-par-ville', 'bug-condition-non-indexable'] as $slug) {
            $exercise = Exercise::where('slug', $slug)->firstOrFail();
            $before = ['allowed_statements' => ['select'], ...$exercise->validation_options]; // valeur par défaut explicitée

            Livewire::actingAs($this->admin)->test(ExerciseEditor::class, ['exercise' => $exercise])
                ->call('save')
                ->assertHasNoErrors();

            $after = $exercise->fresh()->validation_options;
            ksort($before);
            ksort($after);
            $this->assertEquals($before, $after, $slug);
        }
    }

    #[Test]
    public function a_trainer_editing_a_published_exercise_sends_it_back_to_review(): void
    {
        $this->draft()->set('status', 'in_review')->call('save');
        $exercise = Exercise::where('slug', 'produits-les-plus-chers')->firstOrFail();
        Livewire::actingAs($this->admin)->test(ExerciseEditor::class, ['exercise' => $exercise])->set('status', 'published')->call('save')->assertHasNoErrors();
        $this->assertSame(ContentStatus::Published, $exercise->fresh()->status);

        // Enregistrer sans rien changer : l'exercice reste publié.
        Livewire::actingAs($this->trainer)->test(ExerciseEditor::class, ['exercise' => $exercise->fresh()])->call('save')->assertHasNoErrors();
        $this->assertSame(ContentStatus::Published, $exercise->fresh()->status);

        // Une vraie modification : retour en relecture, plus proposé aux élèves.
        Livewire::actingAs($this->trainer)->test(ExerciseEditor::class, ['exercise' => $exercise->fresh()])
            ->set('statement', 'Affichez les 3 produits les plus chers (nom, prix), du plus cher au moins cher.')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('status', 'in_review');

        $exercise->refresh();
        $this->assertSame(ContentStatus::InReview, $exercise->status);
        $this->assertNull($exercise->reviewer_id);
        $this->assertFalse(Exercise::practice()->whereKey($exercise->id)->exists());

        // Un administrateur peut modifier un exercice publié sans le dépublier.
        Livewire::actingAs($this->admin)->test(ExerciseEditor::class, ['exercise' => $exercise])->set('status', 'published')->call('save');
        Livewire::actingAs($this->admin)->test(ExerciseEditor::class, ['exercise' => $exercise->fresh()])
            ->set('xpReward', 35)
            ->call('save');
        $this->assertSame(ContentStatus::Published, $exercise->fresh()->status);
    }
}
