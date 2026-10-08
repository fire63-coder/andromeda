<?php

namespace Tests\Feature\Learn;

use App\Enums\ProgressStatus;
use App\Enums\SubmissionStatus;
use App\Livewire\Admin\Assignments\AssignmentEditor;
use App\Models\Assignment;
use App\Models\Exercise;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserProgress;
use App\Models\UserSubmission;
use App\Services\Learning\AssignmentProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $student;

    private Exercise $lyon;

    private Exercise $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->manager = User::factory()->create();
        $this->student = User::factory()->create();
        $this->organization = Organization::create(['name' => 'BTS SIO', 'slug' => 'bts-sio', 'invite_code' => Organization::generateInviteCode()]);
        $this->organization->members()->attach([$this->manager->id => ['role' => 'manager'], $this->student->id => ['role' => 'member']]);

        $this->lyon = Exercise::where('slug', 'clients-de-lyon')->firstOrFail();
        $this->revenue = Exercise::where('slug', 'chiffre-affaires-par-client')->firstOrFail();
    }

    private function assignment(array $attributes = []): Assignment
    {
        $assignment = $this->organization->assignments()->create([
            'title' => 'Révisions', 'due_at' => now()->addDays(3), 'published_at' => now(), ...$attributes,
        ]);
        $assignment->exercises()->sync([$this->lyon->id => ['position' => 0], $this->revenue->id => ['position' => 1]]);

        return $assignment->fresh();
    }

    #[Test]
    public function a_manager_builds_and_publishes_an_assignment_of_practice_exercises(): void
    {
        $component = Livewire::actingAs($this->manager)->test(AssignmentEditor::class, ['organization' => $this->organization])
            ->set('title', 'Les jointures')
            ->set('published', true)
            ->call('save')
            ->assertHasErrors('exerciseIds')
            ->call('add', Exercise::where('slug', 'cert-panier-moyen')->value('id'))   // réservé à la certification : ignoré
            ->call('add', $this->revenue->id)
            ->call('add', $this->lyon->id)
            ->call('move', 1, -1)
            ->call('save')
            ->assertHasNoErrors();

        $assignment = Assignment::where('title', 'Les jointures')->firstOrFail();
        $component->assertRedirect(route('admin.assignments.edit', [$this->organization, $assignment]));
        $this->assertSame([$this->lyon->id, $this->revenue->id], $assignment->exercises->pluck('id')->all());
        $this->assertNotNull($assignment->published_at);

        // Une modification conserve la date de publication d'origine.
        $publishedAt = $assignment->published_at;
        $this->travel(1)->day();
        Livewire::actingAs($this->manager)->test(AssignmentEditor::class, ['organization' => $this->organization, 'assignment' => $assignment])
            ->set('title', 'Les jointures (corrigé)')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertEquals($publishedAt, $assignment->fresh()->published_at);
    }

    #[Test]
    public function students_see_published_assignments_of_their_organizations_only(): void
    {
        $published = $this->assignment();
        $draft = $this->assignment(['title' => 'Brouillon', 'published_at' => null]);
        $outsider = User::factory()->create();

        $this->actingAs($this->student)->get(route('dashboard'))->assertSee('Devoirs à rendre')->assertSee('Révisions')->assertDontSee('Brouillon');
        $this->actingAs($this->student)->get(route('assignments.show', $published))->assertOk()->assertSee('Les clients lyonnais');
        $this->actingAs($this->student)->get(route('assignments.show', $draft))->assertForbidden();
        $this->actingAs($outsider)->get(route('assignments.show', $published))->assertForbidden();

        $this->actingAs($this->student)->get(route('admin.assignments.results', [$this->organization, $published]))->assertForbidden();
        $this->actingAs($this->manager)->get(route('admin.assignments.results', [$this->organization, $published]))->assertOk();

        $other = Organization::create(['name' => 'Autre', 'slug' => 'autre', 'invite_code' => Organization::generateInviteCode()]);
        $other->members()->attach($this->manager->id, ['role' => 'manager']);
        $this->actingAs($this->manager)->get(route('admin.assignments.results', [$other, $published]))->assertNotFound();
    }

    #[Test]
    public function the_matrix_distinguishes_on_time_late_tried_and_todo(): void
    {
        $assignment = $this->assignment(['due_at' => now()->subDay()]);
        $late = User::factory()->create();
        $this->organization->members()->attach($late->id, ['role' => 'member']);

        $complete = fn (User $user, Exercise $exercise, $at) => UserProgress::create([
            'user_id' => $user->id, 'progressable_type' => $exercise->getMorphClass(), 'progressable_id' => $exercise->id,
            'status' => ProgressStatus::Completed, 'completed_at' => $at,
        ]);

        $complete($this->student, $this->lyon, now()->subDays(3));
        UserSubmission::create(['user_id' => $this->student->id, 'exercise_id' => $this->revenue->id, 'status' => SubmissionStatus::Wrong]);
        $complete($late, $this->lyon, now());

        $matrix = app(AssignmentProgress::class)->matrix($assignment, [$this->student->id, $late->id]);

        $this->assertSame([$this->lyon->id => 'on_time', $this->revenue->id => 'tried'], $matrix[$this->student->id]);
        $this->assertSame([$this->lyon->id => 'late', $this->revenue->id => 'todo'], $matrix[$late->id]);

        $this->assertSame(['done' => 1, 'total' => 2, 'percent' => 50], array_intersect_key(
            app(AssignmentProgress::class)->forUser($assignment, $this->student), ['done' => 0, 'total' => 0, 'percent' => 0],
        ));

        // Terminé : n'apparaît plus dans les devoirs à rendre.
        $complete($this->student, $this->revenue, now());
        $this->assertTrue(app(AssignmentProgress::class)->pendingFor($this->student)->isEmpty());
    }
}
