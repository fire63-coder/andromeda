<?php

namespace App\Services\Analytics;

use App\Enums\AttemptStatus;
use App\Enums\ProgressStatus;
use App\Enums\SubmissionStatus;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Skill;
use App\Models\User;
use App\Models\UserProgress;
use App\Models\UserSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Indicateurs pédagogiques d'un groupe d'élèves (les membres d'une organisation) :
 * activité, progression individuelle, exercices qui bloquent, maîtrise des compétences.
 */
class GroupAnalytics
{
    /** @var list<int> */
    private array $userIds;

    public function __construct(private readonly Organization $organization)
    {
        $this->userIds = $organization->members()->pluck('users.id')->all();
    }

    public static function for(Organization $organization): self
    {
        return new self($organization);
    }

    /**
     * @return array{members: int, active_7d: int, submissions_7d: int, success_rate_7d: ?int, xp_total: int, solved_total: int}
     */
    public function summary(): array
    {
        $since = now()->subDays(7);
        $recent = $this->submissions()->where('created_at', '>=', $since);
        $total = (clone $recent)->count();
        $correct = (clone $recent)->where('is_correct', true)->count();

        return [
            'members' => count($this->userIds),
            'active_7d' => (clone $recent)->distinct()->count('user_id'),
            'submissions_7d' => $total,
            'success_rate_7d' => $total ? (int) round(100 * $correct / $total) : null,
            'xp_total' => (int) User::whereIn('id', $this->userIds)->sum('xp'),
            'solved_total' => $this->solvedPairs()->get()->count(),
        ];
    }

    /**
     * Soumissions par jour sur la période (jours sans activité compris).
     *
     * @return list<array{date: string, label: string, submissions: int, correct: int}>
     */
    public function activity(int $days = 30): array
    {
        $start = now()->subDays($days - 1)->startOfDay();

        $counts = $this->submissions()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total, SUM(CASE WHEN is_correct THEN 1 ELSE 0 END) AS correct')
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $counts) {
            $day = $start->copy()->addDays($offset);
            $row = $counts->get($day->toDateString());

            return [
                'date' => $day->toDateString(),
                'label' => $day->isoFormat('ddd D MMM'),
                'submissions' => (int) ($row->total ?? 0),
                'correct' => (int) ($row->correct ?? 0),
            ];
        })->all();
    }

    /**
     * Une ligne par membre : rôle dans l'organisation, XP, exercices résolus, taux de réussite, dernière activité.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function members(string $sort = 'name', string $search = ''): Collection
    {
        $solved = $this->solvedPairs()->get()->countBy('user_id');
        $stats = $this->submissions()
            ->selectRaw('user_id, COUNT(*) AS total, SUM(CASE WHEN is_correct THEN 1 ELSE 0 END) AS correct, MAX(created_at) AS last_submission')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
        $lessons = $this->completedProgress(Lesson::class)->countBy('user_id');
        $certifications = User::query()
            ->whereIn('id', $this->userIds ?: [0])
            ->withCount(['certificationAttempts as certifications_passed' => fn ($q) => $q->where('status', AttemptStatus::Passed)])
            ->pluck('certifications_passed', 'id');

        $rows = $this->organization->members()
            ->with('rank:id,name')
            ->when($search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->get()
            ->map(function (User $user) use ($solved, $stats, $lessons, $certifications) {
                $stat = $stats->get($user->id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->pivot->role,
                    'xp' => (int) $user->xp,
                    'rank' => $user->rank?->name,
                    'solved' => (int) $solved->get($user->id, 0),
                    'submissions' => (int) ($stat->total ?? 0),
                    'success_rate' => ($stat->total ?? 0) ? (int) round(100 * $stat->correct / $stat->total) : null,
                    'lessons' => (int) $lessons->get($user->id, 0),
                    'certifications' => (int) $certifications->get($user->id, 0),
                    'last_activity' => isset($stat->last_submission) ? Carbon::parse($stat->last_submission) : null,
                ];
            });

        return (match ($sort) {
            'xp' => $rows->sortByDesc('xp'),
            'solved' => $rows->sortByDesc('solved'),
            'activity' => $rows->sortByDesc(fn ($row) => $row['last_activity']?->timestamp ?? 0),
            'struggling' => $rows->sortBy(fn ($row) => [$row['success_rate'] ?? 101, -$row['submissions']]),
            default => $rows->sortBy(fn ($row) => Str::lower(Str::ascii($row['name']))),
        })->values();
    }

    /**
     * Exercices où le groupe bloque : beaucoup d'élèves ont essayé, peu ont réussi.
     * Pour chacun, les messages d'erreur ou de correction les plus fréquents.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function struggles(int $limit = 8): Collection
    {
        $rows = $this->submissions()
            ->selectRaw('exercise_id, COUNT(*) AS attempts, COUNT(DISTINCT user_id) AS tried')
            ->groupBy('exercise_id')
            ->get()
            ->keyBy('exercise_id');

        if ($rows->isEmpty()) {
            return collect();
        }

        $solvedBy = $this->solvedPairs()->get()->countBy('exercise_id');
        $exercises = Exercise::with('level:id,position')->whereIn('id', $rows->keys())->get()->keyBy('id');

        $ranked = $rows->map(fn ($row) => [
            'exercise' => $exercises->get($row->exercise_id),
            'attempts' => (int) $row->attempts,
            'tried' => (int) $row->tried,
            'solved' => (int) $solvedBy->get($row->exercise_id, 0),
        ])
            ->filter(fn ($row) => $row['exercise'] && $row['solved'] < $row['tried'])
            ->sortBy(fn ($row) => [$row['solved'] / $row['tried'], -$row['attempts']])
            ->take($limit)
            ->values();

        // Messages les plus fréquents parmi les échecs récents (regroupés sur leur début, sans les données).
        $failures = $this->submissions()
            ->whereIn('exercise_id', $ranked->pluck('exercise.id'))
            ->whereIn('status', [SubmissionStatus::Wrong, SubmissionStatus::Error, SubmissionStatus::Timeout, SubmissionStatus::Rejected])
            ->latest('id')
            ->limit(500)
            ->get(['exercise_id', 'status', 'error_message', 'feedback'])
            ->groupBy('exercise_id');

        return $ranked->map(fn (array $row) => [
            ...$row,
            'rate' => (int) round(100 * $row['solved'] / $row['tried']),
            'top_errors' => ($failures->get($row['exercise']->id) ?? collect())
                ->map(fn (UserSubmission $submission) => Str::limit(trim((string) ($submission->error_message ?? ($submission->feedback['message'] ?? $submission->status->label()))), 140))
                ->countBy()
                ->sortDesc()
                ->take(3)
                ->all(),
        ]);
    }

    /**
     * Maîtrise par compétence : part des couples (élève, exercice d'entraînement publié) résolus.
     *
     * @return Collection<int, array{skill: Skill, exercises: int, mastery: int}>
     */
    public function skills(): Collection
    {
        $members = count($this->userIds);

        if ($members === 0) {
            return collect();
        }

        $solved = $this->solvedPairs()->get()->groupBy('exercise_id')->map->count();

        return Skill::query()
            ->with(['exercises' => fn ($q) => $q->practice()->select('exercises.id')])
            ->get()
            ->filter(fn (Skill $skill) => $skill->exercises->isNotEmpty())
            ->map(fn (Skill $skill) => [
                'skill' => $skill,
                'exercises' => $skill->exercises->count(),
                'mastery' => (int) round(100 * $skill->exercises->sum(fn (Exercise $e) => $solved->get($e->id, 0)) / ($members * $skill->exercises->count())),
            ])
            ->sortBy('mastery')
            ->values();
    }

    /**
     * @return Builder<UserSubmission>
     */
    private function submissions(): Builder
    {
        return UserSubmission::query()->whereIn('user_id', $this->userIds ?: [0]);
    }

    /**
     * Couples distincts (élève, exercice) résolus.
     *
     * @return Builder<UserSubmission>
     */
    private function solvedPairs(): Builder
    {
        return $this->submissions()->where('is_correct', true)->select('user_id', 'exercise_id')->distinct();
    }

    /**
     * @param  class-string  $type
     * @return Collection<int, UserProgress>
     */
    private function completedProgress(string $type): Collection
    {
        return UserProgress::query()
            ->whereIn('user_id', $this->userIds ?: [0])
            ->where('progressable_type', (new $type)->getMorphClass())
            ->where('status', ProgressStatus::Completed)
            ->get(['user_id']);
    }
}
