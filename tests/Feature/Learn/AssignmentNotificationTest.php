<?php

namespace Tests\Feature\Learn;

use App\Actions\Assignments\SendAssignmentNotifications;
use App\Enums\ProgressStatus;
use App\Livewire\Admin\Assignments\AssignmentEditor;
use App\Livewire\Profile\NotificationPreferences;
use App\Models\Exercise;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserProgress;
use App\Notifications\AssignmentDueSoon;
use App\Notifications\AssignmentPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $manager;

    private User $student;

    private User $optedOut;

    private User $disabled;

    private Exercise $exercise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();

        $this->manager = User::factory()->create();
        $this->student = User::factory()->create();
        $this->optedOut = User::factory()->create();
        $this->optedOut->forceFill(['assignment_emails' => false])->save();
        $this->disabled = User::factory()->create();
        $this->disabled->forceFill(['is_active' => false])->save();

        $this->organization = Organization::create(['name' => 'Terminale', 'slug' => 'terminale', 'invite_code' => Organization::generateInviteCode()]);
        $this->organization->members()->attach([
            $this->manager->id => ['role' => 'manager'],
            $this->student->id => ['role' => 'member'],
            $this->optedOut->id => ['role' => 'member'],
            $this->disabled->id => ['role' => 'member'],
        ]);

        $this->exercise = Exercise::where('slug', 'clients-de-lyon')->firstOrFail();
    }

    #[Test]
    public function students_are_emailed_once_when_an_assignment_is_published(): void
    {
        $editor = Livewire::actingAs($this->manager)->test(AssignmentEditor::class, ['organization' => $this->organization])
            ->set('title', 'Révisions')
            ->call('add', $this->exercise->id)
            ->call('save');

        Notification::assertNothingSent();

        $assignment = $this->organization->assignments()->firstOrFail();
        Livewire::actingAs($this->manager)->test(AssignmentEditor::class, ['organization' => $this->organization, 'assignment' => $assignment])
            ->set('published', true)
            ->call('save')
            ->assertSet('saved', 'Devoir publié : 2 élève(s) prévenu(s).');

        // Notification dans l'application pour tous les élèves actifs ; l'e-mail seulement pour ceux qui l'acceptent.
        Notification::assertSentTo($this->student, AssignmentPublished::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($this->optedOut, AssignmentPublished::class, fn ($n, array $channels) => $channels === ['database']);
        Notification::assertNotSentTo([$this->manager, $this->disabled], AssignmentPublished::class);

        // Une modification ultérieure ne renvoie rien.
        Livewire::actingAs($this->manager)->test(AssignmentEditor::class, ['organization' => $this->organization, 'assignment' => $assignment->fresh()])
            ->set('title', 'Révisions (corrigé)')
            ->call('save');
        Notification::assertSentToTimes($this->student, AssignmentPublished::class, 1);

        $mail = (new AssignmentPublished($assignment->fresh()))->toMail($this->student);
        $this->assertSame('Nouveau devoir : Révisions (corrigé)', $mail->subject);
        $this->assertSame(route('assignments.show', $assignment), $mail->actionUrl);
    }

    #[Test]
    public function a_reminder_is_sent_the_day_before_to_students_who_have_not_finished(): void
    {
        $finisher = User::factory()->create();
        $this->organization->members()->attach($finisher->id, ['role' => 'member']);
        UserProgress::create([
            'user_id' => $finisher->id, 'progressable_type' => $this->exercise->getMorphClass(), 'progressable_id' => $this->exercise->id,
            'status' => ProgressStatus::Completed, 'completed_at' => now(),
        ]);

        $soon = $this->organization->assignments()->create(['title' => 'Demain', 'due_at' => now()->addHours(10), 'published_at' => now(), 'notified_at' => now()]);
        $later = $this->organization->assignments()->create(['title' => 'Plus tard', 'due_at' => now()->addDays(3), 'published_at' => now(), 'notified_at' => now()]);
        $draft = $this->organization->assignments()->create(['title' => 'Brouillon', 'due_at' => now()->addHours(5)]);
        foreach ([$soon, $later, $draft] as $assignment) {
            $assignment->exercises()->attach($this->exercise->id, ['position' => 0]);
        }

        $this->artisan('assignments:remind')->expectsOutputToContain('2 rappel(s)')->assertSuccessful();

        Notification::assertSentTo($this->student, AssignmentDueSoon::class, fn (AssignmentDueSoon $n) => $n->assignment->is($soon) && $n->done === 0 && $n->total === 1);
        Notification::assertSentTo($this->optedOut, AssignmentDueSoon::class, fn ($n, array $channels) => $channels === ['database']);
        Notification::assertNotSentTo([$finisher, $this->disabled, $this->manager], AssignmentDueSoon::class);

        // Une seule fois par devoir.
        $this->assertSame(0, app(SendAssignmentNotifications::class)->dueSoon());
        $this->assertNotNull($soon->fresh()->reminded_at);
        $this->assertNull($later->fresh()->reminded_at);
    }

    #[Test]
    public function students_can_turn_assignment_emails_off_from_their_profile(): void
    {
        $this->actingAs($this->student)->get(route('profile.show'))->assertSee('Notifications');

        Livewire::actingAs($this->student)->test(NotificationPreferences::class)
            ->assertSet('assignmentEmails', true)
            ->set('assignmentEmails', false)
            ->assertSee('E-mails des devoirs désactivés.');

        $this->assertFalse($this->student->fresh()->assignment_emails);
    }
}
