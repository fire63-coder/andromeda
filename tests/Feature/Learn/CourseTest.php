<?php

namespace Tests\Feature\Learn;

use App\Enums\ContentStatus;
use App\Enums\ProgressStatus;
use App\Livewire\Learn\CourseShow;
use App\Livewire\Learn\LessonViewer;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private User $student;

    private Course $course;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->student = User::factory()->create();
        $this->actingAs($this->student);
        $this->course = Course::where('slug', 'fondamentaux-du-sql')->firstOrFail();
        $this->lesson = Lesson::where('slug', 'filtrer-et-trier')->firstOrFail();
    }

    #[Test]
    public function the_catalog_and_course_page_list_published_content(): void
    {
        $this->get(route('courses.index'))->assertOk()->assertSee('Fondamentaux du SQL')->assertSee('Requêtes intermédiaires');

        Livewire::test(CourseShow::class, ['course' => $this->course])
            ->assertSee('Interroger une table')
            ->assertSee('Filtrer et trier')
            ->assertSee('Commencer');
    }

    #[Test]
    public function runnable_examples_execute_against_the_lesson_dataset_and_can_be_edited(): void
    {
        Livewire::test(LessonViewer::class, ['course' => $this->course, 'lesson' => $this->lesson])
            ->assertSet('snippets.0', "SELECT name, price\nFROM products\nWHERE category = 'Livres'\nORDER BY price DESC;")
            ->call('runSnippet', 0)
            ->assertSet('results.0.row_count', 3)
            ->set('snippets.0', 'SELECT COUNT(*) FROM customers')
            ->call('runSnippet', 0)
            ->assertSet('results.0.rows', [[7]])
            // Les exemples peuvent modifier la structure : tout est annulé après exécution.
            ->set('snippets.0', 'DROP TABLE order_items')
            ->call('runSnippet', 0)
            ->assertSet('results.0.success', true)
            ->set('snippets.0', 'SELECT COUNT(*) FROM order_items')
            ->call('runSnippet', 0)
            ->assertSet('results.0.rows', [[13]])
            ->set('snippets.0', "ATTACH DATABASE '/tmp/x.sqlite' AS x")
            ->call('runSnippet', 0)
            ->assertSet('results.0.error_type', 'rejected')
            ->call('resetSnippet', 0)
            ->assertDispatched('sql-editor:replace', model: 'snippets.0');
    }

    #[Test]
    public function completing_a_lesson_awards_xp_once_and_updates_course_progress(): void
    {
        $viewer = Livewire::test(LessonViewer::class, ['course' => $this->course, 'lesson' => $this->lesson])
            ->call('complete')
            ->assertDispatched('xp-gained', amount: 10, total: 10)
            ->assertSee('Leçon terminée')
            ->call('complete')
            ->assertNotDispatched('xp-gained');

        $this->assertSame(10, $this->student->fresh()->xp);
        $courseProgress = $this->course->progress()->where('user_id', $this->student->id)->first();
        $this->assertSame(ProgressStatus::Completed, $courseProgress->status);
        $this->assertSame(100, $courseProgress->progress_percent);
    }

    #[Test]
    public function draft_lessons_and_mismatched_urls_are_not_reachable(): void
    {
        $other = Course::where('slug', 'requetes-intermediaires')->firstOrFail();
        $this->get(route('lessons.show', [$other, $this->lesson]))->assertNotFound();

        $this->lesson->update(['status' => ContentStatus::Draft]);
        $this->get(route('lessons.show', [$this->course, $this->lesson]))->assertNotFound();
    }
}
