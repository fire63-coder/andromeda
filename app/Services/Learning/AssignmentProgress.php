<?php

namespace App\Services\Learning;

use App\Enums\ProgressStatus;
use App\Models\Assignment;
use App\Models\Exercise;
use App\Models\User;
use App\Models\UserProgress;
use App\Models\UserSubmission;
use Illuminate\Support\Collection;

/**
 * Avancement d'un devoir : pour chaque élève et chaque exercice, résolu à temps, en retard,
 * essayé sans succès ou pas commencé. Un exercice déjà résolu avant le devoir compte comme fait.
 */
class AssignmentProgress
{
    public const ON_TIME = 'on_time';

    public const LATE = 'late';

    public const TRIED = 'tried';

    public const TODO = 'todo';

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<int, string>> [user_id][exercise_id] => statut
     */
    public function matrix(Assignment $assignment, array $userIds): array
    {
        $exerciseIds = $assignment->exercises->pluck('id')->all();

        $completed = UserProgress::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->where('progressable_type', (new Exercise)->getMorphClass())
            ->whereIn('progressable_id', $exerciseIds ?: [0])
            ->where('status', ProgressStatus::Completed)
            ->get(['user_id', 'progressable_id', 'completed_at'])
            ->groupBy('user_id');

        $tried = UserSubmission::query()
            ->whereIn('user_id', $userIds ?: [0])
            ->whereIn('exercise_id', $exerciseIds ?: [0])
            ->select('user_id', 'exercise_id')
            ->distinct()
            ->get()
            ->groupBy('user_id');

        $matrix = [];

        foreach ($userIds as $userId) {
            $done = ($completed->get($userId) ?? collect())->keyBy('progressable_id');
            $attempted = ($tried->get($userId) ?? collect())->pluck('exercise_id')->flip();

            foreach ($exerciseIds as $exerciseId) {
                $progress = $done->get($exerciseId);

                $matrix[$userId][$exerciseId] = match (true) {
                    $progress !== null => $assignment->due_at && $progress->completed_at?->gt($assignment->due_at) ? self::LATE : self::ON_TIME,
                    $attempted->has($exerciseId) => self::TRIED,
                    default => self::TODO,
                };
            }
        }

        return $matrix;
    }

    /**
     * @return array{done: int, total: int, percent: int, statuses: array<int, string>}
     */
    public function forUser(Assignment $assignment, User $user): array
    {
        $statuses = $this->matrix($assignment, [$user->id])[$user->id] ?? [];
        $done = count(array_filter($statuses, fn (string $status) => in_array($status, [self::ON_TIME, self::LATE], true)));
        $total = count($statuses);

        return ['done' => $done, 'total' => $total, 'percent' => $total ? (int) round(100 * $done / $total) : 0, 'statuses' => $statuses];
    }

    /**
     * Devoirs publiés de l'élève, les plus urgents d'abord (échéance la plus proche, puis sans échéance).
     *
     * @return Collection<int, array{assignment: Assignment, done: int, total: int, percent: int}>
     */
    public function pendingFor(User $user, int $limit = 5): Collection
    {
        return Assignment::query()
            ->for($user)
            ->with(['exercises:id', 'organization:id,name'])
            ->get()
            ->map(fn (Assignment $assignment) => ['assignment' => $assignment, ...$this->forUser($assignment, $user)])
            ->filter(fn (array $row) => $row['done'] < $row['total'])
            ->sortBy(fn (array $row) => $row['assignment']->due_at?->timestamp ?? PHP_INT_MAX)
            ->take($limit)
            ->values();
    }
}
