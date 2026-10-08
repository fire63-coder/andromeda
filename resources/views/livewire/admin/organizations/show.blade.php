<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <a href="{{ route('admin.organizations.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Organisations</a>
        <div class="mt-1 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $organization->name }}</h1>
            <a href="{{ route('admin.organizations.progress', $organization) }}" wire:navigate class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Suivi pédagogique →</a>
        </div>
    </div>

    @if ($flash)
        <p class="rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ $flash }}</p>
    @endif

    <section class="grid gap-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 sm:grid-cols-2 dark:bg-gray-800 dark:ring-white/10">
        <form wire:submit="rename">
            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nom</label>
            <div class="mt-1 flex gap-2">
                <input id="name" type="text" wire:model="name" class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Enregistrer</button>
            </div>
            @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </form>
        <div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Code d'invitation</p>
            <div class="mt-1 flex items-center gap-3">
                <span class="rounded-md bg-gray-100 px-3 py-2 font-mono text-lg font-semibold tracking-widest text-gray-900 dark:bg-gray-900 dark:text-white" data-testid="invite-code">{{ $organization->invite_code }}</span>
                <button type="button" wire:click="regenerateCode" wire:confirm="L'ancien code ne fonctionnera plus. Continuer ?" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">Régénérer</button>
            </div>
            <p class="mt-1 text-xs text-gray-500">À communiquer aux membres : Profil → Organisations → Rejoindre.</p>
        </div>
    </section>

    <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Membres ({{ $members->count() }})</h2>
        @error('members') <p class="mt-2 text-sm text-rose-600" role="alert">{{ $message }}</p> @enderror
        <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($members as $member)
                <li wire:key="member-{{ $member->id }}" class="flex items-center justify-between gap-3 py-2 text-sm">
                    <span class="text-gray-800 dark:text-gray-100">{{ $member->name }} <span class="text-gray-500">· {{ $member->email }} · {{ number_format($member->xp, 0, ',', ' ') }} XP</span></span>
                    <span class="flex items-center gap-3">
                        <select wire:change="setRole({{ $member->id }}, $event.target.value)" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="Rôle de {{ $member->name }}">
                            <option value="member" @selected($member->pivot->role === 'member')>Membre</option>
                            <option value="manager" @selected($member->pivot->role === 'manager')>Responsable</option>
                        </select>
                        <button type="button" wire:click="removeMember({{ $member->id }})" wire:confirm="Retirer {{ $member->name }} ?" class="text-rose-600 hover:underline">Retirer</button>
                    </span>
                </li>
            @empty
                <li class="py-2 text-sm text-gray-500">Aucun membre pour l'instant.</li>
            @endforelse
        </ul>
        <form wire:submit="addMember" class="mt-4 flex gap-2">
            <input type="email" wire:model="email" placeholder="Ajouter par adresse e-mail" class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium ring-1 ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">Ajouter</button>
        </form>
        @error('email') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    </section>

    @can('delete', $organization)
        <button type="button" wire:click="delete" wire:confirm="Supprimer l'organisation ? Ses défis privés seront supprimés." class="text-sm text-rose-600 hover:underline">Supprimer l'organisation</button>
    @endcan
</div>
