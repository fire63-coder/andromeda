<?php

namespace Tests\Feature\Admin;

use App\Enums\SubmissionStatus;
use App\Livewire\Admin\Organizations\OrganizationProgress;
use App\Models\Exercise;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserSubmission;
use App\Services\Analytics\GroupAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationProgressTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $alice;

    private User $bruno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->manager = User::factory()->create(['name' => 'Mme Prof']);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bruno = User::factory()->create(['name' => 'Bruno']);
        $this->alice->forceFill(['xp' => 120])->save();

        $this->organization = Organization::create(['name' => 'Terminale B', 'slug' => 'terminale-b', 'invite_code' => Organization::generateInviteCode()]);
        $this->organization->members()->attach([
            $this->manager->id => ['role' => 'manager'],
            $this->alice->id => ['role' => 'member'],
            $this->bruno->id => ['role' => 'member'],
        ]);

        $lyon = Exercise::where('slug', 'clients-de-lyon')->firstOrFail();
        $bug = Exercise::where('slug', 'categories-bien-fournies')->firstOrFail();

        // Alice réussit « clients de Lyon » au 2e essai ; Bruno échoue deux fois sur le bug ; Alice aussi une fois.
        $this->submit($this->alice, $lyon, SubmissionStatus::Wrong, 'Les lignes ne sont pas dans le bon ordre.', daysAgo: 2);
        $this->submit($this->alice, $lyon, SubmissionStatus::Correct, null, daysAgo: 2);
        $this->submit($this->bruno, $bug, SubmissionStatus::Error, 'misuse of aggregate: COUNT()', daysAgo: 1);
        $this->submit($this->bruno, $bug, SubmissionStatus::Error, 'misuse of aggregate: COUNT()', daysAgo: 0);
        $this->submit($this->alice, $bug, SubmissionStatus::Wrong, 'Le nombre de lignes est incorrect.', daysAgo: 20);

        // Un élève hors organisation ne compte pas.
        $this->submit(User::factory()->create(), $bug, SubmissionStatus::Correct, null, daysAgo: 0);
    }

    private function submit(User $user, Exercise $exercise, SubmissionStatus $status, ?string $message, int $daysAgo): void
    {
        $submission = new UserSubmission([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
            'query_sql' => 'SELECT 1',
            'status' => $status,
            'is_correct' => $status === SubmissionStatus::Correct,
            'score' => $status === SubmissionStatus::Correct ? 100 : 0,
            'feedback' => ['message' => $message ?? 'Bravo !'],
            'error_message' => $status === SubmissionStatus::Error ? $message : null,
        ]);
        $submission->created_at = $submission->updated_at = now()->subDays($daysAgo);
        $submission->save();
    }

    #[Test]
    public function only_admins_and_managers_of_the_organization_see_its_progress(): void
    {
        $this->actingAs($this->manager)->get(route('admin.organizations.progress', $this->organization))->assertOk()->assertSee('Suivi pédagogique');
        $this->actingAs($this->alice)->get(route('admin.organizations.progress', $this->organization))->assertForbidden();
        $this->actingAs($this->alice)->get(route('admin.organizations.progress.export', $this->organization))->assertForbidden();

        $outsider = User::factory()->create();
        $this->actingAs($this->manager)->get(route('admin.organizations.member', [$this->organization, $outsider->id]))->assertNotFound();
        $this->actingAs($this->manager)->get(route('admin.organizations.member', [$this->organization, $this->bruno->id]))
            ->assertOk()->assertSee('misuse of aggregate');
    }

    #[Test]
    public function summary_and_activity_only_count_the_members(): void
    {
        $analytics = GroupAnalytics::for($this->organization);
        $summary = $analytics->summary();

        $this->assertSame(3, $summary['members']);
        $this->assertSame(2, $summary['active_7d']);
        $this->assertSame(4, $summary['submissions_7d']);
        $this->assertSame(25, $summary['success_rate_7d']);
        $this->assertSame(1, $summary['solved_total']);

        $activity = $analytics->activity(30);
        $this->assertCount(30, $activity);
        $this->assertSame(5, array_sum(array_column($activity, 'submissions')));
        $this->assertSame(['submissions' => 2, 'correct' => 1], array_intersect_key(collect($activity)->firstWhere('date', now()->subDays(2)->toDateString()), ['submissions' => 0, 'correct' => 0]));
    }

    #[Test]
    public function members_are_listed_with_their_progress_and_can_be_sorted(): void
    {
        $members = GroupAnalytics::for($this->organization)->members('struggling')->keyBy('name');

        $this->assertEquals(['solved' => 1, 'submissions' => 3, 'success_rate' => 33, 'xp' => 120], array_intersect_key($members['Alice'], array_flip(['solved', 'submissions', 'success_rate', 'xp'])));
        $this->assertSame(0, $members['Bruno']['success_rate']);
        $this->assertNull($members['Mme Prof']['success_rate']);
        $this->assertSame(['Bruno', 'Alice', 'Mme Prof'], GroupAnalytics::for($this->organization)->members('struggling')->pluck('name')->all());

        Livewire::actingAs($this->manager)->test(OrganizationProgress::class, ['organization' => $this->organization])
            ->set('search', 'bru')
            ->assertSee('Bruno')
            ->assertDontSee('Alice');
    }

    #[Test]
    public function struggling_exercises_show_the_most_frequent_failures(): void
    {
        $struggles = GroupAnalytics::for($this->organization)->struggles();

        $this->assertSame('categories-bien-fournies', $struggles->first()['exercise']->slug);
        $this->assertEquals(['tried' => 2, 'solved' => 0, 'attempts' => 3], array_intersect_key($struggles->first(), array_flip(['tried', 'solved', 'attempts'])));
        $this->assertSame(['misuse of aggregate: COUNT()' => 2, 'Le nombre de lignes est incorrect.' => 1], $struggles->first()['top_errors']);
        // Résolu par tous ceux qui l'ont essayé : n'apparaît pas.
        $this->assertNotContains('clients-de-lyon', $struggles->pluck('exercise.slug'));
    }

    #[Test]
    public function skill_mastery_is_the_share_of_solved_practice_exercises(): void
    {
        $skills = GroupAnalytics::for($this->organization)->skills()->keyBy(fn ($row) => $row['skill']->slug);

        // « select » : clients-de-lyon est le seul exercice d'entraînement ; 1 élève sur 3 l'a résolu.
        $this->assertSame(33, $skills['select']['mastery']);
        $this->assertSame(0, $skills['aggregation']['mastery']);
    }

    #[Test]
    public function the_progress_can_be_exported_as_csv(): void
    {
        $response = $this->actingAs($this->manager)->get(route('admin.organizations.progress.export', $this->organization));

        $response->assertOk()->assertDownload('suivi-terminale-b-'.now()->format('Y-m-d').'.csv');
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFNom;E-mail;", $csv);
        $this->assertStringContainsString('Alice;', $csv);
        $this->assertSame(4, substr_count(trim($csv), "\n") + 1);
    }
}
