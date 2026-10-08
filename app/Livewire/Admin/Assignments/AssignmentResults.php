<?php

namespace App\Livewire\Admin\Assignments;

use App\Models\Assignment;
use App\Models\Organization;
use App\Services\Learning\AssignmentProgress;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Qui a rendu quoi : une ligne par élève, une colonne par exercice.
 */
#[Title('Résultats du devoir')]
class AssignmentResults extends Component
{
    #[Locked]
    public Organization $organization;

    #[Locked]
    public Assignment $assignment;

    public function mount(Organization $organization, Assignment $assignment): void
    {
        $this->authorize('update', $organization);
        abort_unless($assignment->organization_id === $organization->id, 404);

        $this->organization = $organization;
        $this->assignment = $assignment->load('exercises:id,title,slug');
    }

    public function render(AssignmentProgress $progress)
    {
        $students = $this->organization->members()->wherePivot('role', 'member')->orderBy('name')->get(['users.id', 'users.name']);
        $matrix = $progress->matrix($this->assignment, $students->pluck('id')->all());
        $exerciseCount = $this->assignment->exercises->count();

        $rows = $students->map(function ($student) use ($matrix, $exerciseCount) {
            $statuses = $matrix[$student->id] ?? [];
            $done = count(array_filter($statuses, fn ($s) => in_array($s, [AssignmentProgress::ON_TIME, AssignmentProgress::LATE], true)));

            return ['student' => $student, 'statuses' => $statuses, 'done' => $done, 'complete' => $exerciseCount > 0 && $done === $exerciseCount];
        });

        return view('livewire.admin.assignments.results', [
            'rows' => $rows,
            'completed' => $rows->where('complete', true)->count(),
            'perExercise' => $this->assignment->exercises->mapWithKeys(fn ($exercise) => [
                $exercise->id => $rows->filter(fn ($row) => in_array($row['statuses'][$exercise->id] ?? null, [AssignmentProgress::ON_TIME, AssignmentProgress::LATE], true))->count(),
            ]),
        ]);
    }
}
