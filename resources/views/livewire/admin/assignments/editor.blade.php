@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
@endphp

<form wire:submit="save" class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.organizations.show', $organization) }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ $organization->name }}</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $assignment ? $title : 'Nouveau devoir' }}</h1>
        </div>
        <div class="flex items-center gap-3">
            @if ($saved) <span class="text-sm text-emerald-600" x-data x-init="setTimeout(() => $el.remove(), 3000)">{{ $saved }}</span> @endif
            @if ($assignment)
                <a href="{{ route('admin.assignments.results', [$organization, $assignment]) }}" wire:navigate class="text-sm text-gray-600 hover:underline dark:text-gray-300">Résultats</a>
                <button type="button" wire:click="delete" wire:confirm="Supprimer ce devoir ?" class="rounded-md px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/30">Supprimer</button>
            @endif
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Enregistrer</button>
        </div>
    </div>

    <section class="{{ $card }} grid gap-4 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <label class="{{ $label }}" for="title">Titre</label>
            <input id="title" type="text" wire:model="title" class="{{ $input }}" placeholder="Révisions : jointures">
            @error('title') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="dueAt">Échéance (vide = aucune)</label>
            <input id="dueAt" type="datetime-local" wire:model="dueAt" class="{{ $input }}">
            @error('dueAt') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-3">
            <label class="{{ $label }}" for="instructions">Consignes</label>
            <textarea id="instructions" wire:model="instructions" rows="3" class="{{ $input }}"></textarea>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-3 dark:text-gray-300">
            <input type="checkbox" wire:model="published" class="rounded border-gray-300 text-indigo-600">
            Publié : visible des membres de l'organisation sur leur tableau de bord
        </label>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Exercices du devoir ({{ count($exerciseIds) }})</h2>
            @error('exerciseIds') <p class="mt-2 text-sm text-rose-600" role="alert">{{ $message }}</p> @enderror
            @error('exerciseIds.*') <p class="mt-2 text-sm text-rose-600" role="alert">{{ $message }}</p> @enderror
            <ol class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700" data-testid="assignment-items">
                @forelse ($exerciseIds as $index => $id)
                    <li wire:key="item-{{ $id }}" class="flex items-center gap-2 py-2">
                        <span class="w-6 text-right text-gray-400">{{ $index + 1 }}.</span>
                        <span class="flex-1 text-gray-800 dark:text-gray-100">{{ $selected[$id]->title ?? '#'.$id }}</span>
                        <button type="button" wire:click="move({{ $index }}, -1)" @disabled($index === 0) class="px-1 text-gray-500 disabled:opacity-30" aria-label="Monter">↑</button>
                        <button type="button" wire:click="move({{ $index }}, 1)" @disabled($loop->last) class="px-1 text-gray-500 disabled:opacity-30" aria-label="Descendre">↓</button>
                        <button type="button" wire:click="remove({{ $index }})" class="px-1 text-rose-600" aria-label="Retirer">✕</button>
                    </li>
                @empty
                    <li class="py-6 text-center text-gray-500">Ajoutez des exercices depuis la liste.</li>
                @endforelse
            </ol>
        </section>

        <section class="{{ $card }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Exercices d'entraînement</h2>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filtrer…" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            </div>
            <ul class="mt-3 max-h-96 divide-y divide-gray-100 overflow-y-auto text-sm dark:divide-gray-700">
                @foreach ($candidates as $exercise)
                    <li wire:key="candidate-{{ $exercise->id }}" class="flex items-center gap-3 py-2">
                        <span class="flex-1">
                            <span class="text-gray-800 dark:text-gray-100">{{ $exercise->title }}</span>
                            <span class="block text-xs text-gray-500">niv. {{ $exercise->level->position }} · {{ $exercise->lesson?->chapter?->course?->title }}</span>
                        </span>
                        <button type="button" wire:click="add({{ $exercise->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-900/30">Ajouter</button>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</form>
