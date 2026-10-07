<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.admin.partials.tabs')

    <h1 class="mt-6 text-2xl font-semibold text-gray-900 dark:text-white">Organisations</h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Entreprises, écoles, promotions : classements et défis privés. Les membres rejoignent avec le code d'invitation depuis leur profil.</p>

    @can('create', App\Models\Organization::class)
        <form wire:submit="create" class="mt-4 flex gap-2">
            <input type="text" wire:model="name" placeholder="Nom de la nouvelle organisation" class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Créer</button>
        </form>
        @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
    @endcan

    <ul class="mt-6 divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:divide-gray-700 dark:bg-gray-800 dark:ring-white/10">
        @forelse ($organizations as $organization)
            <li wire:key="org-{{ $organization->id }}" class="flex items-center justify-between px-5 py-4">
                <a href="{{ route('admin.organizations.show', $organization) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $organization->name }}</a>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $organization->members_count }} membre(s) · {{ $organization->challenges_count }} défi(s) · code <span class="font-mono">{{ $organization->invite_code }}</span></span>
            </li>
        @empty
            <li class="px-5 py-10 text-center text-sm text-gray-500">Aucune organisation.</li>
        @endforelse
    </ul>
</div>
