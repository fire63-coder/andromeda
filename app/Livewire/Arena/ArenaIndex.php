<?php

namespace App\Livewire\Arena;

use App\Enums\ChallengeType;
use App\Models\Challenge;
use App\Services\Challenges\DailyChallengeGenerator;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Arène')]
class ArenaIndex extends Component
{
    public function render(DailyChallengeGenerator $daily)
    {
        // Filet de sécurité si le planificateur n'a pas encore créé le défi du jour.
        $today = $daily->ensureFor();
        $user = auth()->user();
        $organizations = $user->organizations()->pluck('organizations.id');

        $others = Challenge::query()
            ->running()
            ->where('type', '!=', ChallengeType::Daily)
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhereIn('organization_id', $organizations))
            ->withCount(['exercises', 'participations'])
            ->orderBy('ends_at')
            ->get();

        $mine = $user->challengeParticipations()
            ->whereIn('challenge_id', $others->pluck('id')->push($today?->id))
            ->get()
            ->keyBy('challenge_id');

        return view('livewire.arena.index', [
            'today' => $today?->loadCount(['exercises', 'participations']),
            'others' => $others,
            'mine' => $mine,
        ]);
    }
}
