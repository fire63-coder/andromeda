<?php

namespace App\Livewire\Certification;

use App\Actions\Certifications\FinishCertificationAttempt;
use App\Enums\AttemptStatus;
use App\Models\CertificationAttempt;
use App\Models\Exercise;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Déroulé d'une épreuve : chronomètre, navigation entre questions (chaque question est un
 * ExercisePlayer en mode « certification »), clôture, puis correction détaillée.
 */
#[Title('Certification')]
class CertificationRunner extends Component
{
    #[Locked]
    public CertificationAttempt $attempt;

    #[Url(as: 'q')]
    public int $question = 1;

    public function mount(CertificationAttempt $attempt, FinishCertificationAttempt $finish): void
    {
        abort_unless($attempt->user_id === auth()->id(), 403);

        if ($attempt->isExpired()) {
            $attempt = $finish->handle($attempt);
        }

        $this->attempt = $attempt;
        $this->question = max(1, min($this->question, count($attempt->exercise_ids)));
    }

    #[On('answer-recorded')]
    public function refreshAnswers(): void
    {
        unset($this->answered);
    }

    public function goTo(int $question): void
    {
        $this->question = max(1, min($question, count($this->attempt->exercise_ids)));
    }

    /** Bouton « Terminer » ou fin du chronomètre côté navigateur. */
    public function finish(FinishCertificationAttempt $finish): void
    {
        $this->attempt = $finish->handle($this->attempt);
        unset($this->answered);
    }

    /**
     * @return Collection<int, Exercise> exercices du sujet, dans l'ordre du tirage
     */
    #[Computed]
    public function exercises(): Collection
    {
        $exercises = Exercise::query()->with('level')->findMany($this->attempt->exercise_ids)->keyBy('id');

        return collect($this->attempt->exercise_ids)->map(fn (int $id) => $exercises[$id])->values();
    }

    /**
     * @return Collection<int, true> ids des questions ayant au moins une réponse
     */
    #[Computed]
    public function answered(): Collection
    {
        return $this->attempt->submissions()->distinct()->pluck('exercise_id')->flip()->map(fn () => true);
    }

    /**
     * Correction, une fois l'épreuve close : dernière réponse et verdict par question.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function review(): Collection
    {
        $scores = app(FinishCertificationAttempt::class)->scores($this->attempt);
        $latest = $this->attempt->submissions()->latest('id')->get()->unique('exercise_id')->keyBy('exercise_id');

        return $this->exercises->map(fn (Exercise $exercise) => [
            'exercise' => $exercise,
            'score' => $scores[$exercise->id] ?? 0,
            'submission' => $latest->get($exercise->id),
        ]);
    }

    public function render()
    {
        return view('livewire.certification.runner', [
            'inProgress' => $this->attempt->status === AttemptStatus::InProgress,
            'current' => $this->exercises[$this->question - 1] ?? null,
        ]);
    }
}
