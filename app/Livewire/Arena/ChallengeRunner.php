<?php

namespace App\Livewire\Arena;

use App\Actions\Challenges\JoinChallenge;
use App\Enums\ContentStatus;
use App\Exceptions\ContextClosed;
use App\Models\Challenge;
use App\Models\ChallengeParticipation;
use App\Models\Exercise;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Page d'un défi : inscription, chronomètre, exercices (ExercisePlayer en mode « challenge »),
 * score et classement du défi en direct.
 */
#[Title('Défi')]
class ChallengeRunner extends Component
{
    #[Locked]
    public Challenge $challenge;

    #[Url(as: 'q')]
    public int $question = 1;

    public ?string $error = null;

    public function mount(Challenge $challenge): void
    {
        abort_unless($challenge->status === ContentStatus::Published, 404);
        abort_if($challenge->organization_id && ! auth()->user()->organizations()->whereKey($challenge->organization_id)->exists(), 403);

        $this->challenge = $challenge;
        $this->question = max(1, min($this->question, max(1, $this->exercises->count())));
    }

    public function join(JoinChallenge $join): void
    {
        try {
            $join->handle(auth()->user(), $this->challenge);
        } catch (ContextClosed $e) {
            $this->error = $e->getMessage();
        }

        unset($this->participation);
    }

    #[On('answer-recorded')]
    public function refreshScore(): void
    {
        unset($this->participation, $this->solved);
    }

    public function goTo(int $question): void
    {
        $this->question = max(1, min($question, $this->exercises->count()));
    }

    #[Computed]
    public function participation(): ?ChallengeParticipation
    {
        return $this->challenge->participations()->where('user_id', auth()->id())->first();
    }

    /**
     * @return Collection<int, Exercise>
     */
    #[Computed]
    public function exercises(): Collection
    {
        return $this->challenge->exercises()->with('level')->get();
    }

    /**
     * @return Collection<int, true>
     */
    #[Computed]
    public function solved(): Collection
    {
        return $this->participation
            ? $this->participation->submissions()->where('is_correct', true)->distinct()->pluck('exercise_id')->flip()->map(fn () => true)
            : collect();
    }

    public function render()
    {
        $participation = $this->participation;

        return view('livewire.arena.runner', [
            'participation' => $participation,
            'open' => $participation?->isOpen() ?? false,
            'current' => $this->exercises[$this->question - 1] ?? null,
            'standings' => $this->challenge->standings(),
        ]);
    }
}
