<?php

namespace App\Livewire\Exercises;

use App\Actions\Exercises\SubmitAnswer;
use App\Contracts\ExerciseContext;
use App\Enums\ExerciseType;
use App\Enums\SubmissionStatus;
use App\Exceptions\ContextClosed;
use App\Livewire\Concerns\ThrottlesSandbox;
use App\Models\CertificationAttempt;
use App\Models\ChallengeParticipation;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\UserProgress;
use App\Models\UserSubmission;
use App\Services\Datasets\SchemaIntrospector;
use App\Services\Evaluation\SubmissionEvaluator;
use App\Services\Sandbox\QueryResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page d'exercice : énoncé, schéma, indices, éditeur SQL,
 * « Exécuter » (aperçu sur le jeu visible) et « Valider » (évaluation complète).
 *
 * La logique métier vit dans SubmissionEvaluator / SubmitAnswer : ce composant
 * ne gère que l'état de l'écran.
 */
#[Title('Exercice')]
class ExercisePlayer extends Component
{
    use ThrottlesSandbox;

    /** Taille maximale d'aperçu renvoyée au navigateur. */
    private const PREVIEW_ROWS = 100;

    #[Locked]
    public Exercise $exercise;

    /** practice (entraînement) | certification (épreuve) | challenge (défi) */
    #[Locked]
    public string $mode = 'practice';

    /** Tentative de certification ou participation à un défi, selon le mode. */
    #[Locked]
    public ?int $contextId = null;

    public string $sql = '';

    /** Slug du dialecte choisi (sql_dialects.slug). */
    public ?string $dialect = null;

    /** @var list<int> */
    public array $selectedChoices = [];

    #[Locked]
    public int $hintsRevealed = 0;

    #[Locked]
    public ?string $startedAt = null;

    /** Dernier aperçu de résultat (QueryResult::toPreview()). */
    public ?array $result = null;

    /** Dernier verdict de validation. */
    public ?array $verdict = null;

    public function mount(Exercise $exercise, SubmissionEvaluator $evaluator, string $mode = 'practice', ?int $contextId = null): void
    {
        $this->exercise = $exercise;
        $this->mode = in_array($mode, ['practice', 'certification', 'challenge'], true) ? $mode : 'practice';
        $this->contextId = $contextId;

        if ($this->mode === 'practice') {
            $this->authorize('view', $exercise);
        } else {
            // En épreuve, l'accès vient du contexte : l'exercice doit en faire partie.
            abort_unless($this->context()?->includes($exercise), 403);
        }

        $this->sql = $exercise->starter_sql ?? '';
        $this->dialect = $evaluator->defaultDialect($exercise, auth()->user())?->slug;
        $this->startedAt = $exercise->time_limit_seconds && $this->mode === 'practice' ? now()->toIso8601String() : null;

        if ($this->mode === 'practice') {
            // L'élève reprend là où il en était : indices déjà consultés lors de sa dernière soumission.
            $this->hintsRevealed = (int) auth()->user()->submissions()
                ->where('exercise_id', $exercise->id)
                ->max('hints_used');
        } else {
            $this->restoreLastAnswer();
        }
    }

    public function run(SubmissionEvaluator $evaluator): void
    {
        if (! $this->isSqlExercise() || ! $this->throttle()) {
            return;
        }

        $this->verdict = null;

        $dialect = $this->currentDialect();

        if (! $dialect) {
            $this->result = QueryResult::failure('Aucun moteur SQL n\'est disponible pour cet exercice.', QueryResult::ERROR_INTERNAL)->toPreview();

            return;
        }

        $result = $evaluator->preview($this->exercise, $dialect, $this->sql);
        $this->result = $this->withStatePreview($result);
    }

    public function submit(SubmitAnswer $submitAnswer): void
    {
        if (! $this->throttle()) {
            return;
        }

        $dialect = $this->currentDialect();

        if (! $dialect && $this->isSqlExercise()) {
            $this->verdict = $this->verdictPayload(SubmissionStatus::Error, 'Aucun moteur SQL n\'est disponible pour cet exercice.');

            return;
        }

        if ($this->mode !== 'practice') {
            $this->submitInContext($dialect ?? SqlDialect::where('is_default', true)->firstOrFail());

            return;
        }

        $outcome = $submitAnswer->handle(
            user: auth()->user(),
            exercise: $this->exercise,
            dialect: $dialect ?? SqlDialect::where('is_default', true)->firstOrFail(),
            sql: $this->isSqlExercise() ? $this->sql : null,
            choiceIds: array_map('intval', $this->selectedChoices),
            hintsUsed: $this->hintsRevealed,
            startedAt: $this->startedAt ? Carbon::parse($this->startedAt) : null,
        );
        $submission = $outcome->submission;

        $this->result = $submission->result_preview;
        $this->verdict = $this->verdictPayload(
            $submission->status,
            $submission->feedback['message'] ?? '',
            $submission->feedback ?? [],
            $submission->score,
            $submission->xp_awarded,
        );

        unset($this->progress);

        if ($submission->xp_awarded > 0 || $outcome->unlockedBadges->isNotEmpty()) {
            $this->dispatch('xp-gained', amount: $submission->xp_awarded + $outcome->unlockedBadges->sum('xp_bonus'), total: auth()->user()->fresh()->xp);
            $this->dispatch('refresh-navigation-menu');
        }

        foreach ($outcome->unlockedBadges as $badge) {
            $this->dispatch('badge-unlocked', name: $badge->name, description: $badge->description, tier: $badge->tier->value, icon: $badge->icon);
        }

        if ($outcome->promotedTo) {
            $this->dispatch('rank-up', name: $outcome->promotedTo->name);
        }
    }

    public function revealHint(): void
    {
        if ($this->mode === 'practice' && $this->hintsRevealed < count($this->exercise->hints ?? [])) {
            $this->hintsRevealed++;
        }
    }

    public function resetEditor(): void
    {
        $this->sql = $this->exercise->starter_sql ?? '';
        $this->result = null;
        $this->verdict = null;
        $this->dispatch('sql-editor:replace', sql: $this->sql);
    }

    public function updatedDialect(): void
    {
        if (! $this->dialects->contains('slug', $this->dialect)) {
            $this->dialect = $this->dialects->first()?->slug;
        }

        $this->result = null;
        $this->verdict = null;
        $this->dispatch('sql-editor:mode', mode: $this->currentDialect()?->editor_mode ?? 'sqlite');
    }

    /**
     * @return Collection<int, SqlDialect>
     */
    #[Computed]
    public function dialects(): Collection
    {
        $dialects = app(SubmissionEvaluator::class)->availableDialects($this->exercise);
        $imposed = $this->context()?->imposedDialectId();

        return $imposed ? $dialects->where('id', $imposed)->values() : $dialects;
    }

    /**
     * Tables du jeu visible, pour l'explorateur de schéma et l'autocomplétion.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function tables(): array
    {
        return $this->exercise->primaryDataset()?->tables_meta ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    #[Computed]
    public function completionSchema(): array
    {
        return app(SchemaIntrospector::class)->completionSchema($this->tables);
    }

    #[Computed]
    public function statementHtml(): string
    {
        return Str::markdown($this->exercise->statement, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * @return list<array{text: string, xp_penalty: int}>
     */
    #[Computed]
    public function revealedHints(): array
    {
        return array_slice($this->exercise->hints ?? [], 0, $this->hintsRevealed);
    }

    #[Computed]
    public function nextHintPenalty(): ?int
    {
        $next = ($this->exercise->hints ?? [])[$this->hintsRevealed] ?? null;

        return $next ? (int) ($next['xp_penalty'] ?? 0) : null;
    }

    #[Computed]
    public function potentialXp(): int
    {
        return app(SubmitAnswer::class)->xpFor($this->exercise, $this->hintsRevealed);
    }

    #[Computed]
    public function progress(): ?UserProgress
    {
        return $this->exercise->progress()->where('user_id', auth()->id())->first();
    }

    /**
     * Contexte d'épreuve de l'utilisateur courant (null en entraînement).
     */
    public function context(): ?ExerciseContext
    {
        if ($this->contextId === null) {
            return null;
        }

        return once(fn () => match ($this->mode) {
            'certification' => CertificationAttempt::query()->where('user_id', auth()->id())->find($this->contextId),
            'challenge' => ChallengeParticipation::query()->where('user_id', auth()->id())->find($this->contextId),
            default => null,
        });
    }

    public function isSqlExercise(): bool
    {
        return $this->exercise->type !== ExerciseType::MultipleChoice;
    }

    public function currentDialect(): ?SqlDialect
    {
        return $this->dialects->firstWhere('slug', $this->dialect);
    }

    public function render()
    {
        $choices = $this->isSqlExercise() ? collect() : $this->exercise->choices;

        if ($this->mode === 'certification') {
            // Ordre des réponses propre à chaque tentative (stable d'un affichage à l'autre) :
            // deux candidats côte à côte ne voient pas « la réponse C » au même endroit.
            $choices = $choices->sortBy(fn ($choice) => hash('xxh3', "{$this->contextId}:{$choice->id}"))->values();
        }

        return view('livewire.exercises.exercise-player', [
            'choices' => $choices,
        ]);
    }

    private function submitInContext(SqlDialect $dialect): void
    {
        $context = $this->context();
        abort_unless($context, 403);

        try {
            $submission = $context->recordAnswer(
                $this->exercise,
                $dialect,
                $this->isSqlExercise() ? $this->sql : null,
                array_map('intval', $this->selectedChoices),
            );
        } catch (ContextClosed $e) {
            $this->verdict = $this->verdictPayload(SubmissionStatus::Rejected, $e->getMessage());

            return;
        }

        $this->result = $submission->result_preview;

        if ($this->mode === 'certification') {
            // En certification, le verdict n'est révélé qu'à la fin de l'épreuve.
            $this->verdict = [
                'status' => 'recorded',
                'label' => 'Réponse enregistrée',
                'message' => 'Vous pouvez la modifier jusqu\'à la fin de l\'épreuve : c\'est la dernière réponse qui compte.',
                'score' => 0,
                'xp' => 0,
                'feedback' => [],
            ];
        } else {
            $this->verdict = $this->verdictPayload(
                $submission->status,
                $submission->feedback['message'] ?? '',
                $submission->feedback ?? [],
                $submission->score,
            );
            $this->verdict['points'] = $submission->feedback['points'] ?? 0;
            $this->notifyGains($submission);
        }

        $this->dispatch('answer-recorded', exerciseId: $this->exercise->id);
    }

    /**
     * XP et badges gagnés pendant un défi : notifications et compteur de la barre de navigation.
     */
    private function notifyGains(UserSubmission $submission): void
    {
        $badges = auth()->user()->badges()->wherePivot('awarded_at', '>=', $submission->created_at)->get();

        if ($submission->xp_awarded > 0 || $badges->isNotEmpty()) {
            $this->dispatch('xp-gained', amount: $submission->xp_awarded + $badges->sum('xp_bonus'), total: auth()->user()->fresh()->xp);
            $this->dispatch('refresh-navigation-menu');
        }

        foreach ($badges as $badge) {
            $this->dispatch('badge-unlocked', name: $badge->name, description: $badge->description, tier: $badge->tier->value, icon: $badge->icon);
        }
    }

    /**
     * En épreuve, on retrouve la dernière réponse donnée à cette question.
     */
    private function restoreLastAnswer(): void
    {
        $last = $this->context()?->submissions()->where('exercise_id', $this->exercise->id)->latest('id')->first();

        if ($last) {
            $this->sql = $last->query_sql ?? $this->sql;
            $this->selectedChoices = $last->selected_choice_ids ?? [];
        }
    }

    /**
     * Pour un UPDATE / DELETE sans résultat, on montre l'état des données après exécution.
     *
     * @return array<string, mixed>
     */
    private function withStatePreview(QueryResult $result): array
    {
        $preview = $result->toPreview(self::PREVIEW_ROWS);

        if ($result->success && ! $result->hasResultSet() && $result->checks !== []) {
            $name = array_key_first($result->checks);
            $check = $result->checks[$name];
            $preview = [
                ...$preview,
                'columns' => $check['columns'],
                'rows' => array_slice($check['rows'], 0, self::PREVIEW_ROWS),
                'row_count' => count($check['rows']),
                'state_of' => $name,
            ];
        }

        return $preview;
    }

    /**
     * @param  array<string, mixed>  $feedback
     * @return array<string, mixed>
     */
    private function verdictPayload(SubmissionStatus $status, string $message, array $feedback = [], int $score = 0, int $xp = 0): array
    {
        return [
            'status' => $status->value,
            'label' => $status->label(),
            'message' => $message,
            'score' => $score,
            'xp' => $xp,
            'feedback' => $feedback,
        ];
    }

    private function throttle(): bool
    {
        // Pendant une épreuve surveillée, la sandbox ne sert qu'à l'épreuve (pas d'onglet d'entraînement ouvert à côté).
        if ($this->mode !== 'certification' && auth()->user()->activeSecureExam()) {
            $this->result = QueryResult::failure('Une épreuve en mode examen est en cours : terminez-la avant de reprendre l\'entraînement.', QueryResult::ERROR_REJECTED)->toPreview();
            $this->verdict = null;

            return false;
        }

        if ($message = $this->sandboxThrottled()) {
            $this->result = QueryResult::failure($message, QueryResult::ERROR_REJECTED)->toPreview();
            $this->verdict = null;

            return false;
        }

        return true;
    }
}
