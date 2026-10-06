<?php

namespace App\Livewire\Admin\Datasets;

use App\Enums\DatasetRole;
use App\Enums\DatasetStatus;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Fiche d'un jeu de données : état de l'import et des builds, schéma, aperçu des données,
 * exercices liés (jeu visible ou jeu de test caché).
 */
#[Title('Jeu de données')]
class DatasetShow extends Component
{
    #[Locked]
    public Dataset $dataset;

    public ?int $exerciseId = null;

    public string $role = 'primary';

    /** Table dont on affiche l'aperçu. */
    public ?string $previewTable = null;

    public function mount(Dataset $dataset): void
    {
        $this->authorize('view', $dataset);
        $this->dataset = $dataset;
        $this->previewTable = $dataset->tables_meta[0]['name'] ?? null;
    }

    /** Tant que l'import tourne, la vue se rafraîchit (wire:poll). */
    #[Computed]
    public function processing(): bool
    {
        return $this->dataset->fresh()->status === DatasetStatus::Processing
            || $this->dataset->imports()->whereIn('status', [DatasetStatus::Pending, DatasetStatus::Processing])->exists();
    }

    /**
     * @return Collection<int, Exercise>
     */
    #[Computed]
    public function availableExercises(): Collection
    {
        return Exercise::query()
            ->whereNotIn('id', $this->dataset->exercises()->select('exercises.id'))
            ->orderBy('title')
            ->get(['id', 'title', 'type']);
    }

    /**
     * Aperçu des 10 premières lignes, lu dans la sandbox SQLite (jamais dans la base applicative).
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function preview(): ?array
    {
        $table = collect($this->dataset->tables_meta ?? [])->firstWhere('name', $this->previewTable);
        $sqlite = SqlDialect::where('slug', 'sqlite')->first();

        if (! $table || ! $sqlite || $this->dataset->status !== DatasetStatus::Ready) {
            return null;
        }

        return app(SandboxManager::class)
            ->run($this->dataset, $sqlite, 'SELECT * FROM "'.str_replace('"', '""', $table['name']).'" LIMIT 10')
            ->toPreview(10);
    }

    public function attachExercise(): void
    {
        $this->authorize('update', $this->dataset);

        $this->validate([
            'exerciseId' => ['required', Rule::exists('exercises', 'id')],
            'role' => ['required', Rule::enum(DatasetRole::class)],
        ]);

        $exercise = Exercise::findOrFail($this->exerciseId);

        if ($this->role === DatasetRole::Primary->value && $exercise->datasets()->wherePivot('role', DatasetRole::Primary->value)->exists()) {
            $this->addError('exerciseId', 'Cet exercice a déjà un jeu visible : ajoutez celui-ci comme jeu de test caché.');

            return;
        }

        $this->dataset->exercises()->attach($exercise->id, [
            'role' => $this->role,
            'position' => $this->role === DatasetRole::Primary->value ? 0 : $exercise->datasets()->count() + 1,
        ]);

        $this->reset('exerciseId');
        unset($this->availableExercises);
    }

    public function detachExercise(int $exerciseId): void
    {
        $this->authorize('update', $this->dataset);

        $this->dataset->exercises()->detach($exerciseId);
        unset($this->availableExercises);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->dataset);

        if ($this->dataset->exercises()->exists()) {
            $this->addError('delete', 'Déliez d\'abord les exercices qui utilisent ce jeu de données.');

            return;
        }

        $this->dataset->delete();
        $this->redirectRoute('admin.datasets.index', navigate: true);
    }

    public function render()
    {
        $this->dataset->refresh();

        return view('livewire.admin.datasets.show', [
            'builds' => $this->dataset->builds()->with('dialect')->get()->sortBy('dialect.position'),
            'imports' => $this->dataset->imports()->with('user:id,name')->latest('id')->limit(5)->get(),
            'exercises' => $this->dataset->exercises()->orderBy('title')->get(),
            'roles' => [DatasetRole::Primary->value => DatasetRole::Primary->label(), DatasetRole::HiddenTest->value => DatasetRole::HiddenTest->label()],
        ]);
    }
}
