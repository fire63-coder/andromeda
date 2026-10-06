<?php

namespace Tests\Feature\Gamification;

use App\Livewire\Gamification\Leaderboard;
use App\Livewire\Learn\Dashboard;
use App\Models\Organization;
use App\Models\User;
use App\Models\XpTransaction;
use App\Services\Gamification\LeaderboardService;
use App\Services\Gamification\XpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private LeaderboardService $leaderboard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->leaderboard = app(LeaderboardService::class);
    }

    private function learner(string $name, int $xpThisWeek = 0, int $xpLastMonth = 0): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(XpService::class)->award($user, $xpThisWeek, 'exercise_solved');

        if ($xpLastMonth > 0) {
            app(XpService::class)->award($user, $xpLastMonth, 'exercise_solved');
            XpTransaction::where('user_id', $user->id)->latest('id')->first()
                ->forceFill(['created_at' => now()->subMonths(2)])->save();
        }

        return $user;
    }

    #[Test]
    public function weekly_standings_only_count_this_weeks_xp_and_share_tied_positions(): void
    {
        $alice = $this->learner('Alice', xpThisWeek: 50, xpLastMonth: 1000);
        $bruno = $this->learner('Bruno', xpThisWeek: 80);
        $chloe = $this->learner('Chloé', xpThisWeek: 50);
        $this->learner('David'); // aucun point : absent

        $weekly = $this->leaderboard->standings('weekly');
        $this->assertSame(['Bruno', 'Alice', 'Chloé'], $weekly->pluck('user.name')->all());
        $this->assertSame([1, 2, 2], $weekly->pluck('position')->all());

        $allTime = $this->leaderboard->standings('all_time');
        $this->assertSame('Alice', $allTime->first()['user']->name);
        $this->assertSame(1050, $allTime->first()['score']);

        $this->assertSame(['position' => 2, 'score' => 50], $this->leaderboard->positionOf($chloe, 'weekly'));
        $this->assertNull($this->leaderboard->positionOf(User::where('name', 'David')->first(), 'weekly'));
    }

    #[Test]
    public function hidden_users_and_other_organizations_are_excluded(): void
    {
        $alice = $this->learner('Alice', 30);
        $bruno = $this->learner('Bruno', 90);
        $chloe = $this->learner('Chloé', 60);
        $chloe->forceFill(['leaderboard_visible' => false])->save();

        $acme = Organization::create(['name' => 'Acme', 'slug' => 'acme']);
        $acme->members()->attach($alice);

        $this->assertSame(['Bruno', 'Alice'], $this->leaderboard->standings('weekly')->pluck('user.name')->all());
        $this->assertSame(['Alice'], $this->leaderboard->standings('weekly', $acme)->pluck('user.name')->all());
    }

    #[Test]
    public function snapshots_give_yesterdays_positions(): void
    {
        $alice = $this->learner('Alice', 30);
        $bruno = $this->learner('Bruno', 20);

        $this->travelTo(now()->subDay());
        $this->leaderboard->snapshot();
        $this->travelBack();

        $this->assertSame([$alice->id => 1, $bruno->id => 2], $this->leaderboard->previousPositions('all_time')->all());
    }

    #[Test]
    public function the_leaderboard_page_highlights_the_current_user(): void
    {
        $this->learner('Alice', 30);
        $me = $this->learner('Moi', 10);

        Livewire::actingAs($me)
            ->test(Leaderboard::class)
            ->assertSeeInOrder(['Alice', 'Moi (vous)'])
            ->set('period', 'all_time')
            ->assertSee('Moi (vous)');
    }

    #[Test]
    public function users_cannot_view_an_organization_they_do_not_belong_to(): void
    {
        $outsider = $this->learner('Alice', 30);
        $secret = Organization::create(['name' => 'Secret', 'slug' => 'secret']);
        $secret->members()->attach($outsider);
        $me = $this->learner('Moi', 10);

        // Scope inconnu → retombe sur le classement global, sans fuite de l'organisation.
        Livewire::actingAs($me)
            ->test(Leaderboard::class)
            ->set('scope', (string) $secret->id)
            ->assertDontSee('Secret')
            ->assertSee('Moi (vous)');
    }

    #[Test]
    public function the_dashboard_shows_rank_progress_and_badges(): void
    {
        $me = $this->learner('Moi', 300);

        $this->actingAs($me)->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(Dashboard::class)
            ->assertSee('Apprenti requêteur')
            ->assertSee('Maître des Jointures')
            ->assertSee('Les clients lyonnais'); // prochain exercice suggéré
    }
}
