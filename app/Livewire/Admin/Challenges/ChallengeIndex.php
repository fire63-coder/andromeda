<?php

namespace App\Livewire\Admin\Challenges;

use App\Enums\ChallengeType;
use App\Models\Challenge;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Défis — administration')]
class ChallengeIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $type = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Challenge::class);
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.admin.challenges.index', [
            'types' => ChallengeType::options(),
            'challenges' => Challenge::query()
                ->with('organization:id,name')
                ->withCount(['exercises', 'participations', 'participations as finishers_count' => fn ($q) => $q->where('score', '>', 0)])
                ->withMax('participations as best_score', 'score')
                ->when($this->type, fn ($q) => $q->where('type', $this->type))
                ->orderByDesc('starts_at')
                ->paginate(20),
        ]);
    }
}
