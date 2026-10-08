<?php

namespace App\Livewire\Certification;

use App\Actions\Certifications\CertificationException;
use App\Actions\Certifications\FinishCertificationAttempt;
use App\Actions\Certifications\RecordExamIncident;
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

        // Mode examen : rouvrir ou recharger la page fait sortir du plein écran sans laisser de trace côté navigateur.
        if ($attempt->isSecureExam()) {
            $opened = collect($attempt->incidents ?? [])->contains('type', 'opened');
            app(RecordExamIncident::class)->handle($attempt, $opened ? 'page_reload' : 'opened');
            $attempt->refresh();
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

    /**
     * Incident signalé par le navigateur en mode examen (sortie du plein écran, changement d'onglet, collage bloqué…).
     *
     * @return array{counted: bool, count: int, limit: ?int, closed: bool}
     */
    public function reportIncident(string $type, RecordExamIncident $record): array
    {
        try {
            $outcome = $record->handle($this->attempt, $type);
        } catch (CertificationException) {
            return ['counted' => false, 'count' => $this->attempt->incidents_count, 'limit' => null, 'closed' => false];
        }

        $this->attempt->refresh(); // compteur de l'en-tête, ou correction si l'épreuve vient d'être close

        if ($outcome['closed']) {
            $this->announceEnd();
        }

        return $outcome;
    }

    /** Bouton « Terminer » ou fin du chronomètre côté navigateur. */
    public function finish(FinishCertificationAttempt $finish): void
    {
        $this->attempt = $finish->handle($this->attempt);
        unset($this->answered);
        $this->announceEnd();
    }

    /**
     * Fin d'une épreuve surveillée : la bannière « application fermée » laisse place au résultat.
     */
    private function announceEnd(): void
    {
        if ($this->attempt->certification->exam_mode) {
            $this->dispatch('banner-message', style: 'success', message: 'Épreuve terminée : le reste de l\'application est de nouveau accessible.');
        }
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
        $inProgress = $this->attempt->status === AttemptStatus::InProgress;
        $secure = $inProgress && $this->attempt->certification->exam_mode;

        return view('livewire.certification.runner', [
            'inProgress' => $inProgress,
            'secure' => $secure,
            'current' => $this->exercises[$this->question - 1] ?? null,
        ])->layoutData(['exam' => $secure]);
    }
}
