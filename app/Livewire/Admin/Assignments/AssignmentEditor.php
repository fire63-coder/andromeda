<?php

namespace App\Livewire\Admin\Assignments;

use App\Models\Assignment;
use App\Models\Exercise;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Devoir')]
class AssignmentEditor extends Component
{
    #[Locked]
    public Organization $organization;

    #[Locked]
    public ?Assignment $assignment = null;

    public string $title = '';

    public string $instructions = '';

    public string $dueAt = '';

    public bool $published = false;

    /** @var list<int> exercices, dans l'ordre */
    public array $exerciseIds = [];

    public string $search = '';

    public ?string $saved = null;

    public function mount(Organization $organization, ?Assignment $assignment = null): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;

        if (! $assignment?->exists) {
            $this->dueAt = now()->addWeek()->setTime(23, 59)->format('Y-m-d\TH:i');

            return;
        }

        abort_unless($assignment->organization_id === $organization->id, 404);
        $this->assignment = $assignment;
        $this->fill([
            'title' => $assignment->title,
            'instructions' => (string) $assignment->instructions,
            'dueAt' => $assignment->due_at?->format('Y-m-d\TH:i') ?? '',
            'published' => $assignment->isPublished(),
            'exerciseIds' => $assignment->exercises->pluck('id')->all(),
        ]);
    }

    public function add(int $exerciseId): void
    {
        if (! in_array($exerciseId, $this->exerciseIds, true) && Exercise::practice()->whereKey($exerciseId)->exists()) {
            $this->exerciseIds[] = $exerciseId;
        }
    }

    public function remove(int $index): void
    {
        unset($this->exerciseIds[$index]);
        $this->exerciseIds = array_values($this->exerciseIds);
    }

    public function move(int $index, int $offset): void
    {
        $target = $index + $offset;

        if (isset($this->exerciseIds[$index], $this->exerciseIds[$target])) {
            [$this->exerciseIds[$index], $this->exerciseIds[$target]] = [$this->exerciseIds[$target], $this->exerciseIds[$index]];
        }
    }

    public function save(): void
    {
        $this->authorize('update', $this->organization);

        $data = $this->withValidator(function (Validator $validator) {
            $validator->after(function (Validator $validator) {
                if ($this->published && $this->exerciseIds === []) {
                    $validator->errors()->add('exerciseIds', 'Ajoutez au moins un exercice avant de publier le devoir.');
                }
            });
        })->validate([
            'title' => ['required', 'string', 'max:160'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'dueAt' => ['nullable', 'date'],
            'published' => ['boolean'],
            'exerciseIds' => ['array', 'max:50'],
            // Exercices d'entraînement publiés uniquement : jamais ceux réservés aux certifications.
            'exerciseIds.*' => ['distinct', Rule::exists('exercises', 'id')->whereNotNull('lesson_id')->where('status', 'published')],
        ], attributes: ['title' => 'titre', 'dueAt' => 'échéance', 'exerciseIds.*' => 'exercice']);

        $attributes = [
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?: null,
            'due_at' => $data['dueAt'] ? Carbon::parse($data['dueAt']) : null,
            // La date de publication d'origine est conservée lors des modifications.
            'published_at' => $this->published ? ($this->assignment?->published_at ?? now()) : null,
        ];

        $created = ! $this->assignment;
        $this->assignment = $this->assignment
            ? tap($this->assignment)->update($attributes)
            : $this->organization->assignments()->create($attributes + ['created_by' => auth()->id()]);

        $this->assignment->exercises()->sync(collect($this->exerciseIds)->values()->mapWithKeys(fn (int $id, int $position) => [$id => ['position' => $position]])->all());

        if ($created) {
            $this->redirectRoute('admin.assignments.edit', [$this->organization, $this->assignment], navigate: true);

            return;
        }

        $this->saved = 'Devoir enregistré.';
    }

    public function delete(): void
    {
        $this->authorize('update', $this->organization);
        $this->assignment?->delete();
        $this->redirectRoute('admin.organizations.show', $this->organization, navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.assignments.editor', [
            'selected' => Exercise::with('level:id,position')->whereIn('id', $this->exerciseIds)->get()->keyBy('id'),
            'candidates' => Exercise::practice()
                ->with(['level:id,position', 'lesson.chapter.course:id,title'])
                ->whereNotIn('id', $this->exerciseIds)
                ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
                ->orderBy('level_id')
                ->orderBy('title')
                ->limit(40)
                ->get(),
        ]);
    }
}
