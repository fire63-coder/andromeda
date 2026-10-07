<x-action-section>
    <x-slot name="title">Organisations</x-slot>
    <x-slot name="description">Rejoignez votre entreprise, école ou promotion pour accéder à son classement et à ses défis privés.</x-slot>

    <x-slot name="content">
        @if ($flash)
            <p class="mb-3 text-sm text-emerald-600 dark:text-emerald-400" role="status">{{ $flash }}</p>
        @endif

        <ul class="space-y-2 text-sm">
            @forelse ($organizations as $organization)
                <li wire:key="my-org-{{ $organization->id }}" class="flex items-center justify-between">
                    <span class="text-gray-800 dark:text-gray-100">{{ $organization->name }} <span class="text-gray-500">· {{ $organization->pivot->role === 'manager' ? 'Responsable' : 'Membre' }}</span></span>
                    <button type="button" wire:click="leave({{ $organization->id }})" wire:confirm="Quitter {{ $organization->name }} ?" class="text-rose-600 hover:underline">Quitter</button>
                </li>
            @empty
                <li class="text-gray-500 dark:text-gray-400">Vous ne faites partie d'aucune organisation.</li>
            @endforelse
        </ul>

        <form wire:submit="join" class="mt-4 flex gap-2">
            <x-input type="text" wire:model="code" placeholder="Code d'invitation (ex. AB12-CD34)" class="flex-1 font-mono uppercase" aria-label="Code d'invitation" />
            <x-button type="submit">Rejoindre</x-button>
        </form>
        <x-input-error for="code" class="mt-2" />
    </x-slot>
</x-action-section>
