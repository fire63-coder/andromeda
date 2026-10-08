<?php

namespace Tests\Feature\Notifications;

use App\Livewire\Notifications\NotificationBell;
use App\Livewire\Notifications\NotificationCenter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function notify(User $user, string $title, string $url = '/certifications', ?string $readAt = null): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'data' => ['kind' => 'test', 'icon' => '🔔', 'title' => $title, 'body' => "Détail de {$title}", 'url' => $url],
            'read_at' => $readAt,
        ]);
    }

    #[Test]
    public function the_bell_counts_unread_notifications_and_opens_them(): void
    {
        $first = $this->notify($this->user, 'Premier');
        $this->notify($this->user, 'Second');
        $this->notify($this->user, 'Ancien', readAt: now()->subDay());

        $this->get(route('dashboard'))->assertOk()->assertSeeLivewire(NotificationBell::class);

        Livewire::test(NotificationBell::class)
            ->assertSeeHtml('data-testid="notification-count"')
            ->assertSee(['Premier', 'Second', 'Ancien'])
            ->assertSet('unreadCount', 2)
            ->call('open', $first->id)
            ->assertRedirect('/certifications')
            ->assertDispatched('notifications-read');

        $this->assertNotNull($first->fresh()->read_at);

        Livewire::test(NotificationBell::class)
            ->call('markAllAsRead')
            ->assertSet('unreadCount', 0)
            ->assertDontSeeHtml('data-testid="notification-count"');
    }

    #[Test]
    public function the_center_filters_toggles_and_deletes(): void
    {
        $unread = $this->notify($this->user, 'À lire');
        $read = $this->notify($this->user, 'Déjà lue', readAt: now()->subDay());

        $this->get(route('notifications.index'))->assertOk()->assertSee(['À lire', 'Déjà lue', '1 non lue']);

        $component = Livewire::test(NotificationCenter::class)
            ->set('unread', true)
            ->assertSee('À lire')
            ->assertDontSee('Déjà lue');

        $component->call('toggleRead', $unread->id);
        $this->assertNotNull($unread->fresh()->read_at);
        $component->call('toggleRead', $unread->id);
        $this->assertNull($unread->fresh()->read_at);

        $component->call('delete', $unread->id);
        $this->assertNull($unread->fresh());

        $component->call('deleteRead');
        $this->assertNull($read->fresh());
        $this->assertSame(0, $this->user->notifications()->count());
    }

    #[Test]
    public function notifications_of_other_users_are_out_of_reach(): void
    {
        $other = $this->notify(User::factory()->create(), 'Privée');

        Livewire::test(NotificationCenter::class)->assertDontSee('Privée');

        foreach (['open', 'toggleRead', 'delete'] as $action) {
            Livewire::test(NotificationCenter::class)->call($action, $other->id)->assertNotFound();
        }

        $this->assertNull($other->fresh()->read_at);
    }

    #[Test]
    public function only_internal_links_are_followed(): void
    {
        $external = $this->notify($this->user, 'Piège', 'https://example.com/phishing');
        $protocolRelative = $this->notify($this->user, 'Piège 2', '//example.com');

        Livewire::test(NotificationBell::class)->call('open', $external->id)->assertNoRedirect();
        Livewire::test(NotificationBell::class)->call('open', $protocolRelative->id)->assertNoRedirect();

        $this->assertNotNull($external->fresh()->read_at);
    }

    #[Test]
    public function old_read_notifications_are_pruned(): void
    {
        $old = $this->notify($this->user, 'Vieille', readAt: now()->subDays(120));
        $recent = $this->notify($this->user, 'Récente', readAt: now()->subDays(5));
        $unread = $this->notify($this->user, 'Non lue');
        $unread->forceFill(['created_at' => now()->subYear()])->save();

        $this->artisan('notifications:prune')->expectsOutputToContain('1 notification(s)')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($unread->fresh());
    }
}
