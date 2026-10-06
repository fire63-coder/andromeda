<?php

namespace App\Livewire\Admin\Courses;

use App\Models\Course;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Cours — administration')]
class CourseIndex extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Course::class);
    }

    public function render()
    {
        return view('livewire.admin.courses.index', [
            'courses' => Course::query()
                ->with(['level', 'author:id,name', 'dialect'])
                ->withCount(['chapters', 'lessons'])
                ->join('levels', 'levels.id', '=', 'courses.level_id')
                ->orderBy('levels.position')
                ->orderBy('courses.position')
                ->select('courses.*')
                ->get(),
        ]);
    }
}
