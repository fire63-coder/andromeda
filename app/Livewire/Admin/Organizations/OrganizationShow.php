<?php

namespace App\Livewire\Admin\Organizations;

use App\Models\Organization;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Organisation')]
class OrganizationShow extends Component
{
    #[Locked]
    public Organization $organization;

    public string $name = '';

    public string $email = '';

    public ?string $flash = null;

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
        $this->name = $organization->name;
    }

    public function rename(): void
    {
        $this->authorize('update', $this->organization);
        $this->validate(['name' => ['required', 'string', 'max:120']], attributes: ['name' => 'nom']);
        $this->organization->update(['name' => $this->name]);
        $this->flash = 'Nom enregistré.';
    }

    public function regenerateCode(): void
    {
        $this->authorize('update', $this->organization);
        $this->organization->update(['invite_code' => Organization::generateInviteCode()]);
        $this->flash = 'Nouveau code d\'invitation généré : l\'ancien ne fonctionne plus.';
    }

    public function addMember(): void
    {
        $this->authorize('update', $this->organization);
        $this->validate(['email' => ['required', 'email']], attributes: ['email' => 'adresse e-mail']);

        $user = User::where('email', $this->email)->first();

        if (! $user) {
            $this->addError('email', 'Aucun compte avec cette adresse : partagez plutôt le code d\'invitation.');

            return;
        }

        if ($this->organization->members()->whereKey($user->id)->exists()) {
            $this->addError('email', "{$user->name} fait déjà partie de l'organisation.");

            return;
        }

        $this->organization->members()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);
        $this->reset('email');
        $this->flash = "{$user->name} a été ajouté.";
    }

    public function setRole(int $userId, string $role): void
    {
        $this->authorize('update', $this->organization);
        abort_unless(in_array($role, ['member', 'manager'], true), 422);

        if ($role === 'member' && $this->isLastManager($userId) && ! auth()->user()->isAdmin()) {
            $this->addError('members', 'L\'organisation doit garder au moins un responsable.');

            return;
        }

        $this->organization->members()->updateExistingPivot($userId, ['role' => $role]);
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('update', $this->organization);

        if ($this->isLastManager($userId) && ! auth()->user()->isAdmin()) {
            $this->addError('members', 'L\'organisation doit garder au moins un responsable.');

            return;
        }

        $this->organization->members()->detach($userId);
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->organization);
        $this->organization->delete();
        $this->redirectRoute('admin.organizations.index', navigate: true);
    }

    public function render()
    {
        return view('livewire.admin.organizations.show', [
            'members' => $this->organization->members()->orderBy('name')->get(),
        ]);
    }

    private function isLastManager(int $userId): bool
    {
        return $this->organization->members()->whereKey($userId)->wherePivot('role', 'manager')->exists()
            && $this->organization->members()->wherePivot('role', 'manager')->count() === 1;
    }
}
