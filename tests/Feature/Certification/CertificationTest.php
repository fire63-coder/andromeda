<?php

namespace Tests\Feature\Certification;

use App\Actions\Certifications\CertificationException;
use App\Actions\Certifications\FinishCertificationAttempt;
use App\Actions\Certifications\StartCertificationAttempt;
use App\Enums\AttemptStatus;
use App\Livewire\Certification\CertificationList;
use App\Livewire\Certification\CertificationRunner;
use App\Livewire\Exercises\ExercisePlayer;
use App\Models\Certification;
use App\Models\CertificationAttempt;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class CertificationTest extends TestCase
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
    }

    private function start(): CertificationAttempt
    {
        return app(StartCertificationAttempt::class)->handle($this->candidate, $this->certification);
    }

    private function answerCorrectly(CertificationAttempt $attempt, int $exerciseId): void
    {
        $exercise = Exercise::with('choices')->findOrFail($exerciseId);
        $component = Livewire::test(ExercisePlayer::class, ['exercise' => $exercise, 'mode' => 'certification', 'contextId' => $attempt->id]);

        $exercise->solution_sql
            ? $component->set('sql', $exercise->solution_sql)
            : $component->set('selectedChoices', $exercise->choices->where('is_correct', true)->pluck('id')->all());

        $component->call('submit')
            ->assertSet('verdict.status', 'recorded')
            ->assertDispatched('answer-recorded');
    }

    #[Test]
    public function starting_draws_a_frozen_subject_and_resumes_it(): void
    {
        $attempt = $this->start();

        $this->assertSame(AttemptStatus::InProgress, $attempt->status);
        $this->assertCount(3, $attempt->exercise_ids);
        $this->assertEqualsWithDelta(now()->addMinutes(20)->timestamp, $attempt->expires_at->timestamp, 2);
        $this->assertTrue($attempt->is($this->start()), 'Une tentative en cours est reprise, pas recréée.');
    }

    #[Test]
    public function certification_only_exercises_are_hidden_from_practice(): void
    {
        $reserved = Exercise::where('slug', 'cert-clients-sans-commande')->firstOrFail();

        $this->get(route('exercises.index'))->assertOk()->assertDontSee('Clients sans commande');
        $this->get(route('exercises.show', $reserved))->assertForbidden();

        // …et ne s'ouvrent en épreuve que si elles font partie du sujet de l'utilisateur.
        $otherAttempt = app(StartCertificationAttempt::class)->handle(User::factory()->create(), $this->certification);
        Livewire::test(ExercisePlayer::class, ['exercise' => $reserved, 'mode' => 'certification', 'contextId' => $otherAttempt->id])
            ->assertForbidden();
    }

    #[Test]
    public function answers_are_recorded_without_revealing_the_verdict_then_scored_at_the_end(): void
    {
        $attempt = $this->start();
        [$first, $second] = $attempt->exercise_ids;

        $this->answerCorrectly($attempt, $first);
        $this->answerCorrectly($attempt, $second);
        // Troisième question sans réponse → 0.

        Livewire::test(CertificationRunner::class, ['attempt' => $attempt])
            ->call('finish')
            ->assertSee('67 %')
            ->assertSee('Non obtenue');

        $attempt->refresh();
        $this->assertSame(AttemptStatus::Failed, $attempt->status);
        $this->assertSame(67, $attempt->score);
        $this->assertNull($attempt->certificate_code);
        $this->assertSame(0, $this->candidate->fresh()->xp, 'Pas d\'XP par question en certification.');
    }

    #[Test]
    public function passing_issues_a_public_certificate_and_xp(): void
    {
        $attempt = $this->start();
        foreach ($attempt->exercise_ids as $id) {
            $this->answerCorrectly($attempt, $id);
        }

        $attempt = app(FinishCertificationAttempt::class)->handle($attempt);

        $this->assertSame(AttemptStatus::Passed, $attempt->status);
        $this->assertSame(100, $attempt->score);
        $this->assertMatchesRegularExpression('/^AND-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $attempt->certificate_code);
        $this->assertGreaterThanOrEqual(150, $this->candidate->fresh()->xp);

        auth()->logout();
        $this->get(route('certificates.show', $attempt->certificate_code))
            ->assertOk()
            ->assertSee($this->candidate->name)
            ->assertSee('Certification SQL — Intermédiaire');
        $this->get(route('certificates.show', 'AND-FAUX-FAUX-FAUX'))->assertNotFound();

        $this->expectException(CertificationException::class);
        $this->expectExceptionMessage('déjà obtenu');
        $this->start();
    }

    #[Test]
    public function answers_after_the_deadline_are_refused_and_the_attempt_is_closed(): void
    {
        $attempt = $this->start();
        $exercise = Exercise::findOrFail($attempt->exercise_ids[0]);
        $player = Livewire::test(ExercisePlayer::class, ['exercise' => $exercise, 'mode' => 'certification', 'contextId' => $attempt->id]);

        $this->travel(21)->minutes();

        $player->set('sql', 'SELECT 1')->call('submit')
            ->assertSet('verdict.status', 'rejected')
            ->assertSee('L\'épreuve est terminée');

        $this->artisan('certifications:expire')->assertSuccessful();
        $this->assertSame(AttemptStatus::Failed, $attempt->fresh()->status);
        $this->assertSame(0, $attempt->fresh()->score);
    }

    #[Test]
    public function attempts_are_limited_and_spaced_out(): void
    {
        $finish = app(FinishCertificationAttempt::class);
        $finish->handle($this->start());

        $this->expectExceptionMessage('Prochaine tentative possible');
        try {
            $this->start();
        } finally {
            $this->travel(2)->hours();
            $finish->handle($this->start());
            $this->travel(2)->hours();
            $finish->handle($this->start());
            $this->travel(2)->hours();

            $this->assertSame(
                'Vous avez utilisé toutes vos tentatives pour cette certification.',
                app(StartCertificationAttempt::class)->ineligibility($this->candidate, $this->certification),
            );
        }
    }

    #[Test]
    public function the_list_starts_an_attempt_and_redirects_to_the_runner(): void
    {
        Livewire::test(CertificationList::class)
            ->assertSee('Certification SQL — Intermédiaire')
            ->call('start', $this->certification->id)
            ->assertRedirect(route('certifications.attempt', CertificationAttempt::firstOrFail()));
    }

    #[Test]
    public function candidates_cannot_open_someone_elses_attempt(): void
    {
        $attempt = app(StartCertificationAttempt::class)->handle(User::factory()->create(), $this->certification);

        $this->get(route('certifications.attempt', $attempt))->assertForbidden();
    }
}
