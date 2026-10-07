<?php

namespace App\Livewire\Admin\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Comptes : changement de rôle et activation, avec garde-fous
 * (pas d'action sur son propre compte, toujours au moins un administrateur actif).
 */
#[Title('Utilisateurs')]
class UserIndex extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    public ?string $flash = null;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'role'], true)) {
            $this->resetPage();
        }
    }

    public function changeRole(int $userId, string $role): void
    {
        $target = $this->target($userId);
        validator(['role' => $role], ['role' => ['required', Rule::enum(UserRole::class)]])->validate();

        if ($target->role === UserRole::Admin && $role !== UserRole::Admin->value && $this->isLastActiveAdmin($target)) {
            $this->addError('users', 'Il doit rester au moins un administrateur actif.');

            return;
        }

        $target->forceFill(['role' => $role])->save();
        $this->flash = "{$target->name} est maintenant ".UserRole::from($role)->label().'.';
    }

    public function toggleActive(int $userId): void
    {
        $target = $this->target($userId);

        if ($target->is_active && $target->role === UserRole::Admin && $this->isLastActiveAdmin($target)) {
            $this->addError('users', 'Impossible de désactiver le dernier administrateur actif.');

            return;
        }

        $target->forceFill(['is_active' => ! $target->is_active])->save();
        $this->flash = $target->name.($target->is_active ? ' est réactivé.' : ' est désactivé : il ne peut plus se connecter.');
    }

    public function render()
    {
        return view('livewire.admin.users.index', [
            'users' => User::query()
                ->with('rank')
                ->withCount(['submissions', 'badges'])
                ->when($this->search, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('email', 'like', "%{$this->search}%")))
                ->when($this->role, fn ($q) => $q->where('role', $this->role))
                ->orderBy('name')
                ->paginate(25),
            'roles' => UserRole::cases(),
        ]);
    }

    private function target(int $userId): User
    {
        $target = User::findOrFail($userId);
        $this->authorize('manage', $target);
        abort_if($target->is(auth()->user()), 403, 'Vous ne pouvez pas modifier votre propre compte ici.');

        return $target;
    }

    private function isLastActiveAdmin(User $target): bool
    {
        return ! User::where('role', UserRole::Admin)->where('is_active', true)->whereKeyNot($target->id)->exists();
    }
}
