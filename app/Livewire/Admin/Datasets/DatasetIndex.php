<?php

namespace App\Livewire\Admin\Datasets;

use App\Models\Dataset;
use App\Models\SqlDialect;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Jeux de données')]
class DatasetIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Dataset::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.datasets.index', [
            'dialects' => SqlDialect::query()->orderBy('position')->get()->keyBy('id'),
            'datasets' => Dataset::query()
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('domain', 'like', "%{$this->search}%")))
                ->with(['builds:id,dataset_id,sql_dialect_id,status', 'creator:id,name'])
                ->withCount('exercises')
                ->latest()
                ->paginate(20),
        ]);
    }
}
