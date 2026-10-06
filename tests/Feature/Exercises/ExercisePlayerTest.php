<?php

namespace Tests\Feature\Exercises;

use App\Enums\ContentStatus;
use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Livewire\Exercises\ExercisePlayer;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class ExercisePlayerTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->student = User::factory()->create();
        $this->actingAs($this->student);
    }

    private function exercise(string $slug): Exercise
    {
        return Exercise::where('slug', $slug)->firstOrFail();
    }

    #[Test]
    public function the_page_renders_without_leaking_the_solution(): void
    {
        $this->get(route('exercises.show', 'clients-de-lyon'))
            ->assertOk()
            ->assertSeeLivewire(ExercisePlayer::class)
            ->assertSee('Les clients lyonnais')
            ->assertDontSee("WHERE city = 'Lyon'", false);
    }

    #[Test]
    public function run_shows_the_result_without_a_verdict(): void
    {
        Livewire::test(ExercisePlayer::class, ['exercise' => $this->exercise('clients-de-lyon')])
            ->set('sql', "SELECT name FROM customers WHERE city = 'Lyon'")
            ->call('run')
            ->assertSet('verdict', null)
            ->assertSet('result.row_count', 3)
            ->assertSee('Chloé Durand');

        $this->assertSame(0, $this->student->submissions()->count());
    }

    #[Test]
    public function a_correct_answer_awards_xp_only_once(): void
    {
        $exercise = $this->exercise('clients-de-lyon');
        $sql = "SELECT name, email FROM customers WHERE city = 'Lyon' ORDER BY name";

        Livewire::test(ExercisePlayer::class, ['exercise' => $exercise])
            ->set('sql', $sql)
            ->call('submit')
            ->assertSet('verdict.status', 'correct')
            ->assertSet('verdict.xp', 20)
            ->assertDispatched('xp-gained', amount: 20, total: 20)
            ->call('submit')
            ->assertSet('verdict.status', 'correct')
            ->assertSet('verdict.xp', 0);

        $this->student->refresh();
        $this->assertSame(20, $this->student->xp);
        $this->assertSame(1, $this->student->xpTransactions()->count());
        $this->assertSame(1, $this->student->current_streak);

        $progress = $exercise->progress()->where('user_id', $this->student->id)->first();
        $this->assertSame(ProgressStatus::Completed, $progress->status);
        $this->assertSame(2, $progress->attempts_count);
    }

    #[Test]
    public function a_hardcoded_answer_fails_on_the_hidden_dataset(): void
    {
        Livewire::test(ExercisePlayer::class, ['exercise' => $this->exercise('clients-de-lyon')])
            ->set('sql', 'SELECT name, email FROM customers WHERE id IN (1, 3, 7) ORDER BY name')
            ->call('submit')
            ->assertSet('verdict.status', 'wrong')
            ->assertSet('verdict.score', 50)
            ->assertSet('verdict.feedback.hidden_dataset_failed', true);

        $this->assertSame(0, $this->student->fresh()->xp);
    }

    #[Test]
    public function a_wrong_answer_explains_the_difference(): void
    {
        Livewire::test(ExercisePlayer::class, ['exercise' => $this->exercise('clients-de-lyon')])
            ->set('sql', "SELECT name FROM customers WHERE city = 'Lyon' ORDER BY name")
            ->call('submit')
            ->assertSet('verdict.status', 'wrong')
            ->assertSee('Votre résultat contient 1 colonne, 2 sont attendues.');
    }

    #[Test]
    public function revealed_hints_reduce_the_reward(): void
    {
        Livewire::test(ExercisePlayer::class, ['exercise' => $this->exercise('chiffre-affaires-par-client')])
            ->call('revealHint')
            ->call('revealHint')
            ->call('revealHint') // il n'y en a que deux
            ->assertSet('hintsRevealed', 2)
            ->set('sql', "SELECT c.name, SUM(oi.quantity * oi.unit_price) AS total FROM customers c JOIN orders o ON o.customer_id = c.id JOIN order_items oi ON oi.order_id = o.id WHERE o.status = 'livrée' GROUP BY c.id, c.name")
            ->call('submit')
            ->assertSet('verdict.status', 'correct')
            ->assertSet('verdict.xp', 25); // 40 − 5 − 10
    }

    #[Test]
    public function hints_revealed_count_cannot_be_tampered_with(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(ExercisePlayer::class, ['exercise' => $this->exercise('chiffre-affaires-par-client')])
            ->set('hintsRevealed', 0);
    }

    #[Test]
    public function update_exercises_compare_the_final_state(): void
    {
        Livewire::test(ExercisePlayer::class, ['exercise' => $this->exercise('hausse-prix-livres')])
            ->set('sql', "UPDATE products SET price = price * 1.1 WHERE category = 'Livres'")
            ->call('run')
            ->assertSet('result.state_of', 'products')
            ->call('submit')
            ->assertSet('verdict.status', 'correct');
    }

    #[Test]
    public function multiple_choice_questions_are_graded(): void
    {
        $exercise = $this->exercise('qcm-filtrer-des-groupes');
        $having = $exercise->choices()->where('body', 'HAVING')->value('id');
        $where = $exercise->choices()->where('body', 'WHERE')->value('id');

        Livewire::test(ExercisePlayer::class, ['exercise' => $exercise])
            ->set('selectedChoices', [$where])
            ->call('submit')
            ->assertSet('verdict.status', 'wrong')
            ->assertSee('WHERE filtre les lignes AVANT le regroupement.')
            ->set('selectedChoices', [$having])
            ->call('submit')
            ->assertSet('verdict.status', 'correct')
            ->assertSet('verdict.xp', 10);
    }

    #[Test]
    public function students_cannot_open_draft_exercises_but_authors_can(): void
    {
        $exercise = $this->exercise('clients-de-lyon');
        $exercise->update(['status' => ContentStatus::Draft]);

        $this->get(route('exercises.show', $exercise))->assertForbidden();

        $trainer = User::factory()->create();
        $trainer->forceFill(['role' => UserRole::Trainer])->save();

        $this->actingAs($trainer)->get(route('exercises.show', $exercise))->assertOk();
    }

    #[Test]
    public function executions_are_rate_limited(): void
    {
        config(['sandbox.rate_limit_per_minute' => 2]);

        Livewire::test(ExercisePlayer::class, ['exercise' => $this->exercise('clients-de-lyon')])
            ->set('sql', 'SELECT 1')
            ->call('run')
            ->call('run')
            ->call('run')
            ->assertSet('result.error_type', 'rejected')
            ->assertSee('Trop d\'exécutions rapprochées');
    }

    #[Test]
    public function the_index_lists_published_exercises_by_level(): void
    {
        $this->get(route('exercises.index'))
            ->assertOk()
            ->assertSeeInOrder(['Niveau 1', 'Les clients lyonnais', 'Niveau 2', 'Chiffre d']);
    }
}
