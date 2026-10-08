<?php

namespace App\Livewire\Admin\Certifications;

use App\Enums\ContentStatus;
use App\Models\Certification;
use App\Models\Exercise;
use App\Models\Level;
use App\Models\SqlDialect;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Éditeur de certification')]
class CertificationEditor extends Component
{
    #[Locked]
    public ?Certification $certification = null;

    public string $title = '';

    public string $slug = '';

    public string $description = '';

    public ?int $levelId = null;

    public ?int $dialectId = null;

    public int $passingScore = 70;

    public int $durationMinutes = 30;

    public int $exercisesCount = 10;

    public ?int $maxAttempts = 3;

    public int $cooldownHours = 24;

    public int $xpReward = 200;

    public string $status = 'draft';

    public bool $examMode = false;

    public ?int $maxIncidents = 3;

    /** @var list<int> */
    public array $pool = [];

    public string $search = '';

    public ?string $saved = null;

    public function mount(?Certification $certification = null): void
    {
        if (! $certification?->exists) {
            $this->authorize('create', Certification::class);
            $this->levelId = Level::orderBy('position')->value('id');

            return;
        }

        $this->authorize('update', $certification);
        $this->certification = $certification;
        $this->fill([
            'title' => $certification->title,
            'slug' => $certification->slug,
            'description' => (string) $certification->description,
            'levelId' => $certification->level_id,
            'dialectId' => $certification->sql_dialect_id,
            'passingScore' => $certification->passing_score,
            'durationMinutes' => $certification->duration_minutes,
            'exercisesCount' => $certification->exercises_count,
            'maxAttempts' => $certification->max_attempts,
            'cooldownHours' => $certification->cooldown_hours,
            'xpReward' => $certification->xp_reward,
            'status' => $certification->status->value,
            'examMode' => $certification->exam_mode,
            'maxIncidents' => $certification->max_incidents,
            'pool' => $certification->exercisePool()->pluck('exercises.id')->all(),
        ]);
    }

    public function updatedTitle(): void
    {
        if (! $this->certification) {
            $this->slug = Str::slug($this->title);
        }
    }

    public function save(): void
    {
        $this->authorize($this->certification ? 'update' : 'create', $this->certification ?? Certification::class);

        $data = $this->withValidator(function (Validator $validator) {
            $validator->after(function (Validator $validator) {
                $published = Exercise::published()->whereIn('id', $this->pool)->count();

                if ($this->status === ContentStatus::Published->value && $published < $this->exercisesCount) {
                    $validator->errors()->add('pool', "Le pool ne contient que {$published} exercice(s) publié(s) pour {$this->exercisesCount} question(s) par sujet.");
                }
            });
        })->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('certifications', 'slug')->ignore($this->certification?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'levelId' => ['required', Rule::exists('levels', 'id')],
            'dialectId' => ['nullable', Rule::exists('sql_dialects', 'id')],
            'passingScore' => ['required', 'integer', 'between:1,100'],
            'durationMinutes' => ['required', 'integer', 'between:5,480'],
            'exercisesCount' => ['required', 'integer', 'between:1,100'],
            'maxAttempts' => ['nullable', 'integer', 'between:1,50'],
            'cooldownHours' => ['required', 'integer', 'between:0,2160'],
            'xpReward' => ['required', 'integer', 'between:0,5000'],
            'status' => ['required', Rule::enum(ContentStatus::class)],
            'examMode' => ['boolean'],
            'maxIncidents' => ['nullable', 'integer', 'between:0,50'],
            'pool.*' => [Rule::exists('exercises', 'id')],
        ], attributes: ['title' => 'titre', 'slug' => 'identifiant', 'exercisesCount' => 'nombre de questions', 'maxIncidents' => 'incidents tolérés']);

        $attributes = [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?: null,
            'level_id' => $data['levelId'],
            'sql_dialect_id' => $data['dialectId'],
            'passing_score' => $data['passingScore'],
            'duration_minutes' => $data['durationMinutes'],
            'exercises_count' => $data['exercisesCount'],
            'max_attempts' => $data['maxAttempts'],
            'cooldown_hours' => $data['cooldownHours'],
            'xp_reward' => $data['xpReward'],
            'status' => $data['status'],
            'exam_mode' => $data['examMode'],
            'max_incidents' => $data['examMode'] ? $data['maxIncidents'] : null,
        ];

        $created = ! $this->certification;
        $this->certification = $this->certification
            ? tap($this->certification)->update($attributes)
            : Certification::create($attributes);
        $this->certification->exercisePool()->sync($this->pool);

        if ($created) {
            $this->redirectRoute('admin.certifications.edit', $this->certification, navigate: true);

            return;
        }

        $this->saved = 'Certification enregistrée.';
    }

    public function render()
    {
        return view('livewire.admin.certifications.editor', [
            'levels' => Level::orderBy('position')->get(),
            'dialects' => SqlDialect::orderBy('position')->get(),
            'statuses' => ContentStatus::cases(),
            'exercises' => Exercise::query()
                ->with('level')
                ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
                ->orderByRaw('CASE WHEN lesson_id IS NULL THEN 0 ELSE 1 END')
                ->orderBy('level_id')
                ->orderBy('title')
                ->get(),
        ]);
    }
}
