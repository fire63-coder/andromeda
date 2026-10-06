<?php

namespace App\Livewire\Admin\Exercises;

use App\Enums\ContentStatus;
use App\Models\Exercise;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Exercices — administration')]
class ExerciseIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public bool $mine = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Exercise::class);
    }

    public function updating(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.exercises.index', [
            'exercises' => Exercise::query()
                ->with(['level', 'lesson.chapter.course', 'author:id,name'])
                ->withCount('datasets')
                ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->when($this->mine, fn ($q) => $q->where('author_id', auth()->id()))
                ->orderByRaw("CASE WHEN status = 'in_review' THEN 0 ELSE 1 END")
                ->latest('updated_at')
                ->paginate(25),
            'statuses' => ContentStatus::cases(),
            'toReview' => Exercise::where('status', ContentStatus::InReview)->count(),
        ]);
    }
}
