<?php

namespace App\Livewire\Learn;

use App\Models\Assignment;
use App\Services\Learning\AssignmentProgress;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Devoir')]
class AssignmentShow extends Component
{
    public Assignment $assignment;

    public function mount(Assignment $assignment): void
    {
        $this->authorize('view', $assignment);
        $this->assignment = $assignment->load(['exercises.level:id,position', 'organization:id,name']);
    }

    public function render(AssignmentProgress $progress)
    {
        return view('livewire.learn.assignment', [
            'progress' => $progress->forUser($this->assignment, auth()->user()),
        ]);
    }
}
