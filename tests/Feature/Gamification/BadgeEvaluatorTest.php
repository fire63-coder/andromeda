<?php

namespace Tests\Feature\Gamification;

use App\Enums\SubmissionStatus;
use App\Models\Badge;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\User;
use App\Services\Gamification\BadgeEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BadgeEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BadgeEvaluator $badges;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::factory()->create();
        $this->badges = app(BadgeEvaluator::class);
    }

    private function submit(string $exerciseSlug, bool $correct, string $dialect = 'sqlite'): void
    {
        $this->user->submissions()->create([
            'exercise_id' => Exercise::where('slug', $exerciseSlug)->value('id'),
            'sql_dialect_id' => SqlDialect::where('slug', $dialect)->value('id'),
            'status' => $correct ? SubmissionStatus::Correct : SubmissionStatus::Wrong,
            'is_correct' => $correct,
            'score' => $correct ? 100 : 0,
        ]);
    }

    #[Test]
    public function badges_are_awarded_once_with_their_xp_bonus(): void
    {
        $this->assertTrue($this->badges->evaluate($this->user)->isEmpty());

        $this->submit('clients-de-lyon', true);

        $this->assertSame(['premiere-requete'], $this->badges->evaluate($this->user)->pluck('slug')->all());
        $this->assertSame(10, $this->user->fresh()->xp);
        $this->assertTrue($this->badges->evaluate($this->user)->isEmpty());
        $this->assertSame(10, $this->user->fresh()->xp);
    }

    #[Test]
    public function skill_badges_count_distinct_solved_exercises(): void
    {
        $badge = Badge::where('slug', 'maitre-des-jointures')->firstOrFail();

        $this->submit('chiffre-affaires-par-client', true);
        $this->submit('chiffre-affaires-par-client', true);
        $this->submit('clients-de-lyon', true); // pas une jointure

        $this->assertSame([1, 25], $this->badges->progress($this->user, $badge));
    }

    #[Test]
    public function first_try_streak_stops_at_the_first_failed_first_attempt(): void
    {
        $badge = Badge::where('slug', 'sans-faute')->firstOrFail();

        $this->submit('clients-de-lyon', false);   // raté au premier essai
        $this->submit('clients-de-lyon', true);
        $this->submit('hausse-prix-livres', true);
        $this->submit('categories-bien-fournies', true);

        $this->assertSame([2, 10], $this->badges->progress($this->user, $badge));
    }

    #[Test]
    public function dialect_badge_counts_distinct_engines(): void
    {
        $badge = Badge::where('slug', 'polyglotte')->firstOrFail();

        $this->submit('clients-de-lyon', true, 'sqlite');
        $this->submit('clients-de-lyon', true, 'pgsql');
        $this->submit('qcm-filtrer-des-groupes', true, 'mysql'); // les QCM ne comptent pas

        $this->assertSame([2, 4], $this->badges->progress($this->user, $badge));
    }

    #[Test]
    public function streak_badge_uses_the_longest_streak(): void
    {
        $this->user->forceFill(['longest_streak' => 7, 'current_streak' => 1])->save();

        $this->assertContains('regulier', $this->badges->evaluate($this->user)->pluck('slug'));
    }

    #[Test]
    public function inactive_badges_are_never_awarded(): void
    {
        Badge::where('slug', 'premiere-requete')->update(['is_active' => false]);
        $this->submit('clients-de-lyon', true);

        $this->assertTrue($this->badges->evaluate($this->user)->isEmpty());
    }
}
