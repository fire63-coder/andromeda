<?php

namespace Tests\Feature\Certification;

use App\Actions\Certifications\FinishCertificationAttempt;
use App\Actions\Certifications\StartCertificationAttempt;
use App\Enums\AttemptStatus;
use App\Enums\ContentStatus;
use App\Enums\ExerciseType;
use App\Enums\UserRole;
use App\Livewire\Admin\Certifications\CertificationEditor;
use App\Livewire\Certification\CertificationRunner;
use App\Livewire\Exercises\ExercisePlayer;
use App\Models\Certification;
use App\Models\CertificationAttempt;
use App\Models\Exercise;
use App\Models\User;
use App\Notifications\CertificationCompleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class ExamModeTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private User $candidate;

    private Certification $certification;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->candidate = User::factory()->create();
        $this->actingAs($this->candidate);
        $this->certification = Certification::where('slug', 'sql-intermediaire')->firstOrFail();
        $this->certification->update(['exam_mode' => true, 'max_incidents' => 2]);
    }

    private function start(): CertificationAttempt
    {
        return app(StartCertificationAttempt::class)->handle($this->candidate, $this->certification);
    }

    private function runner(CertificationAttempt $attempt)
    {
        return Livewire::test(CertificationRunner::class, ['attempt' => $attempt]);
    }

    #[Test]
    public function subjects_are_random_but_balanced_by_difficulty(): void
    {
        $pool = Exercise::published()->limit(6)->get();
        $pool->take(3)->each->update(['difficulty' => 1]);
        $pool->skip(3)->each->update(['difficulty' => 5]);
        $this->certification->exercisePool()->sync($pool->pluck('id'));
        $this->certification->update(['exercises_count' => 2]);

        $subjects = collect(range(1, 25))->map(function () {
            $ids = app(StartCertificationAttempt::class)->draw($this->certification->fresh());
            $difficulties = Exercise::findMany($ids)->pluck('difficulty')->sort()->values()->all();

            $this->assertCount(2, $ids);
            $this->assertSame([1, 5], $difficulties, 'Une question de chaque niveau de difficulté, comme dans le pool.');

            return $ids->sort()->implode(',');
        });

        $this->assertGreaterThan(1, $subjects->unique()->count(), 'Les candidats ne reçoivent pas tous le même sujet.');
    }

    #[Test]
    public function multiple_choice_answers_are_shuffled_per_attempt_and_stable(): void
    {
        $mcq = Exercise::where('type', ExerciseType::MultipleChoice)->whereHas('choices', null, '>=', 3)->firstOrFail();
        $byPosition = $mcq->choices()->orderBy('position')->pluck('id')->all();
        $orders = [];

        foreach ([101, 202, 303, 404, 505] as $attemptId) {
            $expected = collect($byPosition)->sortBy(fn (int $id) => hash('xxh3', "{$attemptId}:{$id}"))->values()->all();
            $orders[] = $expected;

            $player = new ExercisePlayer;
            $player->exercise = $mcq;
            $player->mode = 'certification';
            $player->contextId = $attemptId;

            $this->assertSame($expected, $player->render()->getData()['choices']->pluck('id')->all());
            $this->assertSame($expected, $player->render()->getData()['choices']->pluck('id')->all(), 'Même ordre à chaque affichage.');
        }

        $this->assertGreaterThan(1, collect($orders)->map(fn ($order) => implode(',', $order))->unique()->count());
    }

    #[Test]
    public function exits_are_counted_once_per_burst_and_close_the_exam_beyond_the_limit(): void
    {
        $attempt = $this->start();
        $runner = $this->runner($attempt)->assertSeeHtml('data-testid="exam-guard"');

        // Quitter le plein écran déclenche aussi un changement d'onglet : un seul incident.
        $runner->call('reportIncident', 'fullscreen_exit')->assertReturned(['counted' => true, 'count' => 1, 'limit' => 2, 'closed' => false]);
        $runner->call('reportIncident', 'tab_hidden')->assertReturned(['counted' => false, 'count' => 1, 'limit' => 2, 'closed' => false]);

        // Collage bloqué : journalisé, jamais compté. Type inconnu : ignoré.
        $runner->call('reportIncident', 'paste_blocked')->assertReturned(['counted' => false, 'count' => 1, 'limit' => 2, 'closed' => false]);
        $runner->call('reportIncident', 'devtools')->assertReturned(['counted' => false, 'count' => 1, 'limit' => null, 'closed' => false]);

        $this->travel(5)->seconds();
        $runner->call('reportIncident', 'window_blur')->assertReturned(['counted' => true, 'count' => 2, 'limit' => 2, 'closed' => false]);
        $this->assertSame(AttemptStatus::InProgress, $attempt->fresh()->status);

        $this->travel(5)->seconds();
        $runner->call('reportIncident', 'tab_hidden')->assertReturned(['counted' => true, 'count' => 3, 'limit' => 2, 'closed' => true]);

        $attempt->refresh();
        $this->assertSame(AttemptStatus::Failed, $attempt->status);
        $this->assertSame('incidents', $attempt->closed_reason);
        $this->assertSame(['opened', 'fullscreen_exit', 'tab_hidden', 'paste_blocked', 'window_blur', 'tab_hidden'], array_column($attempt->incidents, 'type'));

        $runner->assertDontSeeHtml('data-testid="exam-guard"')
            ->assertSee('Épreuve close : trop de sorties')
            ->assertSeeHtml('data-testid="incident-log"');

        // Le candidat est prévenu dans son centre de notifications.
        $notification = $this->candidate->notifications()->sole();
        $this->assertSame(CertificationCompleted::class, $notification->type);
        $this->assertStringContainsString('trop d\'incidents', $notification->data['body']);

        // Une fois close, plus rien n'est compté.
        $runner->call('reportIncident', 'tab_hidden')->assertReturned(['counted' => false, 'count' => 3, 'limit' => 2, 'closed' => false]);
    }

    #[Test]
    public function reopening_the_exam_page_counts_as_an_incident(): void
    {
        $attempt = $this->start();

        $this->get(route('certifications.attempt', $attempt))->assertOk();
        $this->assertSame(0, $attempt->fresh()->incidents_count);

        $this->travel(5)->seconds();
        $this->get(route('certifications.attempt', $attempt))->assertOk();
        $this->assertSame(1, $attempt->fresh()->incidents_count);
        $this->assertSame('page_reload', last($attempt->fresh()->incidents)['type']);
    }

    #[Test]
    public function finishing_shows_the_monitoring_log(): void
    {
        $attempt = $this->start();

        $this->runner($attempt)
            ->call('reportIncident', 'fullscreen_exit')
            ->call('finish')
            ->assertDontSeeHtml('data-testid="exam-guard"')
            ->assertSeeHtml('data-testid="incident-log"')
            ->assertSee('Sortie du plein écran')
            ->assertDispatched('banner-message');

        $this->assertSame('submitted', $attempt->fresh()->closed_reason);
    }

    #[Test]
    public function without_exam_mode_nothing_is_tracked(): void
    {
        $this->certification->update(['exam_mode' => false]);
        $attempt = $this->start();

        $this->runner($attempt)
            ->assertDontSeeHtml('data-testid="exam-guard"')
            ->call('reportIncident', 'fullscreen_exit')
            ->assertReturned(['counted' => false, 'count' => 0, 'limit' => 2, 'closed' => false]);

        $this->get(route('courses.index'))->assertOk();
        $this->assertNull($attempt->fresh()->incidents);
    }

    #[Test]
    public function the_rest_of_the_application_is_closed_during_the_exam(): void
    {
        $attempt = $this->start();

        // Page de l'épreuve sans barre de navigation.
        $this->get(route('certifications.attempt', $attempt))->assertOk()->assertDontSee(route('leaderboard'));

        foreach (['dashboard', 'courses.index', 'exercises.index', 'arena.index', 'notifications.index', 'profile.show'] as $route) {
            $this->get(route($route))->assertRedirect(route('certifications.attempt', $attempt));
        }

        // Un onglet d'entraînement ouvert avant l'épreuve ne peut plus utiliser la sandbox.
        $practice = Exercise::published()->whereNotNull('lesson_id')->where('type', '!=', ExerciseType::MultipleChoice)->firstOrFail();
        Livewire::test(ExercisePlayer::class, ['exercise' => $practice])
            ->set('sql', 'SELECT 1')
            ->call('run')
            ->assertSet('result.error', fn ($error) => str_contains($error, 'mode examen'));

        app(FinishCertificationAttempt::class)->handle($attempt);

        $this->get(route('courses.index'))->assertOk();
    }

    #[Test]
    public function an_expired_exam_no_longer_locks_the_application(): void
    {
        $attempt = $this->start();
        $this->travel($this->certification->duration_minutes + 1)->minutes();

        $this->get(route('courses.index'))->assertOk();
        $this->assertSame(AttemptStatus::InProgress, $attempt->fresh()->status);
    }

    #[Test]
    public function admins_configure_exam_mode(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Livewire::actingAs($admin)->test(CertificationEditor::class, ['certification' => $this->certification])
            ->assertSet('examMode', true)
            ->assertSet('maxIncidents', 2)
            ->set('maxIncidents', 99)
            ->call('save')
            ->assertHasErrors('maxIncidents')
            ->set('maxIncidents', 5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(5, $this->certification->fresh()->max_incidents);

        Livewire::actingAs($admin)->test(CertificationEditor::class, ['certification' => $this->certification->fresh()])
            ->set('examMode', false)
            ->call('save');

        $this->assertFalse($this->certification->fresh()->exam_mode);
        $this->assertNull($this->certification->fresh()->max_incidents);
        $this->assertSame(ContentStatus::Published, $this->certification->fresh()->status);
    }
}
