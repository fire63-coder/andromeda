<?php

namespace App\Livewire\Learn;

use App\Enums\ContentStatus;
use App\Models\Course;
use App\Services\Learning\CourseProgress;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Cours')]
class CourseShow extends Component
{
    #[Locked]
    public Course $course;

    public function mount(Course $course): void
    {
        abort_unless($course->status === ContentStatus::Published || auth()->user()->canAuthorContent(), 404);
        $this->course = $course;
    }

    public function render(CourseProgress $progress)
    {
        $lessons = $progress->lessons($this->course);
        $completed = $progress->completedLessonIds(auth()->user(), $lessons)->flip();

        return view('livewire.learn.course-show', [
            'chapters' => $this->course->chapters()->get()->map(fn ($chapter) => [
                'chapter' => $chapter,
                'lessons' => $lessons->where('chapter_id', $chapter->id)->values(),
            ])->filter(fn (array $row) => $row['lessons']->isNotEmpty()),
            'completed' => $completed,
            'percent' => $lessons->isEmpty() ? 0 : (int) floor(100 * $completed->count() / $lessons->count()),
            'next' => $lessons->first(fn ($lesson) => ! $completed->has($lesson->id)),
        ]);
    }
}
