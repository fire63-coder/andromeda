<?php

namespace App\Livewire\Gamification;

use App\Models\Organization;
use App\Services\Gamification\LeaderboardService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Classement')]
class Leaderboard extends Component
{
    #[Url]
    public string $period = 'weekly';

    /** « global » ou identifiant d'une organisation dont l'utilisateur est membre. */
    #[Url]
    public string $scope = 'global';

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, LeaderboardService::PERIODS)) {
            $this->period = 'weekly';
        }
    }

    /**
     * @return Collection<int, Organization>
     */
    #[Computed]
    public function organizations(): Collection
    {
        return auth()->user()->organizations()->orderBy('name')->get();
    }

    #[Computed]
    public function organization(): ?Organization
    {
        // Seules les organisations de l'utilisateur sont consultables.
        return $this->scope === 'global' ? null : $this->organizations->firstWhere('id', (int) $this->scope);
    }

    public function render(LeaderboardService $leaderboard)
    {
        $period = array_key_exists($this->period, LeaderboardService::PERIODS) ? $this->period : 'weekly';
        $standings = $leaderboard->standings($period, $this->organization);

        return view('livewire.gamification.leaderboard', [
            'periods' => LeaderboardService::PERIODS,
            'standings' => $standings,
            'previous' => $leaderboard->previousPositions($period, $this->organization),
            'me' => $standings->contains(fn (array $row) => $row['user']->is(auth()->user()))
                ? null
                : $leaderboard->positionOf(auth()->user(), $period, $this->organization),
        ]);
    }
}
