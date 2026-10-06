<?php

namespace App\Livewire\Exercises;

use App\Actions\Exercises\SubmitAnswer;
use App\Enums\ExerciseType;
use App\Enums\SubmissionStatus;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\UserProgress;
use App\Services\Datasets\SchemaIntrospector;
use App\Services\Evaluation\SubmissionEvaluator;
use App\Services\Sandbox\QueryResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
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
    /** Taille maximale d'aperçu renvoyée au navigateur. */
    private const PREVIEW_ROWS = 100;

    #[Locked]
    public Exercise $exercise;

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

    public function mount(Exercise $exercise, SubmissionEvaluator $evaluator): void
    {
        $this->authorize('view', $exercise);

        $this->exercise = $exercise;
        $this->sql = $exercise->starter_sql ?? '';
        $this->dialect = $evaluator->defaultDialect($exercise, auth()->user())?->slug;
        $this->startedAt = $exercise->time_limit_seconds ? now()->toIso8601String() : null;

        // L'élève reprend là où il en était : indices déjà consultés lors de sa dernière soumission.
        $this->hintsRevealed = (int) auth()->user()->submissions()
            ->where('exercise_id', $exercise->id)
            ->max('hints_used');
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
        if ($this->hintsRevealed < count($this->exercise->hints ?? [])) {
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
        return app(SubmissionEvaluator::class)->availableDialects($this->exercise);
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
        return view('livewire.exercises.exercise-player', [
            'choices' => $this->isSqlExercise() ? collect() : $this->exercise->choices,
        ]);
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
        $key = 'sandbox:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, (int) config('sandbox.rate_limit_per_minute'))) {
            $seconds = RateLimiter::availableIn($key);
            $this->result = QueryResult::failure("Trop d'exécutions rapprochées : réessayez dans {$seconds} s.", QueryResult::ERROR_REJECTED)->toPreview();
            $this->verdict = null;

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
