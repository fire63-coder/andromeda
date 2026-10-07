<?php

namespace Tests\Feature\Admin;

use App\Enums\ContentStatus;
use App\Enums\UserRole;
use App\Livewire\Admin\Certifications\CertificationEditor;
use App\Livewire\Admin\Challenges\ChallengeEditor;
use App\Models\Certification;
use App\Models\Challenge;
use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CompetitionAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => UserRole::Admin])->save();
        $this->actingAs($this->admin);
    }

    #[Test]
    public function certification_and_challenge_admin_is_reserved_to_admins(): void
    {
        $trainer = User::factory()->create();
        $trainer->forceFill(['role' => UserRole::Trainer])->save();

        foreach (['admin.certifications.index', 'admin.certifications.create', 'admin.challenges.index', 'admin.challenges.create'] as $route) {
            $this->actingAs($trainer)->get(route($route))->assertForbidden();
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }
    }

    #[Test]
    public function a_published_certification_needs_a_large_enough_pool(): void
    {
        $exercises = Exercise::published()->limit(3)->pluck('id')->all();

        $component = Livewire::test(CertificationEditor::class)
            ->set('title', 'Requêteur confirmé')
            ->set('exercisesCount', 5)
            ->set('pool', $exercises)
            ->set('status', ContentStatus::Published->value)
            ->call('save')
            ->assertHasErrors('pool');

        $component->set('exercisesCount', 3)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.certifications.edit', 'requeteur-confirme'));

        $certification = Certification::where('slug', 'requeteur-confirme')->firstOrFail();
        $this->assertEqualsCanonicalizing($exercises, $certification->exercisePool()->pluck('exercises.id')->all());
    }

    #[Test]
    public function an_admin_builds_an_ordered_challenge(): void
    {
        [$first, $second, $third] = Exercise::published()->limit(3)->pluck('id')->all();

        Livewire::test(ChallengeEditor::class)
            ->set('title', 'Sprint du vendredi')
            ->set('type', 'timed')
            ->set('startsAt', '2026-10-09T18:00')
            ->set('endsAt', '2026-10-09T17:00')
            ->set('durationMinutes', 20)
            ->set('xpMultiplier', '1.5')
            ->call('addExercise', $first)
            ->call('addExercise', $second)
            ->call('addExercise', $third)
            ->call('addExercise', $first)
            ->set('items.2.points', 300)
            ->call('moveExercise', 2, -1)
            ->call('removeExercise', 0)
            ->set('status', ContentStatus::Published->value)
            ->call('save')
            ->assertHasErrors('endsAt')
            ->set('endsAt', '2026-10-09T20:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.challenges.edit', 'sprint-du-vendredi'));

        $challenge = Challenge::where('slug', 'sprint-du-vendredi')->firstOrFail();
        $this->assertSame(1200, $challenge->duration_seconds);
        $this->assertSame($this->admin->id, $challenge->created_by);
        $this->assertSame(
            [[$third, 300, 0], [$second, 100, 1]],
            $challenge->exercises->map(fn ($e) => [$e->id, (int) $e->pivot->points, (int) $e->pivot->position])->all(),
        );
    }

    #[Test]
    public function a_published_challenge_only_contains_published_exercises(): void
    {
        $draft = Exercise::first();
        $draft->update(['status' => ContentStatus::Draft]);

        Livewire::test(ChallengeEditor::class)
            ->set('title', 'Défi vide')
            ->set('status', ContentStatus::Published->value)
            ->call('save')
            ->assertHasErrors('items')
            ->call('addExercise', $draft->id)
            ->call('save')
            ->assertHasErrors('items')
            ->set('status', ContentStatus::Draft->value)
            ->call('save')
            ->assertHasNoErrors();
    }
}
