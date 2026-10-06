<?php

namespace App\Livewire\Admin\Courses;

use App\Enums\ContentStatus;
use App\Models\Chapter;
use App\Models\Course;
use App\Models\Dataset;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\SqlDialect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Création / modification d'un cours et gestion de ses chapitres et leçons (ordre, ajout, suppression).
 */
#[Title('Éditeur de cours')]
class CourseEditor extends Component
{
    #[Locked]
    public ?Course $course = null;

    public string $title = '';

    public string $slug = '';

    public ?int $levelId = null;

    public ?int $dialectId = null;

    public string $summary = '';

    public string $description = '';

    public ?int $estimatedMinutes = null;

    public string $status = 'draft';

    public string $newChapter = '';

    /** @var array<int, string> titre de la nouvelle leçon par chapitre */
    public array $newLesson = [];

    /** Chapitre en cours d'édition (titre, résumé, schéma). */
    public ?int $editingChapterId = null;

    /** @var array{title: string, summary: string, schema_diagram: string, dataset_id: ?int} */
    public array $chapterForm = ['title' => '', 'summary' => '', 'schema_diagram' => '', 'dataset_id' => null];

    public ?string $saved = null;

    public function mount(?Course $course = null): void
    {
        if ($course?->exists) {
            $this->authorize('update', $course);
            $this->course = $course;
            $this->fill([
                'title' => $course->title,
                'slug' => $course->slug,
                'levelId' => $course->level_id,
                'dialectId' => $course->sql_dialect_id,
                'summary' => (string) $course->summary,
                'description' => (string) $course->description,
                'estimatedMinutes' => $course->estimated_minutes,
                'status' => $course->status->value,
            ]);
        } else {
            $this->authorize('create', Course::class);
            $this->levelId = Level::orderBy('position')->value('id');
        }
    }

    public function updatedTitle(): void
    {
        if (! $this->course) {
            $this->slug = Str::slug($this->title);
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'title' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'alpha_dash', 'max:160', Rule::unique('courses', 'slug')->ignore($this->course?->id)],
            'levelId' => ['required', Rule::exists('levels', 'id')],
            'dialectId' => ['nullable', Rule::exists('sql_dialects', 'id')],
            'summary' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:20000'],
            'estimatedMinutes' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'status' => ['required', Rule::in($this->allowedStatuses())],
        ], attributes: ['title' => 'titre', 'slug' => 'identifiant', 'levelId' => 'niveau']);

        $attributes = [
            'title' => $data['title'],
            'slug' => $data['slug'],
            'level_id' => $data['levelId'],
            'sql_dialect_id' => $data['dialectId'],
            'summary' => $data['summary'] ?: null,
            'description' => $data['description'] ?: null,
            'estimated_minutes' => $data['estimatedMinutes'],
            'status' => $data['status'],
            'published_at' => $data['status'] === ContentStatus::Published->value ? ($this->course?->published_at ?? now()) : null,
        ];

        if ($this->course) {
            $this->course->update($attributes);
        } else {
            $this->course = Course::create($attributes + ['author_id' => auth()->id()]);
            $this->redirectRoute('admin.courses.edit', $this->course, navigate: true);

            return;
        }

        $this->saved = 'Cours enregistré.';
    }

    public function addChapter(): void
    {
        $this->authorizeCourse();
        $this->validate(['newChapter' => ['required', 'string', 'max:160']], attributes: ['newChapter' => 'titre du chapitre']);

        $this->course->chapters()->create([
            'title' => $this->newChapter,
            'slug' => $this->uniqueSlug($this->course->chapters(), $this->newChapter),
            'position' => (int) $this->course->chapters()->max('position') + 1,
        ]);

        $this->reset('newChapter');
    }

    public function editChapter(int $chapterId): void
    {
        $chapter = $this->chapter($chapterId);
        $this->editingChapterId = $chapter->id;
        $this->chapterForm = [
            'title' => $chapter->title,
            'summary' => (string) $chapter->summary,
            'schema_diagram' => (string) $chapter->schema_diagram,
            'dataset_id' => null,
        ];
    }

    /** Pré-remplit le schéma du chapitre avec le diagramme d'un jeu de données. */
    public function useDatasetDiagram(): void
    {
        $this->chapterForm['schema_diagram'] = (string) Dataset::find($this->chapterForm['dataset_id'])?->schema_diagram;
    }

    public function saveChapter(): void
    {
        $chapter = $this->chapter($this->editingChapterId);
        $this->validate([
            'chapterForm.title' => ['required', 'string', 'max:160'],
            'chapterForm.summary' => ['nullable', 'string', 'max:1000'],
            'chapterForm.schema_diagram' => ['nullable', 'string', 'max:20000'],
        ]);

        $chapter->update([
            'title' => $this->chapterForm['title'],
            'summary' => $this->chapterForm['summary'] ?: null,
            'schema_diagram' => $this->chapterForm['schema_diagram'] ?: null,
        ]);

        $this->editingChapterId = null;
    }

    public function moveChapter(int $chapterId, int $direction): void
    {
        $this->move($this->course->chapters()->orderBy('position')->orderBy('id')->get(), $this->chapter($chapterId)->id, $direction);
    }

    public function deleteChapter(int $chapterId): void
    {
        $chapter = $this->chapter($chapterId);

        if ($chapter->lessons()->exists()) {
            $this->addError('chapters', 'Supprimez ou déplacez d\'abord les leçons de ce chapitre.');

            return;
        }

        $chapter->delete();
    }

    public function addLesson(int $chapterId): void
    {
        $chapter = $this->chapter($chapterId);
        $title = trim($this->newLesson[$chapterId] ?? '');

        if ($title === '') {
            $this->addError("newLesson.{$chapterId}", 'Donnez un titre à la leçon.');

            return;
        }

        $lesson = $chapter->lessons()->create([
            'title' => $title,
            // Unique dans tout le cours : l'URL /cours/{cours}/{leçon} ne mentionne pas le chapitre.
            'slug' => $this->uniqueSlug($this->course->lessons()->withTrashed(), $title, 'lessons.slug'),
            'content_markdown' => "Introduction…\n\n```sql runnable\nSELECT 1;\n```\n",
            'position' => (int) $chapter->lessons()->max('position') + 1,
            'status' => ContentStatus::Draft,
        ]);

        $this->redirectRoute('admin.lessons.edit', $lesson, navigate: true);
    }

    public function moveLesson(int $lessonId, int $direction): void
    {
        $lesson = Lesson::whereIn('chapter_id', $this->course->chapters()->select('id'))->findOrFail($lessonId);
        $this->authorizeCourse();

        $this->move($lesson->chapter->lessons()->withTrashed()->orderBy('position')->orderBy('id')->get(), $lesson->id, $direction);
    }

    public function render()
    {
        return view('livewire.admin.courses.editor', [
            'levels' => Level::orderBy('position')->get(),
            'dialects' => SqlDialect::orderBy('position')->get(),
            'datasets' => Dataset::orderBy('name')->get(['id', 'name']),
            'statuses' => collect(ContentStatus::cases())->filter(fn ($s) => in_array($s->value, $this->allowedStatuses(), true)),
            'chapters' => $this->course?->chapters()->with(['lessons' => fn ($q) => $q->orderBy('position')->orderBy('id')])->orderBy('position')->orderBy('id')->get() ?? collect(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedStatuses(): array
    {
        $statuses = [ContentStatus::Draft->value, ContentStatus::InReview->value];

        // Un formateur ne peut pas publier, mais ne dépublie pas non plus un cours déjà en ligne.
        if (auth()->user()->can('publish', Course::class)) {
            return array_column(ContentStatus::cases(), 'value');
        }

        return $this->course && ! in_array($this->course->status->value, $statuses, true)
            ? [...$statuses, $this->course->status->value]
            : $statuses;
    }

    private function authorizeCourse(): void
    {
        abort_unless($this->course, 404);
        $this->authorize('update', $this->course);
    }

    private function chapter(?int $chapterId): Chapter
    {
        $this->authorizeCourse();

        return $this->course->chapters()->findOrFail($chapterId);
    }

    /**
     * @param  Collection<int, Model>  $items
     */
    private function move($items, int $id, int $direction): void
    {
        $items = $items->values();
        $index = $items->search(fn ($item) => $item->id === $id);
        $target = $index + ($direction < 0 ? -1 : 1);

        if ($index === false || ! isset($items[$target])) {
            return;
        }

        [$items[$index], $items[$target]] = [$items[$target], $items[$index]];
        $items->each(fn ($item, int $position) => $item->update(['position' => $position + 1]));
    }

    private function uniqueSlug($relation, string $title, string $column = 'slug'): string
    {
        $base = Str::slug($title) ?: 'element';
        $slug = $base;
        $suffix = 2;

        while ((clone $relation)->where($column, $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
