@php
    $statusColors = ['published' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300', 'in_review' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'archived' => 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.admin.partials.tabs')

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Exercices</h1>
        <a href="{{ route('admin.exercises.create') }}" wire:navigate class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Nouvel exercice</a>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher…" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
        <select wire:model.live="status" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            <option value="">Tous les statuts</option>
            @foreach ($statuses as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <input type="checkbox" wire:model.live="mine" class="rounded border-gray-300 text-indigo-600"> Mes exercices
        </label>
        @if ($toReview > 0)
            <button type="button" wire:click="$set('status', 'in_review')" class="rounded-full bg-amber-100 px-3 py-1 text-sm font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                {{ $toReview }} à relire
            </button>
        @endif
    </div>

    <div class="mt-4 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">Titre</th>
                    <th class="px-4 py-3">Type / niveau</th>
                    <th class="px-4 py-3">Usage</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-4 py-3">Auteur</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @forelse ($exercises as $exercise)
                    <tr wire:key="exercise-{{ $exercise->id }}">
                        <td class="px-4 py-3">
                            @can('update', $exercise)
                                <a href="{{ route('admin.exercises.edit', $exercise) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $exercise->title }}</a>
                            @else
                                <span class="font-medium text-gray-900 dark:text-white">{{ $exercise->title }}</span>
                            @endcan
                            <p class="font-mono text-xs text-gray-500">{{ $exercise->slug }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $exercise->type->label() }} · niv. {{ $exercise->level->position }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $exercise->lesson ? $exercise->lesson->chapter->course->title.' › '.$exercise->lesson->title : 'Réservé (certif. / défis)' }}
                            <span class="text-xs text-gray-500">· {{ $exercise->datasets_count }} jeu(x)</span>
                        </td>
                        <td class="px-4 py-3"><span class="rounded px-2 py-0.5 text-xs {{ $statusColors[$exercise->status->value] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}">{{ $exercise->status->label() }}</span></td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $exercise->author?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Aucun exercice.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $exercises->links() }}</div>
</div>
