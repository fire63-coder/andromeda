@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
    $total = collect($items)->sum('points');
@endphp

<form wire:submit="save" class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.challenges.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Défis</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $challenge ? $title : 'Nouveau défi' }}</h1>
        </div>
        <div class="flex items-center gap-3">
            @if ($saved) <span class="text-sm text-emerald-600" x-data x-init="setTimeout(() => $el.remove(), 3000)">{{ $saved }}</span> @endif
            @if ($challenge)
                <a href="{{ route('arena.show', $challenge) }}" class="text-sm text-gray-600 hover:underline dark:text-gray-300">Voir côté élève</a>
                <button type="button" wire:click="delete" wire:confirm="Supprimer ce défi et toutes ses participations ?" class="rounded-md px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-900/30">Supprimer</button>
            @endif
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Enregistrer</button>
        </div>
    </div>

    @if ($stats && $stats['participants'] > 0)
        <p class="rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            {{ $stats['participants'] }} participant(s) ont déjà commencé : modifier les exercices ou les points ne recalcule pas les scores acquis.
        </p>
    @endif

    <section class="{{ $card }} grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2">
            <label class="{{ $label }}" for="title">Titre</label>
            <input id="title" type="text" wire:model.blur="title" class="{{ $input }}">
            @error('title') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="slug">Identifiant</label>
            <input id="slug" type="text" wire:model="slug" class="{{ $input }} font-mono text-sm">
            @error('slug') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="status">Statut</label>
            <select id="status" wire:model="status" class="{{ $input }}">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2 lg:col-span-4">
            <label class="{{ $label }}" for="description">Description</label>
            <textarea id="description" wire:model="description" rows="2" class="{{ $input }}"></textarea>
        </div>
        <div>
            <label class="{{ $label }}" for="type">Type</label>
            <select id="type" wire:model="type" class="{{ $input }}">
                @foreach ($types as $value => $text)
                    <option value="{{ $value }}">{{ $text }}</option>
                @endforeach
            </select>
            @error('type') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="organization">Réservé à</label>
            <select id="organization" wire:model="organizationId" class="{{ $input }}">
                <option value="">Tout le monde</option>
                @foreach ($organizations as $organization)
                    <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $label }}" for="startsAt">Début</label>
            <input id="startsAt" type="datetime-local" wire:model="startsAt" class="{{ $input }}">
            @error('startsAt') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="endsAt">Fin (vide = sans fin)</label>
            <input id="endsAt" type="datetime-local" wire:model="endsAt" class="{{ $input }}">
            @error('endsAt') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="durationMinutes">Chrono individuel (min, vide = aucun)</label>
            <input id="durationMinutes" type="number" wire:model="durationMinutes" class="{{ $input }}">
            @error('durationMinutes') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="xpMultiplier">Multiplicateur d'XP</label>
            <input id="xpMultiplier" type="number" step="0.05" wire:model="xpMultiplier" class="{{ $input }}">
            @error('xpMultiplier') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Épreuves ({{ count($items) }} · {{ $total }} pts)
            </h2>
            @error('items') <p class="mt-2 text-sm text-rose-600" role="alert">{{ $message }}</p> @enderror
            <ol class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700" data-testid="challenge-items">
                @forelse ($items as $index => $item)
                    @php $exercise = $selected[$item['id']] ?? null; @endphp
                    <li wire:key="item-{{ $item['id'] }}" class="flex items-center gap-2 py-2">
                        <span class="w-6 text-right text-gray-400">{{ $index + 1 }}.</span>
                        <span class="flex-1 text-gray-800 dark:text-gray-100">
                            {{ $exercise?->title ?? '#'.$item['id'] }}
                            @if ($exercise && $exercise->status !== \App\Enums\ContentStatus::Published) <span class="text-xs text-amber-600">({{ $exercise->status->label() }})</span> @endif
                        </span>
                        <input type="number" wire:model="items.{{ $index }}.points" aria-label="Points" class="w-20 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <button type="button" wire:click="moveExercise({{ $index }}, -1)" @disabled($index === 0) class="px-1 text-gray-500 disabled:opacity-30" aria-label="Monter">↑</button>
                        <button type="button" wire:click="moveExercise({{ $index }}, 1)" @disabled($loop->last) class="px-1 text-gray-500 disabled:opacity-30" aria-label="Descendre">↓</button>
                        <button type="button" wire:click="removeExercise({{ $index }})" class="px-1 text-rose-600" aria-label="Retirer">✕</button>
                    </li>
                    @error("items.$index.points") <li class="pb-2 text-sm text-rose-600">{{ $message }}</li> @enderror
                @empty
                    <li class="py-6 text-center text-gray-500">Ajoutez des exercices depuis la liste.</li>
                @endforelse
            </ol>
        </section>

        <section class="{{ $card }}">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Exercices disponibles</h2>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filtrer…" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
            </div>
            <ul class="mt-3 max-h-96 divide-y divide-gray-100 overflow-y-auto text-sm dark:divide-gray-700">
                @foreach ($candidates as $exercise)
                    <li wire:key="candidate-{{ $exercise->id }}" class="flex items-center gap-3 py-2">
                        <span class="flex-1 text-gray-800 dark:text-gray-100">{{ $exercise->title }}</span>
                        <span class="text-xs text-gray-500">
                            niv. {{ $exercise->level->position }} ·
                            <span @class(['font-semibold text-indigo-600 dark:text-indigo-400' => $exercise->lesson_id === null])>{{ $exercise->lesson_id === null ? 'réservé' : 'entraînement' }}</span>
                            @if ($exercise->status !== \App\Enums\ContentStatus::Published) · <span class="text-amber-600">{{ $exercise->status->label() }}</span> @endif
                        </span>
                        <button type="button" wire:click="addExercise({{ $exercise->id }})" class="rounded-md px-2 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 dark:text-indigo-400 dark:hover:bg-indigo-900/30">Ajouter</button>
                    </li>
                @endforeach
            </ul>
        </section>
    </div>

    @if ($stats)
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Participation</h2>
            <dl class="mt-3 grid grid-cols-3 gap-4 text-center">
                <div><dt class="text-xs text-gray-500">Participants</dt><dd class="text-xl font-semibold text-gray-900 dark:text-white">{{ $stats['participants'] }}</dd></div>
                <div><dt class="text-xs text-gray-500">Classés</dt><dd class="text-xl font-semibold text-gray-900 dark:text-white">{{ $stats['scored'] }}</dd></div>
                <div><dt class="text-xs text-gray-500">Score moyen</dt><dd class="text-xl font-semibold text-gray-900 dark:text-white">{{ $stats['average'] !== null ? round($stats['average']) : '—' }}</dd></div>
            </dl>
            @if ($standings->isNotEmpty())
                <ol class="mt-4 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                    @foreach ($standings as $participation)
                        <li class="flex justify-between py-1.5">
                            <span class="text-gray-800 dark:text-gray-100">{{ $loop->iteration }}. {{ $participation->user->name }}</span>
                            <span class="text-gray-500">{{ $participation->score }} pts · {{ $participation->solved_count }} résolu(s)</span>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif
</form>
