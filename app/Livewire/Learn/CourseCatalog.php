<?php

namespace App\Livewire\Learn;

use App\Models\Course;
use App\Models\Level;
use App\Services\Learning\CourseProgress;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Cours')]
class CourseCatalog extends Component
{
    public function render(CourseProgress $progress)
    {
        $user = auth()->user();

        $levels = Level::query()
            ->orderBy('position')
            ->with(['courses' => fn ($q) => $q->published()->with('dialect')->withCount('chapters')])
            ->get()
            ->filter(fn (Level $level) => $level->courses->isNotEmpty())
            ->map(fn (Level $level) => [
                'level' => $level,
                'courses' => $level->courses->map(fn (Course $course) => [
                    'course' => $course,
                    'lessons' => $progress->lessons($course)->count(),
                    'percent' => $progress->percent($user, $course),
                ]),
            ]);

        return view('livewire.learn.course-catalog', ['levels' => $levels]);
    }
}
