<?php

namespace App\Livewire\Admin\Courses;

use App\Enums\ContentStatus;
use App\Models\Course;
use App\Models\Dataset;
use App\Models\Lesson;
use App\Models\SqlDialect;
use App\Services\Content\LessonRenderer;
use App\Services\Sandbox\SandboxManager;
use App\Services\Sandbox\Scenario\ScenarioParser;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Édition d'une leçon : Markdown avec aperçu en direct, jeu de données des exemples,
 * vérification de tous les blocs ```sql runnable avant publication.
 */
#[Title('Éditeur de leçon')]
class LessonEditor extends Component
{
    #[Locked]
    public Lesson $lesson;

    public string $title = '';

    public string $slug = '';

    public ?int $datasetId = null;

    public ?int $estimatedMinutes = null;

    public int $xpReward = 10;

    public string $status = 'draft';

    public string $content = '';

    /** @var list<array{index: int, sql: string, ok: bool, message: string}> */
    public array $checks = [];

    public ?string $saved = null;

    public function mount(Lesson $lesson): void
    {
        $this->authorize('update', $lesson->chapter->course);

        $this->lesson = $lesson;
        $this->fill([
            'title' => $lesson->title,
            'slug' => $lesson->slug,
            'datasetId' => $lesson->dataset_id,
            'estimatedMinutes' => $lesson->estimated_minutes,
            'xpReward' => $lesson->xp_reward,
            'status' => $lesson->status->value,
            'content' => $lesson->content_markdown,
        ]);
    }

    public function save(): void
    {
        $course = $this->lesson->chapter->course;
        $this->authorize('update', $course);

        $data = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'alpha_dash', 'max:160', function ($attribute, $value, $fail) use ($course) {
                if ($course->lessons()->withTrashed()->where('lessons.slug', $value)->where('lessons.id', '!=', $this->lesson->id)->exists()) {
                    $fail('Cet identifiant est déjà utilisé dans ce cours.');
                }
            }],
            'datasetId' => ['nullable', Rule::exists('datasets', 'id')],
            'estimatedMinutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'xpReward' => ['required', 'integer', 'min:0', 'max:500'],
            'status' => ['required', Rule::in($this->allowedStatuses())],
            'content' => ['required', 'string', 'max:100000'],
        ], attributes: ['title' => 'titre', 'slug' => 'identifiant', 'content' => 'contenu']);

        $this->lesson->update([
            'title' => $data['title'],
            'slug' => $data['slug'],
            'dataset_id' => $data['datasetId'],
            'estimated_minutes' => $data['estimatedMinutes'],
            'xp_reward' => $data['xpReward'],
            'status' => $data['status'],
            'content_markdown' => $data['content'],
            'content_html' => app(LessonRenderer::class)->html($data['content']),
            'published_at' => $data['status'] === ContentStatus::Published->value ? ($this->lesson->published_at ?? now()) : null,
        ]);

        $this->saved = 'Leçon enregistrée.';
    }

    /**
     * Exécute chaque bloc runnable sur le jeu de données choisi, sans rien enregistrer.
     */
    public function checkSnippets(LessonRenderer $renderer, SandboxManager $sandbox): void
    {
        $dataset = Dataset::find($this->datasetId);
        // Le moteur du cours s'il en impose un (ex. PostgreSQL pour PL/pgSQL), sinon le moteur par défaut.
        $dialect = $this->lesson->chapter->course->dialect ?? SqlDialect::where('is_default', true)->first();

        $this->checks = collect($renderer->snippets($this->content))->map(function (string $sql, int $index) use ($dataset, $dialect, $sandbox) {
            if (! $dataset || ! $dialect) {
                return ['index' => $index, 'sql' => $sql, 'ok' => false, 'message' => 'Choisissez un jeu de données pour exécuter les exemples.'];
            }

            $result = ScenarioParser::isScenario($sql)
                ? $sandbox->runScenario($dataset, $dialect, $sql)
                : $sandbox->run($dataset, $dialect, $sql, LessonRenderer::SNIPPET_GUARD);

            return [
                'index' => $index,
                'sql' => $sql,
                'ok' => $result->success,
                'message' => $result->success ? count($result->rows).' ligne(s), '.$result->durationMs.' ms' : (string) $result->error,
            ];
        })->all();
    }

    public function render(LessonRenderer $renderer)
    {
        return view('livewire.admin.courses.lesson-editor', [
            'course' => $this->lesson->chapter->course,
            'segments' => $renderer->segments($this->content),
            'datasets' => Dataset::orderBy('name')->get(['id', 'name']),
            'statuses' => collect(ContentStatus::cases())->filter(fn ($s) => in_array($s->value, $this->allowedStatuses(), true)),
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        if (auth()->user()->can('publish', Course::class)) {
            return array_column(ContentStatus::cases(), 'value');
        }

        return array_values(array_unique([ContentStatus::Draft->value, ContentStatus::InReview->value, $this->lesson->status->value]));
    }
}
