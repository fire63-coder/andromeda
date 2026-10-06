<?php

namespace App\Services\Gamification;

use App\Enums\AttemptStatus;
use App\Enums\ExerciseType;
use App\Models\Badge;
use App\Models\User;
use App\Models\UserSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Évalue les règles déclaratives des badges (badges.criteria) et attribue
 * ceux que l'utilisateur vient de mériter, avec leur bonus d'XP.
 *
 * Ajouter un type de règle = ajouter une branche à progress().
 */
class BadgeEvaluator
{
    public function __construct(private readonly XpService $xp) {}

    /**
     * @return Collection<int, Badge> badges nouvellement obtenus
     */
    public function evaluate(User $user): Collection
    {
        $owned = $user->badges()->pluck('badges.id');

        $unlocked = Badge::query()
            ->where('is_active', true)
            ->whereNotIn('id', $owned)
            ->orderBy('position')
            ->get()
            ->filter(fn (Badge $badge) => $this->isEarned($user, $badge));

        foreach ($unlocked as $badge) {
            DB::transaction(function () use ($user, $badge) {
                $user->badges()->attach($badge->id, ['awarded_at' => now(), 'context' => json_encode($badge->criteria)]);
                $this->xp->award($user, $badge->xp_bonus, 'badge_unlocked', $badge, ['badge' => $badge->slug]);
            });
        }

        return $unlocked->values();
    }

    public function isEarned(User $user, Badge $badge): bool
    {
        [$current, $target] = $this->progress($user, $badge);

        return $target > 0 && $current >= $target;
    }

    /**
     * Avancement vers un badge : [valeur actuelle, objectif]. Sert aussi à l'affichage.
     *
     * @return array{0: int, 1: int}
     */
    public function progress(User $user, Badge $badge): array
    {
        $criteria = $badge->criteria;
        $target = (int) ($criteria['count'] ?? $criteria['days'] ?? 1);

        $current = match ($criteria['type'] ?? null) {
            'exercises_solved' => $this->solved($user)->count(DB::raw('DISTINCT exercise_id')),
            'skill_exercises_solved' => $this->solved($user)
                ->whereHas('exercise.skills', fn (Builder $q) => $q->where('slug', $criteria['skill'] ?? null))
                ->count(DB::raw('DISTINCT exercise_id')),
            'exercise_type_solved' => $this->solved($user)
                ->whereHas('exercise', fn (Builder $q) => $q->where('type', $criteria['exercise_type'] ?? null))
                ->count(DB::raw('DISTINCT exercise_id')),
            'distinct_dialects_solved' => $this->solved($user)
                ->whereHas('exercise', fn (Builder $q) => $q->where('type', '!=', ExerciseType::MultipleChoice))
                ->count(DB::raw('DISTINCT sql_dialect_id')),
            'first_try_streak' => $this->firstTryStreak($user),
            'daily_streak' => $user->longest_streak,
            'certification_passed' => $user->certificationAttempts()
                ->where('status', AttemptStatus::Passed)
                ->whereHas('certification.level', fn (Builder $q) => $q->where('position', '>=', (int) ($criteria['level'] ?? 1)))
                ->exists() ? 1 : 0,
            default => 0,
        };

        if (($criteria['type'] ?? null) === 'certification_passed') {
            $target = 1;
        }

        return [(int) $current, $target];
    }

    /**
     * @return HasMany<UserSubmission, User>
     */
    private function solved(User $user)
    {
        return $user->submissions()->where('is_correct', true);
    }

    /**
     * Nombre d'exercices consécutifs (les plus récents d'abord) réussis dès la première tentative.
     */
    private function firstTryStreak(User $user): int
    {
        $firstAttempts = $user->submissions()
            ->whereIn('id', fn ($query) => $query->selectRaw('MIN(id)')
                ->from('user_submissions')
                ->where('user_id', $user->id)
                ->groupBy('exercise_id'))
            ->orderByDesc('id')
            ->pluck('is_correct');

        $streak = 0;
        foreach ($firstAttempts as $correct) {
            if (! $correct) {
                break;
            }
            $streak++;
        }

        return $streak;
    }
}
