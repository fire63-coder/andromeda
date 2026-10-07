<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Livewire\Admin\Organizations\OrganizationIndex;
use App\Livewire\Admin\Organizations\OrganizationShow;
use App\Livewire\Profile\OrganizationsForm;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => UserRole::Admin])->save();
    }

    private function organization(?User $manager = null): Organization
    {
        $organization = Organization::create([
            'name' => 'Lycée Turing', 'slug' => 'lycee-turing', 'invite_code' => Organization::generateInviteCode(),
        ]);

        if ($manager) {
            $organization->members()->attach($manager->id, ['role' => 'manager']);
        }

        return $organization;
    }

    #[Test]
    public function an_admin_creates_an_organization_with_an_invite_code(): void
    {
        Livewire::actingAs($this->admin)->test(OrganizationIndex::class)
            ->set('name', 'IUT de Lannion')
            ->call('create')
            ->assertRedirect(route('admin.organizations.show', 'iut-de-lannion'));

        $organization = Organization::where('slug', 'iut-de-lannion')->firstOrFail();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $organization->invite_code);

        Livewire::actingAs($this->admin)->test(OrganizationIndex::class)
            ->set('name', 'IUT de Lannion')
            ->call('create')
            ->assertHasErrors('name');
    }

    #[Test]
    public function a_student_joins_with_the_code_and_a_manager_keeps_their_role(): void
    {
        $manager = User::factory()->create();
        $organization = $this->organization($manager);
        $student = User::factory()->create();

        Livewire::actingAs($student)->test(OrganizationsForm::class)
            ->set('code', strtolower($organization->invite_code))
            ->call('join')
            ->assertHasNoErrors()
            ->assertSee('Lycée Turing');

        $this->assertSame('member', $organization->members()->find($student->id)->pivot->role);

        Livewire::actingAs($manager)->test(OrganizationsForm::class)
            ->set('code', $organization->invite_code)
            ->call('join');

        $this->assertSame('manager', $organization->members()->find($manager->id)->pivot->role);

        Livewire::actingAs($student)->test(OrganizationsForm::class)
            ->set('code', 'ZZZZ-ZZZZ')
            ->call('join')
            ->assertHasErrors('code');
    }

    #[Test]
    public function a_regenerated_code_invalidates_the_old_one(): void
    {
        $manager = User::factory()->create();
        $organization = $this->organization($manager);
        $old = $organization->invite_code;

        Livewire::actingAs($manager)->test(OrganizationShow::class, ['organization' => $organization])
            ->call('regenerateCode');

        $this->assertNotSame($old, $organization->fresh()->invite_code);

        Livewire::actingAs(User::factory()->create())->test(OrganizationsForm::class)
            ->set('code', $old)
            ->call('join')
            ->assertHasErrors('code');
    }

    #[Test]
    public function a_manager_only_manages_their_own_organization(): void
    {
        $manager = User::factory()->create();
        $mine = $this->organization($manager);
        $other = Organization::create(['name' => 'Autre', 'slug' => 'autre', 'invite_code' => Organization::generateInviteCode()]);
        $student = User::factory()->create();

        $this->actingAs($manager)->get(route('admin.organizations.index'))->assertOk()->assertSee('Lycée Turing')->assertDontSee('Autre');
        $this->actingAs($manager)->get(route('admin.organizations.show', $mine))->assertOk();
        $this->actingAs($manager)->get(route('admin.organizations.show', $other))->assertForbidden();
        $this->actingAs($student)->get(route('admin.organizations.index'))->assertForbidden();

        Livewire::actingAs($manager)->test(OrganizationShow::class, ['organization' => $mine])
            ->set('email', $student->email)
            ->call('addMember')
            ->assertHasNoErrors()
            ->call('setRole', $student->id, 'manager')
            ->call('delete')
            ->assertForbidden();

        $this->assertSame('manager', $mine->members()->find($student->id)->pivot->role);
    }

    #[Test]
    public function an_organization_keeps_at_least_one_manager(): void
    {
        $manager = User::factory()->create();
        $organization = $this->organization($manager);
        $member = User::factory()->create();
        $organization->members()->attach($member->id, ['role' => 'member']);

        Livewire::actingAs($manager)->test(OrganizationShow::class, ['organization' => $organization])
            ->call('setRole', $manager->id, 'member')
            ->assertHasErrors('members')
            ->call('removeMember', $manager->id)
            ->assertHasErrors('members');

        Livewire::actingAs($manager)->test(OrganizationsForm::class)
            ->call('leave', $organization->id)
            ->assertHasErrors('code');

        $this->assertSame('manager', $organization->members()->find($manager->id)->pivot->role);

        Livewire::actingAs($member)->test(OrganizationsForm::class)->call('leave', $organization->id);
        $this->assertNull($organization->members()->find($member->id));
    }
}
