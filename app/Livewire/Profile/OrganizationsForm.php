<?php

namespace App\Livewire\Profile;

use App\Models\Organization;
use Livewire\Component;

/**
 * Section du profil : rejoindre une organisation avec son code, voir et quitter ses organisations.
 */
class OrganizationsForm extends Component
{
    public string $code = '';

    public ?string $flash = null;

    public function join(): void
    {
        $this->validate(['code' => ['required', 'string', 'max:20']], attributes: ['code' => 'code d\'invitation']);

        $organization = Organization::where('invite_code', strtoupper(trim($this->code)))->first();

        if (! $organization) {
            $this->addError('code', 'Code d\'invitation inconnu.');

            return;
        }

        // Ne rétrograde pas un responsable qui saisirait de nouveau le code.
        if (! auth()->user()->organizations()->whereKey($organization->id)->exists()) {
            auth()->user()->organizations()->attach($organization->id, ['role' => 'member', 'joined_at' => now()]);
        }

        $this->reset('code');
        $this->flash = "Vous avez rejoint {$organization->name}.";
    }

    public function leave(int $organizationId): void
    {
        $organization = auth()->user()->organizations()->whereKey($organizationId)->first();

        if (! $organization) {
            return;
        }

        $otherManagers = $organization->members()->wherePivot('role', 'manager')->whereKeyNot(auth()->id())->exists();
        $otherMembers = $organization->members()->whereKeyNot(auth()->id())->exists();

        if ($organization->pivot->role === 'manager' && $otherMembers && ! $otherManagers) {
            $this->addError('code', "Nommez un autre responsable de {$organization->name} avant de la quitter.");

            return;
        }

        auth()->user()->organizations()->detach($organizationId);
        $this->flash = null;
    }

    public function render()
    {
        return view('livewire.profile.organizations-form', [
            'organizations' => auth()->user()->organizations()->orderBy('name')->get(),
        ]);
    }
}
