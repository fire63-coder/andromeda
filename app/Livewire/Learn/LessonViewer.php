<?php

namespace App\Livewire\Learn;

use App\Enums\ContentStatus;
use App\Enums\ProgressStatus;
use App\Livewire\Concerns\ThrottlesSandbox;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\SqlDialect;
use App\Services\Content\LessonRenderer;
use App\Services\Datasets\SchemaIntrospector;
use App\Services\Learning\CourseProgress;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Lecture d'une leçon : contenu Markdown, exemples ```sql runnable modifiables et exécutables
 * contre le jeu de données de la leçon, diagrammes, exercices liés, « J'ai terminé » (+ XP).
 */
#[Title('Leçon')]
class LessonViewer extends Component
{
    use ThrottlesSandbox;

    #[Locked]
    public Course $course;

    #[Locked]
    public Lesson $lesson;

    /** @var list<string> requêtes des blocs exécutables (modifiables par l'élève) */
    public array $snippets = [];

    /** @var array<int, array<string, mixed>> résultat par bloc */
    public array $results = [];

    public ?string $dialect = null;

    public function mount(Course $course, Lesson $lesson, LessonRenderer $renderer, CourseProgress $progress): void
    {
        abort_unless($lesson->chapter->course_id === $course->id, 404);
        abort_unless(
            ($lesson->status === ContentStatus::Published && $course->status === ContentStatus::Published) || auth()->user()->canAuthorContent(),
            404,
        );

        $this->course = $course;
        $this->lesson = $lesson;
        $this->snippets = $renderer->snippets($lesson->content_markdown);
        $this->dialect = $this->dialects->firstWhere('id', auth()->user()->preferred_dialect_id)?->slug
            ?? $this->dialects->firstWhere('is_default', true)?->slug
            ?? $this->dialects->first()?->slug;

        $progress->open(auth()->user(), $lesson);
    }

    public function runSnippet(int $index, SandboxManager $sandbox): void
    {
        if (! array_key_exists($index, $this->snippets)) {
            return;
        }

        $dialect = $this->dialects->firstWhere('slug', $this->dialect);

        $result = match (true) {
            ($message = $this->sandboxThrottled()) !== null => QueryResult::failure($message, QueryResult::ERROR_REJECTED),
            ! $this->lesson->dataset || ! $dialect => QueryResult::failure('Aucun jeu de données exécutable pour cette leçon.', QueryResult::ERROR_INTERNAL),
            // Les exemples peuvent illustrer des modifications : elles sont toujours annulées.
            default => $sandbox->run($this->lesson->dataset, $dialect, $this->snippets[$index], ['allowed_statements' => ['select', 'dml'], 'max_statements' => 5]),
        };

        $this->results[$index] = $result->toPreview(50);
    }

    public function resetSnippet(int $index, LessonRenderer $renderer): void
    {
        $original = $renderer->snippets($this->lesson->content_markdown)[$index] ?? null;

        if ($original !== null) {
            $this->snippets[$index] = $original;
            unset($this->results[$index]);
            $this->dispatch('sql-editor:replace', model: "snippets.{$index}", sql: $original);
        }
    }

    public function updatedDialect(): void
    {
        $this->results = [];
        $this->dispatch('sql-editor:mode', mode: $this->dialects->firstWhere('slug', $this->dialect)?->editor_mode ?? 'sqlite');
    }

    public function complete(CourseProgress $progress): void
    {
        $xp = $progress->complete(auth()->user(), $this->lesson);

        if ($xp > 0) {
            $this->dispatch('xp-gained', amount: $xp, total: auth()->user()->fresh()->xp);
            $this->dispatch('refresh-navigation-menu');
        }

        unset($this->completed);
    }

    /**
     * @return Collection<int, SqlDialect>
     */
    #[Computed]
    public function dialects(): Collection
    {
        $sandbox = app(SandboxManager::class);
        $dataset = $this->lesson->dataset;

        return $dataset
            ? SqlDialect::query()->executable()->orderBy('position')->get()->filter(fn (SqlDialect $d) => $sandbox->isExecutable($dataset, $d))->values()
            : collect();
    }

    #[Computed]
    public function completed(): bool
    {
        return $this->lesson->progress()->where('user_id', auth()->id())->where('status', ProgressStatus::Completed)->exists();
    }

    /**
     * @return array{previous: ?Lesson, next: ?Lesson, position: int, total: int}
     */
    #[Computed]
    public function navigation(): array
    {
        $lessons = app(CourseProgress::class)->lessons($this->course)->values();
        $index = $lessons->search(fn (Lesson $lesson) => $lesson->id === $this->lesson->id);

        return [
            'previous' => $index !== false && $index > 0 ? $lessons[$index - 1] : null,
            'next' => $index !== false ? $lessons->get($index + 1) : null,
            'position' => $index === false ? 0 : $index + 1,
            'total' => $lessons->count(),
        ];
    }

    public function render(LessonRenderer $renderer)
    {
        $exercises = $this->lesson->exercises()->practice()->get();
        $solved = auth()->user()->progress()
            ->where('progressable_type', (new Exercise)->getMorphClass())
            ->whereIn('progressable_id', $exercises->pluck('id'))
            ->where('status', ProgressStatus::Completed)
            ->pluck('progressable_id')
            ->flip();

        return view('livewire.learn.lesson-viewer', [
            'segments' => $renderer->segments($renderer->withoutLeadingTitle($this->lesson->content_markdown, $this->lesson->title)),
            'exercises' => $exercises,
            'solved' => $solved,
            'mode' => $this->dialects->firstWhere('slug', $this->dialect)?->editor_mode ?? 'sqlite',
            'schema' => app(SchemaIntrospector::class)->completionSchema($this->lesson->dataset?->tables_meta ?? []),
        ]);
    }
}
