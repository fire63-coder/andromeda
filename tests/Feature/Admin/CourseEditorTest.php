<?php

namespace Tests\Feature\Admin;

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\Courses\CourseEditor;
use App\Livewire\Admin\Courses\LessonEditor;
use App\Models\Course;
use App\Models\Dataset;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class CourseEditorTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->trainer = $this->user(UserRole::Trainer);
        $this->actingAs($this->trainer);
    }

    private function user(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    #[Test]
    public function a_trainer_builds_a_course_with_chapters_and_lessons(): void
    {
        Livewire::test(CourseEditor::class)
            ->set('title', 'Fonctions de fenêtrage')
            ->assertSet('slug', 'fonctions-de-fenetrage')
            ->set('levelId', Level::where('position', 3)->value('id'))
            ->call('save')
            ->assertRedirect(route('admin.courses.edit', 'fonctions-de-fenetrage'));

        $course = Course::where('slug', 'fonctions-de-fenetrage')->firstOrFail();
        $this->assertSame($this->trainer->id, $course->author_id);
        $this->assertSame(ContentStatus::Draft, $course->status);

        $editor = Livewire::test(CourseEditor::class, ['course' => $course])
            ->set('newChapter', 'ROW_NUMBER et RANK')->call('addChapter')
            ->set('newChapter', 'LAG et LEAD')->call('addChapter');

        [$first, $second] = $course->chapters()->orderBy('position')->get();
        $editor->call('moveChapter', $second->id, -1);
        $this->assertSame(['LAG et LEAD', 'ROW_NUMBER et RANK'], $course->chapters()->orderBy('position')->pluck('title')->all());

        $editor->set("newLesson.{$first->id}", 'Numéroter les lignes')->call('addLesson', $first->id);
        $lesson = Lesson::where('slug', 'numeroter-les-lignes')->firstOrFail();
        $editor->assertRedirect(route('admin.lessons.edit', $lesson));
        $this->assertSame(ContentStatus::Draft, $lesson->status);
    }

    #[Test]
    public function trainers_submit_for_review_and_only_admins_publish(): void
    {
        $course = Course::create(['level_id' => Level::value('id'), 'title' => 'Brouillon', 'slug' => 'brouillon', 'status' => 'draft', 'author_id' => $this->trainer->id]);

        Livewire::test(CourseEditor::class, ['course' => $course])
            ->set('status', 'published')->call('save')->assertHasErrors('status')
            ->set('status', 'in_review')->call('save')->assertHasNoErrors();

        $this->assertSame(ContentStatus::InReview, $course->fresh()->status);

        Livewire::actingAs($this->user(UserRole::Admin))
            ->test(CourseEditor::class, ['course' => $course->fresh()])
            ->set('status', 'published')->call('save')->assertHasNoErrors();

        $this->assertSame(ContentStatus::Published, $course->fresh()->status);
        $this->assertNotNull($course->fresh()->published_at);
    }

    #[Test]
    public function trainers_cannot_edit_someone_elses_course_and_students_are_kept_out(): void
    {
        $theirs = Course::where('slug', 'fondamentaux-du-sql')->firstOrFail(); // sans auteur : réservé aux admins

        $this->get(route('admin.courses.edit', $theirs))->assertForbidden();

        $student = User::factory()->create();
        $this->actingAs($student)->get(route('admin.courses.index'))->assertForbidden();
    }

    #[Test]
    public function the_lesson_editor_previews_markdown_and_checks_runnable_examples(): void
    {
        $course = Course::create(['level_id' => Level::value('id'), 'title' => 'Essai', 'slug' => 'essai', 'status' => 'draft', 'author_id' => $this->trainer->id]);
        $chapter = $course->chapters()->create(['title' => 'Chapitre', 'slug' => 'chapitre']);
        $lesson = $chapter->lessons()->create(['title' => 'Leçon', 'slug' => 'lecon', 'content_markdown' => 'x', 'status' => 'draft']);

        Livewire::test(LessonEditor::class, ['lesson' => $lesson])
            ->set('content', "## Partie 1\n\n```sql runnable\nSELECT COUNT(*) FROM customers;\n```\n\n```sql runnable\nSELECT nope FROM customers;\n```")
            ->assertSee('Partie 1')
            ->assertSee('Exemple exécutable n° 2')
            ->call('checkSnippets')
            ->assertSee('Choisissez un jeu de données')
            ->set('datasetId', Dataset::where('slug', 'boutique')->value('id'))
            ->call('checkSnippets')
            ->assertSet('checks.0.ok', true)
            ->assertSet('checks.1.ok', false)
            ->assertSee('no such column: nope')
            ->set('status', 'published')->call('save')->assertHasErrors('status')
            ->set('status', 'in_review')->call('save')->assertHasNoErrors();

        $lesson->refresh();
        $this->assertStringContainsString('SELECT COUNT(*) FROM customers;', $lesson->content_markdown);
        $this->assertStringContainsString('<h2>Partie 1</h2>', $lesson->content_html);
        $this->assertSame(ContentStatus::InReview, $lesson->status);
    }

    #[Test]
    public function lesson_slugs_are_unique_within_a_course(): void
    {
        $course = Course::create(['level_id' => Level::value('id'), 'title' => 'Essai', 'slug' => 'essai', 'status' => 'draft', 'author_id' => $this->trainer->id]);
        $a = $course->chapters()->create(['title' => 'A', 'slug' => 'a']);
        $b = $course->chapters()->create(['title' => 'B', 'slug' => 'b']);

        Livewire::test(CourseEditor::class, ['course' => $course])
            ->set("newLesson.{$a->id}", 'Introduction')->call('addLesson', $a->id);
        Livewire::test(CourseEditor::class, ['course' => $course])
            ->set("newLesson.{$b->id}", 'Introduction')->call('addLesson', $b->id);

        $this->assertEqualsCanonicalizing(['introduction', 'introduction-2'], $course->lessons()->pluck('lessons.slug')->all());
    }
}
