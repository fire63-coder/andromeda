<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Livewire\Admin\Users\UserIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = $this->user(UserRole::Admin);
    }

    private function user(UserRole $role = UserRole::Student): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    #[Test]
    public function only_admins_can_open_user_management(): void
    {
        $this->actingAs($this->user(UserRole::Trainer))->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->user())->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertOk()->assertSee('Utilisateurs');
    }

    #[Test]
    public function an_admin_changes_roles_and_filters_the_list(): void
    {
        $student = $this->user();

        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->call('changeRole', $student->id, 'trainer')
            ->assertHasNoErrors()
            ->set('role', 'trainer')
            ->assertSee($student->email);

        $this->assertSame(UserRole::Trainer, $student->fresh()->role);
    }

    #[Test]
    public function an_admin_cannot_change_their_own_account(): void
    {
        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->call('toggleActive', $this->admin->id)
            ->assertForbidden();

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    #[Test]
    public function admins_can_demote_each_other_but_a_disabled_admin_is_locked_out(): void
    {
        $other = $this->user(UserRole::Admin);

        Livewire::actingAs($this->admin)->test(UserIndex::class)
            ->call('changeRole', $other->id, 'student')
            ->assertHasNoErrors();

        $this->assertSame(UserRole::Student, $other->fresh()->role);

        // Un administrateur désactivé en cours de session perd immédiatement la main.
        $other->forceFill(['role' => UserRole::Admin])->save();
        $this->admin->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    #[Test]
    public function a_disabled_account_cannot_log_in(): void
    {
        $student = $this->user();
        $student->forceFill(['is_active' => false])->save();

        $this->post('/login', ['email' => $student->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function a_session_is_closed_as_soon_as_the_account_is_disabled(): void
    {
        $student = $this->user();
        $this->actingAs($student)->get(route('dashboard'))->assertOk();

        $student->forceFill(['is_active' => false])->save();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
