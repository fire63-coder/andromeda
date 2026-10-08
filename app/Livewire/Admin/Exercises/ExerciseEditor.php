<?php

namespace App\Livewire\Admin\Exercises;

use App\Enums\ContentStatus;
use App\Enums\DatasetRole;
use App\Enums\ExerciseType;
use App\Enums\ValidationStrategy;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Skill;
use App\Models\SqlDialect;
use App\Services\Authoring\SolutionTester;
use App\Services\Content\LessonRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Création / modification d'un exercice, test de la solution sur tous les moteurs,
 * circuit brouillon → en relecture → publié (publication : administrateurs, après test réussi).
 */
#[Title('Éditeur d\'exercice')]
class ExerciseEditor extends Component
{
    #[Locked]
    public ?Exercise $exercise = null;

    public string $title = '';

    public string $slug = '';

    public string $type = 'query_write';

    public ?int $levelId = null;

    public ?int $dialectId = null;

    /** NULL = exercice réservé (certifications, défis). */
    public ?int $lessonId = null;

    public string $statement = '';

    public string $starterSql = '';

    public string $solutionSql = '';

    public string $strategy = 'result_set';

    /** @var list<string> */
    public array $allowedStatements = ['select'];

    public string $requiredKeywords = '';

    public string $forbiddenKeywords = '';

    public ?string $floatTolerance = null;

    /** Nombre maximal d'instructions (vide = 1 pour une lecture, 20 sinon). */
    public ?int $maxStatements = null;

    public bool $checkColumnNames = false;

    /** Une requête de contrôle par ligne : « nom: SELECT … ». */
    public string $checkQueries = '';

    /** Stratégie « plan d'exécution » : requête analysée (vide = celle de l'élève) et tables à atteindre par index. */
    public string $planQuery = '';

    public string $indexTables = '';

    /** Scénario de concurrence : étapes dont le résultat doit égaler celui de la solution, et refus de toute erreur. */
    public string $compareSteps = '';

    public bool $noErrors = false;

    /** @var list<array{text: string, xp_penalty: int|string}> */
    public array $hints = [];

    /** @var list<array{body: string, is_correct: bool, explanation: string}> */
    public array $choices = [];

    public ?int $primaryDatasetId = null;

    /** @var list<int> */
    public array $hiddenDatasetIds = [];

    /** @var list<int> */
    public array $skillIds = [];

    public int $difficulty = 1;

    public int $xpReward = 20;

    public ?int $timeLimitSeconds = null;

    public int $maxExecutionMs = 3000;

    public string $status = 'draft';

    /** @var array{passed: bool, checks: list<array{label: string, ok: bool, message: string}>}|null */
    public ?array $test = null;

    public ?string $saved = null;

    public function mount(?Exercise $exercise = null): void
    {
        if (! $exercise?->exists) {
            $this->authorize('create', Exercise::class);
            $this->levelId = Level::orderBy('position')->value('id');
            $this->hints = [['text' => '', 'xp_penalty' => 5]];

            return;
        }

        $this->authorize('update', $exercise);
        $this->exercise = $exercise->load(['datasets', 'skills', 'choices']);
        $options = $exercise->validation_options ?? [];

        $this->fill([
            'title' => $exercise->title,
            'slug' => $exercise->slug,
            'type' => $exercise->type->value,
            'levelId' => $exercise->level_id,
            'dialectId' => $exercise->sql_dialect_id,
            'lessonId' => $exercise->lesson_id,
            'statement' => $exercise->statement,
            'starterSql' => (string) $exercise->starter_sql,
            'solutionSql' => (string) $exercise->solution_sql,
            'strategy' => $exercise->validation_strategy->value,
            'allowedStatements' => $options['allowed_statements'] ?? ['select'],
            'requiredKeywords' => implode(', ', $options['required_keywords'] ?? []),
            'forbiddenKeywords' => implode(', ', $options['forbidden_keywords'] ?? []),
            'floatTolerance' => isset($options['float_tolerance']) ? (string) $options['float_tolerance'] : null,
            'maxStatements' => $options['max_statements'] ?? null,
            'checkColumnNames' => (bool) ($options['check_column_names'] ?? false),
            'checkQueries' => collect($options['check_queries'] ?? [])->map(fn ($sql, $name) => "{$name}: {$sql}")->implode("\n"),
            'planQuery' => (string) ($options['plan_query'] ?? ''),
            'indexTables' => implode(', ', $options['index_tables'] ?? []),
            'compareSteps' => implode(', ', $options['compare_steps'] ?? []),
            'noErrors' => (bool) ($options['no_errors'] ?? false),
            'hints' => array_map(fn (array $hint) => ['text' => $hint['text'], 'xp_penalty' => $hint['xp_penalty'] ?? 0], $exercise->hints ?? []),
            'choices' => $exercise->choices->map(fn ($c) => ['body' => $c->body, 'is_correct' => $c->is_correct, 'explanation' => (string) $c->explanation])->all(),
            'primaryDatasetId' => $exercise->datasets->firstWhere('pivot.role', DatasetRole::Primary->value)?->id,
            'hiddenDatasetIds' => $exercise->datasets->where('pivot.role', DatasetRole::HiddenTest->value)->pluck('id')->all(),
            'skillIds' => $exercise->skills->pluck('id')->all(),
            'difficulty' => $exercise->difficulty,
            'xpReward' => $exercise->xp_reward,
            'timeLimitSeconds' => $exercise->time_limit_seconds,
            'maxExecutionMs' => $exercise->max_execution_ms,
            'status' => $exercise->status->value,
        ]);
    }

    public function updatedTitle(): void
    {
        if (! $this->exercise) {
            $this->slug = Str::slug($this->title);
        }
    }

    public function updatedType(): void
    {
        if ($this->type === ExerciseType::MultipleChoice->value) {
            $this->strategy = ValidationStrategy::Choices->value;
            $this->choices = $this->choices ?: [['body' => '', 'is_correct' => true, 'explanation' => ''], ['body' => '', 'is_correct' => false, 'explanation' => '']];
        } elseif ($this->strategy === ValidationStrategy::Choices->value) {
            $this->strategy = ValidationStrategy::ResultSet->value;
        }
    }

    public function addHint(): void
    {
        $this->hints[] = ['text' => '', 'xp_penalty' => 5];
    }

    public function removeHint(int $index): void
    {
        unset($this->hints[$index]);
        $this->hints = array_values($this->hints);
    }

    public function addChoice(): void
    {
        $this->choices[] = ['body' => '', 'is_correct' => false, 'explanation' => ''];
    }

    public function removeChoice(int $index): void
    {
        unset($this->choices[$index]);
        $this->choices = array_values($this->choices);
    }

    public function save(): void
    {
        $this->persist();
    }

    /** Enregistre puis passe la solution dans le moteur d'évaluation. */
    public function runTest(SolutionTester $tester): void
    {
        if ($this->persist(keepStatus: true, redirectWhenCreated: false)) {
            $this->test = $tester->test($this->exercise->fresh());
        }
    }

    public function render()
    {
        return view('livewire.admin.exercises.editor', [
            'types' => ExerciseType::cases(),
            'strategies' => ValidationStrategy::cases(),
            'levels' => Level::orderBy('position')->get(),
            'dialects' => SqlDialect::orderBy('position')->get(),
            'lessons' => Lesson::with('chapter.course')->orderBy('title')->get(),
            'datasets' => Dataset::where('status', 'ready')->orderBy('name')->get(['id', 'name']),
            'skills' => Skill::orderBy('category')->orderBy('name')->get(),
            'statuses' => collect(ContentStatus::cases())->filter(fn ($s) => in_array($s->value, $this->allowedStatuses(), true)),
            'statementHtml' => app(LessonRenderer::class)->html($this->statement ?: '*Énoncé…*'),
            'isMcq' => $this->type === ExerciseType::MultipleChoice->value,
        ]);
    }

    private function persist(bool $keepStatus = false, bool $redirectWhenCreated = true): bool
    {
        $isMcq = $this->type === ExerciseType::MultipleChoice->value;
        // Les lignes d'indice laissées vides sont ignorées.
        $this->hints = array_values(array_filter($this->hints, fn (array $hint) => trim($hint['text'] ?? '') !== ''));
        $status = $keepStatus && $this->exercise ? $this->exercise->status->value : $this->status;

        $data = $this->withValidator(function (Validator $validator) use ($isMcq) {
            $validator->after(function (Validator $validator) use ($isMcq) {
                if ($isMcq && collect($this->choices)->where('is_correct', true)->isEmpty()) {
                    $validator->errors()->add('choices', 'Cochez au moins une bonne réponse.');
                }

                if ($this->strategy === ValidationStrategy::QueryPlan->value && $this->parseList($this->indexTables) === []) {
                    $validator->errors()->add('indexTables', 'Indiquez au moins une table qui doit être atteinte par un index.');
                }

                if ($this->strategy === ValidationStrategy::StateCheck->value && $this->parseCheckQueries() === []) {
                    $validator->errors()->add('checkQueries', 'Une validation par état des données nécessite au moins une requête de contrôle.');
                }

                if ($this->primaryDatasetId && in_array($this->primaryDatasetId, $this->hiddenDatasetIds, false)) {
                    $validator->errors()->add('hiddenDatasetIds', 'Le jeu visible ne peut pas être aussi un jeu de test caché.');
                }
            });
        })->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('exercises', 'slug')->ignore($this->exercise?->id)],
            'type' => ['required', Rule::enum(ExerciseType::class)],
            'levelId' => ['required', Rule::exists('levels', 'id')],
            'dialectId' => ['nullable', Rule::exists('sql_dialects', 'id')],
            'lessonId' => ['nullable', Rule::exists('lessons', 'id')],
            'statement' => ['required', 'string', 'max:20000'],
            'starterSql' => ['nullable', 'string', 'max:10000', Rule::requiredIf($this->type === ExerciseType::BugFix->value)],
            'solutionSql' => [Rule::requiredIf(! $isMcq), 'nullable', 'string', 'max:20000'],
            'strategy' => ['required', Rule::enum(ValidationStrategy::class), $isMcq ? Rule::in(['choices']) : Rule::notIn(['choices'])],
            'allowedStatements' => ['array', 'min:1'],
            'allowedStatements.*' => [Rule::in(['select', 'dml', 'ddl', 'routine'])],
            'floatTolerance' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'maxStatements' => ['nullable', 'integer', 'between:1,50'],
            'hints.*.text' => ['required', 'string', 'max:1000'],
            'hints.*.xp_penalty' => ['required', 'integer', 'min:0', 'max:500'],
            'choices' => $isMcq ? ['array', 'min:2'] : ['array'],
            'choices.*.body' => ['required', 'string', 'max:1000'],
            'primaryDatasetId' => [Rule::requiredIf(! $isMcq), 'nullable', Rule::exists('datasets', 'id')],
            'hiddenDatasetIds.*' => [Rule::exists('datasets', 'id')],
            'skillIds.*' => [Rule::exists('skills', 'id')],
            'difficulty' => ['required', 'integer', 'between:1,5'],
            'xpReward' => ['required', 'integer', 'min:0', 'max:1000'],
            'timeLimitSeconds' => ['nullable', 'integer', 'min:10', 'max:7200', Rule::requiredIf($this->type === ExerciseType::TimedChallenge->value)],
            'maxExecutionMs' => ['required', 'integer', 'min:100', 'max:'.config('sandbox.max_execution_ms')],
            'status' => ['required', Rule::in($this->allowedStatuses())],
        ], attributes: [
            'title' => 'titre', 'slug' => 'identifiant', 'statement' => 'énoncé', 'starterSql' => 'code de départ',
            'solutionSql' => 'solution', 'primaryDatasetId' => 'jeu visible', 'timeLimitSeconds' => 'temps limite',
            'choices' => 'réponses', 'hints.*.text' => 'texte de l\'indice', 'choices.*.body' => 'texte de la réponse',
        ]);

        $publishing = $status === ContentStatus::Published->value && $this->exercise?->status !== ContentStatus::Published;
        $wasPublished = $this->exercise?->status === ContentStatus::Published;
        $choicesBefore = $this->exercise?->choices()->orderBy('position')->get(['body', 'is_correct', 'explanation'])->toArray() ?? [];
        $datasetsBefore = $this->datasetRoles();
        $changed = false;

        DB::transaction(function () use ($data, $isMcq, $status, &$changed) {
            $attributes = [
                'title' => $data['title'],
                'slug' => $data['slug'],
                'type' => $data['type'],
                'level_id' => $data['levelId'],
                'sql_dialect_id' => $data['dialectId'],
                'lesson_id' => $data['lessonId'],
                'statement' => $data['statement'],
                'starter_sql' => $this->starterSql ?: null,
                'solution_sql' => $isMcq ? null : $this->solutionSql,
                'validation_strategy' => $data['strategy'],
                'validation_options' => $isMcq ? null : $this->options(),
                'hints' => array_map(fn (array $h) => ['text' => $h['text'], 'xp_penalty' => (int) $h['xp_penalty']], $this->hints) ?: null,
                'difficulty' => $data['difficulty'],
                'xp_reward' => $data['xpReward'],
                'time_limit_seconds' => $this->timeLimitSeconds,
                'max_execution_ms' => $data['maxExecutionMs'],
                // La publication passe par publishIfTested() : on n'enregistre ici que les autres statuts.
                'status' => $status === ContentStatus::Published->value ? ($this->exercise?->status->value ?? 'draft') : $status,
            ];

            if ($this->exercise) {
                $this->exercise->update($attributes);
            } else {
                $this->exercise = Exercise::create($attributes + ['author_id' => auth()->id()]);
            }

            $this->exercise->choices()->delete();
            if ($isMcq) {
                $this->exercise->choices()->createMany(array_map(fn (array $choice, int $i) => [
                    'body' => $choice['body'],
                    'is_correct' => (bool) $choice['is_correct'],
                    'explanation' => $choice['explanation'] ?: null,
                    'position' => $i + 1,
                ], $this->choices, array_keys($this->choices)));
            }

            $datasets = [];
            if (! $isMcq && $this->primaryDatasetId) {
                $datasets[$this->primaryDatasetId] = ['role' => DatasetRole::Primary->value, 'position' => 0];
                foreach (array_values(array_unique($this->hiddenDatasetIds)) as $i => $id) {
                    $datasets[(int) $id] = ['role' => DatasetRole::HiddenTest->value, 'position' => $i + 1];
                }
            }
            $this->exercise->datasets()->sync($datasets);
            $skillChanges = $this->exercise->skills()->sync($this->skillIds);

            $changed = $this->exercise->wasChanged() || $skillChanges['attached'] !== [] || $skillChanges['detached'] !== [];
        });

        $changed = $changed
            || $datasetsBefore !== $this->datasetRoles()
            || $choicesBefore !== $this->exercise->choices()->orderBy('position')->get(['body', 'is_correct', 'explanation'])->toArray();

        // Un formateur qui modifie un exercice publié le renvoie en relecture : il n'est plus proposé
        // aux élèves tant qu'un administrateur ne l'a pas republié (après le test de la solution).
        if ($wasPublished && $changed && auth()->user()->cannot('publish', Exercise::class)) {
            $this->exercise->update(['status' => ContentStatus::InReview, 'reviewer_id' => null, 'reviewed_at' => null]);
            $this->status = ContentStatus::InReview->value;
            $this->saved = 'Modifications enregistrées : l\'exercice repasse en relecture et n\'est plus proposé aux élèves jusqu\'à sa republication.';

            return true;
        }

        if ($publishing) {
            return $this->publishIfTested();
        }

        $wasNew = $this->exercise->wasRecentlyCreated;
        $this->saved = 'Exercice enregistré.';

        if ($wasNew && $redirectWhenCreated) {
            $this->redirectRoute('admin.exercises.edit', $this->exercise, navigate: true);
        }

        return true;
    }

    /**
     * Jeux de données liés et leur rôle (visible, test caché), dans l'ordre.
     *
     * @return array<int, string>
     */
    private function datasetRoles(): array
    {
        return $this->exercise?->datasets()->orderBy('dataset_exercise.position')->get()
            ->mapWithKeys(fn ($dataset) => [$dataset->id => $dataset->pivot->role])->all() ?? [];
    }

    /**
     * Publication (administrateur) : uniquement si la solution passe sur tous les moteurs.
     */
    private function publishIfTested(): bool
    {
        $this->authorize('publish', Exercise::class);
        $this->test = app(SolutionTester::class)->test($this->exercise->fresh());

        if (! $this->test['passed']) {
            $this->addError('status', 'Publication refusée : le test de la solution échoue (voir ci-dessous).');

            return false;
        }

        $this->exercise->update([
            'status' => ContentStatus::Published,
            'published_at' => $this->exercise->published_at ?? now(),
            'reviewer_id' => auth()->id(),
            'reviewed_at' => now(),
        ]);
        $this->saved = 'Exercice publié.';

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $keywords = fn (string $list) => array_values(array_filter(array_map(fn ($k) => strtoupper(trim($k)), explode(',', $list))));

        return array_filter([
            'allowed_statements' => array_values($this->allowedStatements),
            'max_statements' => $this->maxStatements,
            'required_keywords' => $keywords($this->requiredKeywords),
            'forbidden_keywords' => $keywords($this->forbiddenKeywords),
            'float_tolerance' => $this->floatTolerance !== null && $this->floatTolerance !== '' ? (float) $this->floatTolerance : null,
            'check_column_names' => $this->checkColumnNames ?: null,
            'check_queries' => in_array($this->strategy, [ValidationStrategy::StateCheck->value, ValidationStrategy::Concurrency->value], true) ? ($this->parseCheckQueries() ?: null) : null,
            'compare_steps' => $this->strategy === ValidationStrategy::Concurrency->value
                ? array_values(array_unique(array_filter(array_map('intval', explode(',', $this->compareSteps)), fn (int $n) => $n > 0)))
                : null,
            'no_errors' => $this->strategy === ValidationStrategy::Concurrency->value && $this->noErrors ? true : null,
            'plan_query' => $this->strategy === ValidationStrategy::QueryPlan->value && trim($this->planQuery) !== '' ? trim($this->planQuery) : null,
            'index_tables' => $this->strategy === ValidationStrategy::QueryPlan->value ? $this->parseList($this->indexTables) : null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @return list<string>
     */
    private function parseList(string $list): array
    {
        return array_values(array_filter(array_map(fn ($item) => strtolower(trim($item)), explode(',', $list))));
    }

    /**
     * @return array<string, string>
     */
    private function parseCheckQueries(): array
    {
        return collect(preg_split('/\R/', $this->checkQueries))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->mapWithKeys(function (string $line, int $i) {
                // « nom: requête » : le nom peut contenir espaces et parenthèses (« salaire_annuel(id) »),
                // la requête commence par un mot-clé SQL ; sinon toute la ligne est la requête.
                return preg_match('/^(?<name>[^:]+?)\s*:\s*(?<sql>(?:SELECT|WITH|VALUES|TABLE|CALL|EXPLAIN)\b.*)$/is', $line, $match)
                    ? [$match['name'] => $match['sql']]
                    : ['controle_'.($i + 1) => $line];
            })
            ->all();
    }

    /**
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        if (auth()->user()->can('publish', Exercise::class)) {
            return array_column(ContentStatus::cases(), 'value');
        }

        return array_values(array_unique([ContentStatus::Draft->value, ContentStatus::InReview->value, ...($this->exercise ? [$this->exercise->status->value] : [])]));
    }
}
