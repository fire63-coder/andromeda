<?php

namespace App\Livewire\Admin\Challenges;

use App\Enums\ChallengeType;
use App\Enums\ContentStatus;
use App\Models\Challenge;
use App\Models\Exercise;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Éditeur de défi')]
class ChallengeEditor extends Component
{
    #[Locked]
    public ?Challenge $challenge = null;

    public string $type = 'arena';

    public string $title = '';

    public string $slug = '';

    public string $description = '';

    public ?int $organizationId = null;

    public string $startsAt = '';

    public string $endsAt = '';

    public ?int $durationMinutes = null;

    public string $xpMultiplier = '1.00';

    public string $status = 'draft';

    /** Exercices du défi, dans l'ordre : [['id' => int, 'points' => int], ...]. */
    public array $items = [];

    public string $search = '';

    public ?string $saved = null;

    public function mount(?Challenge $challenge = null): void
    {
        if (! $challenge?->exists) {
            $this->authorize('create', Challenge::class);
            $this->startsAt = now()->startOfHour()->addHour()->format('Y-m-d\TH:i');

            return;
        }

        $this->authorize('update', $challenge);
        $this->challenge = $challenge;
        $this->fill([
            'type' => $challenge->type->value,
            'title' => $challenge->title,
            'slug' => $challenge->slug,
            'description' => (string) $challenge->description,
            'organizationId' => $challenge->organization_id,
            'startsAt' => $challenge->starts_at->format('Y-m-d\TH:i'),
            'endsAt' => $challenge->ends_at?->format('Y-m-d\TH:i') ?? '',
            'durationMinutes' => $challenge->duration_seconds ? intdiv($challenge->duration_seconds, 60) : null,
            'xpMultiplier' => number_format((float) $challenge->xp_multiplier, 2, '.', ''),
            'status' => $challenge->status->value,
            'items' => $challenge->exercises
                ->map(fn (Exercise $exercise) => ['id' => $exercise->id, 'points' => (int) $exercise->pivot->points])
                ->all(),
        ]);
    }

    public function updatedTitle(): void
    {
        if (! $this->challenge) {
            $this->slug = Str::slug($this->title);
        }
    }

    public function addExercise(int $exerciseId): void
    {
        if (collect($this->items)->contains('id', $exerciseId) || ! Exercise::whereKey($exerciseId)->exists()) {
            return;
        }

        $this->items[] = ['id' => $exerciseId, 'points' => 100];
    }

    public function removeExercise(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function moveExercise(int $index, int $offset): void
    {
        $target = $index + $offset;

        if (! isset($this->items[$index], $this->items[$target])) {
            return;
        }

        [$this->items[$index], $this->items[$target]] = [$this->items[$target], $this->items[$index]];
    }

    public function save(): void
    {
        $this->authorize($this->challenge ? 'update' : 'create', $this->challenge ?? Challenge::class);

        $data = $this->withValidator(function (Validator $validator) {
            $validator->after(function (Validator $validator) {
                if ($this->status !== ContentStatus::Published->value) {
                    return;
                }

                $ids = collect($this->items)->pluck('id');
                if ($ids->isEmpty()) {
                    $validator->errors()->add('items', 'Un défi publié doit contenir au moins un exercice.');
                } elseif (Exercise::published()->whereIn('id', $ids)->count() < $ids->count()) {
                    $validator->errors()->add('items', 'Tous les exercices d\'un défi publié doivent eux-mêmes être publiés.');
                }
            });
        })->validate([
            'type' => ['required', Rule::enum(ChallengeType::class)],
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('challenges', 'slug')->ignore($this->challenge?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'organizationId' => ['nullable', Rule::exists('organizations', 'id')],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['nullable', 'date', 'after:startsAt'],
            'durationMinutes' => ['nullable', 'integer', 'between:1,1440'],
            'xpMultiplier' => ['required', 'numeric', 'between:0,10'],
            'status' => ['required', Rule::enum(ContentStatus::class)],
            'items' => ['array', 'max:50'],
            'items.*.id' => ['required', 'distinct', Rule::exists('exercises', 'id')],
            'items.*.points' => ['required', 'integer', 'between:1,10000'],
        ], attributes: [
            'title' => 'titre', 'slug' => 'identifiant', 'startsAt' => 'début', 'endsAt' => 'fin',
            'durationMinutes' => 'durée', 'xpMultiplier' => 'multiplicateur d\'XP', 'items.*.points' => 'points',
        ]);

        $attributes = [
            'type' => $data['type'],
            'title' => $data['title'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?: null,
            'organization_id' => $data['organizationId'],
            'starts_at' => Carbon::parse($data['startsAt']),
            'ends_at' => $data['endsAt'] ? Carbon::parse($data['endsAt']) : null,
            'duration_seconds' => $data['durationMinutes'] ? $data['durationMinutes'] * 60 : null,
            'xp_multiplier' => $data['xpMultiplier'],
            'status' => $data['status'],
        ];

        $created = ! $this->challenge;
        $this->challenge = $this->challenge
            ? tap($this->challenge)->update($attributes)
            : Challenge::create($attributes + ['created_by' => auth()->id()]);

        $this->challenge->exercises()->sync(collect($this->items)->values()->mapWithKeys(fn (array $item, int $position) => [
            $item['id'] => ['points' => $item['points'], 'position' => $position],
        ])->all());

        if ($created) {
            $this->redirectRoute('admin.challenges.edit', $this->challenge, navigate: true);

            return;
        }

        $this->saved = 'Défi enregistré.';
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->challenge);
        $this->challenge->delete();

        $this->redirectRoute('admin.challenges.index', navigate: true);
    }

    public function render()
    {
        $selected = collect($this->items)->pluck('id');
        $exercises = Exercise::with('level')->whereIn('id', $selected)->get()->keyBy('id');

        return view('livewire.admin.challenges.editor', [
            'types' => ChallengeType::options(),
            'statuses' => ContentStatus::cases(),
            'organizations' => Organization::orderBy('name')->get(['id', 'name']),
            'selected' => $exercises,
            'candidates' => Exercise::query()
                ->with('level')
                ->whereNotIn('id', $selected)
                ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
                ->orderByRaw('CASE WHEN lesson_id IS NULL THEN 0 ELSE 1 END')
                ->orderBy('level_id')
                ->orderBy('title')
                ->limit(50)
                ->get(),
            'standings' => $this->challenge?->standings(10) ?? collect(),
            'stats' => $this->challenge ? [
                'participants' => $this->challenge->participations()->count(),
                'scored' => $this->challenge->participations()->where('score', '>', 0)->count(),
                'average' => $this->challenge->participations()->where('score', '>', 0)->avg('score'),
            ] : null,
        ]);
    }
}
