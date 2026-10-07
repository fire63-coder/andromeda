<?php

namespace App\Livewire\Admin\Organizations;

use App\Models\Organization;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Organisations')]
class OrganizationIndex extends Component
{
    public string $name = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Organization::class);
    }

    public function create(): void
    {
        $this->authorize('create', Organization::class);
        $this->validate(['name' => ['required', 'string', 'max:120']], attributes: ['name' => 'nom']);

        if (Str::slug($this->name) === '' || Organization::where('slug', Str::slug($this->name))->exists()) {
            $this->addError('name', 'Une organisation porte déjà ce nom.');

            return;
        }

        $organization = Organization::create([
            'name' => $this->name,
            'slug' => Str::slug($this->name),
            'owner_id' => auth()->id(),
            'invite_code' => Organization::generateInviteCode(),
        ]);

        $this->redirectRoute('admin.organizations.show', $organization, navigate: true);
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.admin.organizations.index', [
            'organizations' => Organization::query()
                ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('members', fn ($q) => $q->whereKey($user->id)->where('organization_user.role', 'manager')))
                ->withCount(['members', 'challenges'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
