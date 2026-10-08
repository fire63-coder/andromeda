<?php

namespace App\Livewire\Learn;

use App\Enums\ProgressStatus;
use App\Models\Badge;
use App\Models\Exercise;
use App\Models\Level;
use App\Models\Rank;
use App\Services\Gamification\BadgeEvaluator;
use App\Services\Gamification\LeaderboardService;
use App\Services\Learning\AssignmentProgress;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Tableau de bord apprenant : XP et rang, série, progression par niveau,
 * badges (obtenus et à débloquer), activité récente, prochain exercice.
 */
#[Title('Tableau de bord')]
class Dashboard extends Component
{
    /**
     * @return Collection<int, int> ids des exercices résolus
     */
    #[Computed]
    public function solvedIds(): Collection
    {
        return auth()->user()->progress()
            ->where('progressable_type', (new Exercise)->getMorphClass())
            ->where('status', ProgressStatus::Completed)
            ->pluck('progressable_id');
    }

    /**
     * Devoirs non terminés des organisations de l'élève, les plus urgents d'abord.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function assignments(): Collection
    {
        return app(AssignmentProgress::class)->pendingFor(auth()->user());
    }

    /**
     * @return array{current: ?Rank, next: ?Rank, percent: int}
     */
    #[Computed]
    public function rankProgress(): array
    {
        $xp = auth()->user()->xp;
        $current = Rank::forXp($xp);
        $next = Rank::query()->where('min_xp', '>', $xp)->orderBy('min_xp')->first();

        $floor = $current?->min_xp ?? 0;
        $percent = $next ? (int) floor(100 * ($xp - $floor) / max(1, $next->min_xp - $floor)) : 100;

        return ['current' => $current, 'next' => $next, 'percent' => $percent];
    }

    /**
     * @return Collection<int, array{level: Level, solved: int, total: int}>
     */
    #[Computed]
    public function levels(): Collection
    {
        return Level::query()
            ->orderBy('position')
            ->with(['exercises' => fn ($q) => $q->practice()->select('id', 'level_id')])
            ->get()
            ->map(fn (Level $level) => [
                'level' => $level,
                'solved' => $level->exercises->whereIn('id', $this->solvedIds)->count(),
                'total' => $level->exercises->count(),
            ]);
    }

    /**
     * @return Collection<int, array{badge: Badge, awarded_at: ?string, progress: array{0: int, 1: int}}>
     */
    #[Computed]
    public function badges(): Collection
    {
        $user = auth()->user();
        $owned = $user->badges()->get()->keyBy('id');
        $evaluator = app(BadgeEvaluator::class);

        return Badge::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->get()
            ->map(fn (Badge $badge) => [
                'badge' => $badge,
                'awarded_at' => $owned->get($badge->id)?->pivot->awarded_at,
                'progress' => $owned->has($badge->id) ? [1, 1] : $evaluator->progress($user, $badge),
            ]);
    }

    #[Computed]
    public function nextExercise(): ?Exercise
    {
        return Exercise::query()
            ->practice()
            ->whereNotIn('exercises.id', $this->solvedIds)
            ->join('levels', 'levels.id', '=', 'exercises.level_id')
            ->orderBy('levels.position')
            ->orderBy('exercises.difficulty')
            ->orderBy('exercises.position')
            ->select('exercises.*')
            ->with('level')
            ->first();
    }

    public function render(LeaderboardService $leaderboard)
    {
        $user = auth()->user();
        $submissions = $user->submissions();

        return view('livewire.learn.dashboard', [
            'user' => $user,
            'attempts' => (clone $submissions)->count(),
            'successRate' => ($total = (clone $submissions)->count()) > 0
                ? (int) round(100 * (clone $submissions)->where('is_correct', true)->count() / $total)
                : null,
            'position' => $leaderboard->positionOf($user, 'all_time'),
            'recent' => $user->submissions()->with('exercise:id,title,slug')->latest('id')->limit(6)->get(),
        ]);
    }
}
