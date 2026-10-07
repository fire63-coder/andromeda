@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
@endphp

<form wire:submit="save" class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.certifications.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Certifications</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $certification ? $title : 'Nouvelle certification' }}</h1>
        </div>
        <div class="flex items-center gap-3">
            @if ($saved) <span class="text-sm text-emerald-600" x-data x-init="setTimeout(() => $el.remove(), 3000)">{{ $saved }}</span> @endif
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Enregistrer</button>
        </div>
    </div>

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
            <label class="{{ $label }}" for="level">Niveau</label>
            <select id="level" wire:model="levelId" class="{{ $input }}">
                @foreach ($levels as $level)
                    <option value="{{ $level->id }}">{{ $level->position }}. {{ $level->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $label }}" for="dialect">Moteur imposé</label>
            <select id="dialect" wire:model="dialectId" class="{{ $input }}">
                <option value="">Au choix du candidat</option>
                @foreach ($dialects as $dialect)
                    <option value="{{ $dialect->id }}">{{ $dialect->name }}</option>
                @endforeach
            </select>
        </div>
        @foreach ([
            'exercisesCount' => 'Questions par sujet', 'durationMinutes' => 'Durée (min)', 'passingScore' => 'Seuil de réussite (%)',
            'maxAttempts' => 'Tentatives max. (vide = illimité)', 'cooldownHours' => 'Délai entre tentatives (h)', 'xpReward' => 'XP à la réussite',
        ] as $field => $text)
            <div>
                <label class="{{ $label }}" for="{{ $field }}">{{ $text }}</label>
                <input id="{{ $field }}" type="number" wire:model="{{ $field }}" class="{{ $input }}">
                @error($field) <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
        @endforeach
    </section>

    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Pool d'exercices ({{ count($pool) }} sélectionné(s) — {{ $exercisesCount }} tirés au sort par sujet)
            </h2>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filtrer…" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
        </div>
        @error('pool') <p class="mt-2 text-sm text-rose-600" role="alert">{{ $message }}</p> @enderror
        <p class="mt-2 text-xs text-gray-500">Privilégiez les exercices <strong>réservés</strong> (sans leçon) : les élèves n'ont pas pu s'y entraîner.</p>
        <ul class="mt-3 max-h-96 divide-y divide-gray-100 overflow-y-auto text-sm dark:divide-gray-700">
            @foreach ($exercises as $exercise)
                <li wire:key="pool-{{ $exercise->id }}">
                    <label class="flex items-center gap-3 py-2">
                        <input type="checkbox" value="{{ $exercise->id }}" wire:model.live="pool" class="rounded border-gray-300 text-indigo-600">
                        <span class="flex-1 text-gray-800 dark:text-gray-100">{{ $exercise->title }}</span>
                        <span class="text-xs text-gray-500">
                            niv. {{ $exercise->level->position }} · {{ $exercise->type->label() }} ·
                            <span @class(['font-semibold text-indigo-600 dark:text-indigo-400' => $exercise->lesson_id === null])>{{ $exercise->lesson_id === null ? 'réservé' : 'entraînement' }}</span>
                            @if ($exercise->status !== \App\Enums\ContentStatus::Published) · <span class="text-amber-600">{{ $exercise->status->label() }}</span> @endif
                        </span>
                    </label>
                </li>
            @endforeach
        </ul>
    </section>
</form>
