<?php

namespace Tests\Feature\Arena;

use App\Actions\Challenges\JoinChallenge;
use App\Enums\ChallengeType;
use App\Exceptions\ContextClosed;
use App\Livewire\Arena\ArenaIndex;
use App\Livewire\Arena\ChallengeRunner;
use App\Livewire\Exercises\ExercisePlayer;
use App\Models\Challenge;
use App\Models\ChallengeParticipation;
use App\Models\Exercise;
use App\Models\Organization;
use App\Models\User;
use App\Services\Challenges\DailyChallengeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class ChallengeTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private User $player;

    private Challenge $sprint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->player = User::factory()->create();
        $this->actingAs($this->player);
        $this->sprint = Challenge::where('slug', 'sprint-sql-10-minutes')->firstOrFail();
    }

    private function solve(ChallengeParticipation $participation, string $slug, bool $correctly = true): Testable
    {
        $exercise = Exercise::where('slug', $slug)->firstOrFail();

        return Livewire::test(ExercisePlayer::class, ['exercise' => $exercise, 'mode' => 'challenge', 'contextId' => $participation->id])
            ->set('sql', $correctly ? $exercise->solution_sql : 'SELECT 1')
            ->call('submit');
    }

    #[Test]
    public function the_daily_challenge_is_generated_once_per_day_with_practice_exercises(): void
    {
        $generator = app(DailyChallengeGenerator::class);
        $today = $generator->ensureFor();

        $this->assertSame(ChallengeType::Daily, $today->type);
        $this->assertTrue($today->is($generator->ensureFor()));
        $this->assertCount(3, $today->exercises);
        $this->assertTrue($today->exercises->every(fn (Exercise $exercise) => $exercise->isPractice()));
        $this->assertTrue($today->isRunning());

        $this->artisan('challenges:daily')->assertSuccessful();
        $this->assertSame(1, Challenge::where('type', ChallengeType::Daily)->count());
    }

    #[Test]
    public function solving_earns_points_and_xp_once_per_exercise(): void
    {
        $participation = app(JoinChallenge::class)->handle($this->player, $this->sprint);

        $this->solve($participation, 'clients-de-lyon', correctly: false)->assertSet('verdict.status', 'wrong');
        $this->solve($participation, 'clients-de-lyon')
            ->assertSet('verdict.status', 'correct')
            ->assertSet('verdict.points', 100)
            ->assertDispatched('answer-recorded')
            ->assertDispatched('xp-gained', amount: 25, total: 25)
            ->assertDispatched('badge-unlocked', name: 'Première requête');
        $this->solve($participation, 'clients-de-lyon')->assertSet('verdict.points', 0);

        $participation->refresh();
        $this->assertSame(100, $participation->score);
        $this->assertSame(1, $participation->solved_count);
        // 100 pts / 10 × 1,5 = 15 XP du défi, + 10 XP du badge « Première requête ». Pas d'XP d'entraînement.
        $this->assertSame(25, $this->player->fresh()->xp);
        $this->assertSame(0, $this->player->progress()->count());
    }

    #[Test]
    public function solving_everything_finishes_the_participation(): void
    {
        $participation = app(JoinChallenge::class)->handle($this->player, $this->sprint);

        foreach (['clients-de-lyon', 'categories-bien-fournies', 'chiffre-affaires-par-client'] as $slug) {
            $this->solve($participation, $slug);
        }

        $participation->refresh();
        $this->assertNotNull($participation->finished_at);
        $this->assertSame(100 + 200 + 300, $participation->score);
        $this->assertFalse($participation->isOpen());
    }

    #[Test]
    public function the_personal_clock_closes_a_timed_challenge(): void
    {
        $participation = app(JoinChallenge::class)->handle($this->player, $this->sprint);
        $player = Livewire::test(ExercisePlayer::class, [
            'exercise' => Exercise::where('slug', 'clients-de-lyon')->firstOrFail(),
            'mode' => 'challenge',
            'contextId' => $participation->id,
        ]);

        $this->travel(11)->minutes();

        $player->set('sql', "SELECT name, email FROM customers WHERE city = 'Lyon' ORDER BY name")
            ->call('submit')
            ->assertSet('verdict.status', 'rejected')
            ->assertSee('Le défi est terminé pour vous');

        $this->assertSame(0, $participation->fresh()->score);
    }

    #[Test]
    public function exercises_outside_the_challenge_cannot_be_played_in_its_context(): void
    {
        $participation = app(JoinChallenge::class)->handle($this->player, $this->sprint);

        Livewire::test(ExercisePlayer::class, [
            'exercise' => Exercise::where('slug', 'cert-clients-sans-commande')->firstOrFail(),
            'mode' => 'challenge',
            'contextId' => $participation->id,
        ])->assertForbidden();
    }

    #[Test]
    public function standings_rank_by_points_then_speed(): void
    {
        $fast = User::factory()->create(['name' => 'Rapide']);
        $slow = User::factory()->create(['name' => 'Lent']);

        $this->sprint->participations()->create(['user_id' => $slow->id, 'score' => 300, 'total_time_ms' => 90_000, 'started_at' => now()]);
        $this->sprint->participations()->create(['user_id' => $fast->id, 'score' => 300, 'total_time_ms' => 40_000, 'started_at' => now()]);
        $this->sprint->participations()->create(['user_id' => $this->player->id, 'score' => 400, 'total_time_ms' => 120_000, 'started_at' => now()]);

        $this->assertSame([$this->player->id, $fast->id, $slow->id], $this->sprint->standings()->pluck('user_id')->all());
    }

    #[Test]
    public function private_challenges_are_reserved_to_their_organization(): void
    {
        $acme = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $this->sprint->update(['organization_id' => $acme->id]);

        $this->get(route('arena.show', $this->sprint))->assertForbidden();

        try {
            app(JoinChallenge::class)->handle($this->player, $this->sprint->fresh());
            $this->fail('Un non-membre a pu rejoindre un défi privé.');
        } catch (ContextClosed $e) {
            $this->assertStringContainsString('Acme', $e->getMessage());
        }

        $acme->members()->attach($this->player);
        $this->get(route('arena.show', $this->sprint))->assertOk();
    }

    #[Test]
    public function the_arena_lists_the_daily_challenge_and_running_events(): void
    {
        Livewire::test(ArenaIndex::class)
            ->assertSee('Défi du jour')
            ->assertSee('Sprint SQL — 10 minutes');

        Livewire::test(ChallengeRunner::class, ['challenge' => $this->sprint])
            ->assertSee('Lancer le chrono')
            ->call('join')
            ->assertSee('0 pts')
            ->assertSeeLivewire(ExercisePlayer::class);
    }
}
