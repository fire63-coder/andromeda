<?php

namespace App\Services\Learning;

use App\Enums\ContentStatus;
use App\Enums\ProgressStatus;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserProgress;
use App\Services\Gamification\StreakService;
use App\Services\Gamification\XpService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Progression dans les cours : leçon ouverte, leçon terminée (XP une seule fois),
 * pourcentage du cours recalculé.
 */
class CourseProgress
{
    public function __construct(
        private readonly XpService $xp,
        private readonly StreakService $streaks,
    ) {}

    /**
     * Leçons publiées du cours, dans l'ordre chapitres → leçons.
     *
     * @return Collection<int, Lesson>
     */
    public function lessons(Course $course): Collection
    {
        return Lesson::query()
            ->join('chapters', 'chapters.id', '=', 'lessons.chapter_id')
            ->where('chapters.course_id', $course->id)
            ->where('lessons.status', ContentStatus::Published)
            ->orderBy('chapters.position')
            ->orderBy('chapters.id')
            ->orderBy('lessons.position')
            ->orderBy('lessons.id')
            ->select('lessons.*')
            ->get();
    }

    public function open(User $user, Lesson $lesson): void
    {
        $progress = $this->record($user, $lesson);

        $progress->fill([
            'status' => $progress->status ?? ProgressStatus::InProgress,
            'started_at' => $progress->started_at ?? now(),
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * @return int XP gagnés (0 si la leçon était déjà terminée)
     */
    public function complete(User $user, Lesson $lesson): int
    {
        $xp = DB::transaction(function () use ($user, $lesson) {
            $progress = $this->record($user, $lesson);

            if ($progress->status === ProgressStatus::Completed) {
                return 0;
            }

            $progress->fill([
                'status' => ProgressStatus::Completed,
                'progress_percent' => 100,
                'started_at' => $progress->started_at ?? now(),
                'completed_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            $this->xp->award($user, $lesson->xp_reward, 'lesson_completed', $lesson, ['lesson' => $lesson->slug]);
            $this->streaks->touch($user);

            return $lesson->xp_reward;
        });

        $this->refreshCourse($user, $lesson->chapter->course);

        return $xp;
    }

    /**
     * @return Collection<int, int> ids des leçons terminées par l'utilisateur
     */
    public function completedLessonIds(User $user, Collection $lessons): Collection
    {
        return $user->progress()
            ->where('progressable_type', (new Lesson)->getMorphClass())
            ->whereIn('progressable_id', $lessons->pluck('id'))
            ->where('status', ProgressStatus::Completed)
            ->pluck('progressable_id');
    }

    public function percent(User $user, Course $course): int
    {
        $lessons = $this->lessons($course);

        return $lessons->isEmpty() ? 0 : (int) floor(100 * $this->completedLessonIds($user, $lessons)->count() / $lessons->count());
    }

    private function refreshCourse(User $user, Course $course): void
    {
        $percent = $this->percent($user, $course);
        $progress = UserProgress::firstOrNew([
            'user_id' => $user->id,
            'progressable_type' => $course->getMorphClass(),
            'progressable_id' => $course->id,
        ]);

        $progress->fill([
            'status' => $percent >= 100 ? ProgressStatus::Completed : ProgressStatus::InProgress,
            'progress_percent' => $percent,
            'started_at' => $progress->started_at ?? now(),
            'completed_at' => $percent >= 100 ? ($progress->completed_at ?? now()) : null,
            'last_activity_at' => now(),
        ])->save();
    }

    private function record(User $user, Lesson $lesson): UserProgress
    {
        return UserProgress::firstOrNew([
            'user_id' => $user->id,
            'progressable_type' => $lesson->getMorphClass(),
            'progressable_id' => $lesson->id,
        ]);
    }
}
