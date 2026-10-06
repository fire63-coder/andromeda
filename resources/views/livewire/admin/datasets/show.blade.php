@php
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
    $h2 = 'text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $statusColors = ['ready' => 'text-emerald-600 dark:text-emerald-400', 'failed' => 'text-rose-600 dark:text-rose-400', 'processing' => 'text-amber-600', 'pending' => 'text-gray-500'];
@endphp

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8" @if ($this->processing) wire:poll.2s @endif>
    <div>
        <a href="{{ route('admin.datasets.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Jeux de données</a>
        <div class="mt-2 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $dataset->name }}</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    <span class="font-mono">{{ $dataset->slug }}</span> · {{ $dataset->source_format->label() }}
                    · <span data-testid="dataset-status" class="{{ $statusColors[$dataset->status->value] ?? '' }}">{{ $dataset->status->label() }}</span>
                    @if ($this->processing) <span class="animate-pulse">(import en cours…)</span> @endif
                </p>
            </div>
            @can('delete', $dataset)
                <div>
                    <button type="button" wire:click="delete" wire:confirm="Supprimer ce jeu de données ?" class="rounded-md px-3 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10">Supprimer</button>
                    @error('delete') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
            @endcan
        </div>
        @if ($dataset->description)
            <p class="mt-2 max-w-3xl text-gray-700 dark:text-gray-300">{{ $dataset->description }}</p>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="{{ $card }}">
            <h2 class="{{ $h2 }}">Moteurs</h2>
            <ul class="mt-3 space-y-2 text-sm" data-testid="builds">
                @forelse ($builds as $build)
                    <li>
                        <span class="font-medium text-gray-800 dark:text-gray-100">{{ $build->dialect->name }}</span> :
                        <span class="{{ $statusColors[$build->status->value] ?? '' }}">{{ $build->status->label() }}</span>
                        @unless ($build->dialect->is_sandbox_enabled)
                            <span class="text-xs text-gray-500">(SQL généré, exécution pas encore disponible)</span>
                        @endunless
                        @if ($build->error_message)
                            <p class="mt-1 text-xs text-rose-600">{{ Str::limit($build->error_message, 300) }}</p>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-500">Aucun build pour l'instant.</li>
                @endforelse
            </ul>
        </section>

        <section class="{{ $card }} lg:col-span-2">
            <h2 class="{{ $h2 }}">Exercices liés</h2>
            <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @forelse ($exercises as $exercise)
                    <li class="flex items-center justify-between py-2" wire:key="ex-{{ $exercise->id }}">
                        <a href="{{ route('exercises.show', $exercise) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $exercise->title }}</a>
                        <span class="flex items-center gap-3">
                            <span class="text-gray-500">{{ $roles[$exercise->pivot->role] ?? $exercise->pivot->role }}</span>
                            @can('update', $dataset)
                                <button type="button" wire:click="detachExercise({{ $exercise->id }})" class="text-xs text-rose-600 hover:underline">Délier</button>
                            @endcan
                        </span>
                    </li>
                @empty
                    <li class="py-2 text-gray-500">Aucun exercice n'utilise encore ce jeu de données.</li>
                @endforelse
            </ul>

            @can('update', $dataset)
                <form wire:submit="attachExercise" class="mt-4 flex flex-wrap items-start gap-2">
                    <select wire:model="exerciseId" class="min-w-64 flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <option value="">Choisir un exercice…</option>
                        @foreach ($this->availableExercises as $exercise)
                            <option value="{{ $exercise->id }}">{{ $exercise->title }}</option>
                        @endforeach
                    </select>
                    <select wire:model="role" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($roles as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Lier</button>
                    @error('exerciseId') <p class="w-full text-sm text-rose-600">{{ $message }}</p> @enderror
                </form>
            @endcan
        </section>
    </div>

    @if ($dataset->tables_meta)
        <section class="{{ $card }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="{{ $h2 }}">Tables</h2>
                <div class="flex flex-wrap gap-1">
                    @foreach ($dataset->tables_meta as $table)
                        <button type="button" wire:click="$set('previewTable', '{{ $table['name'] }}')"
                                @class(['rounded-md px-2.5 py-1 font-mono text-xs', 'bg-indigo-600 text-white' => $previewTable === $table['name'], 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200' => $previewTable !== $table['name']])>
                            {{ $table['name'] }} ({{ $table['rows'] }})
                        </button>
                    @endforeach
                </div>
            </div>

            @php
                $current = collect($dataset->tables_meta)->firstWhere('name', $previewTable);
            @endphp
            @if ($current)
                <ul class="mt-4 flex flex-wrap gap-x-6 gap-y-1 font-mono text-xs text-gray-600 dark:text-gray-300">
                    @foreach ($current['columns'] as $column)
                        <li>
                            @if ($column['primary']) <span class="text-amber-500">PK</span> @endif
                            {{ $column['name'] }} <span class="text-gray-400">{{ strtolower($column['type']) }}</span>
                            @if ($column['references']) <span class="text-sky-500">→ {{ $column['references'] }}</span> @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($this->preview && $this->preview['success'])
                <div class="mt-4 overflow-x-auto" data-testid="data-preview">
                    <table class="min-w-full divide-y divide-gray-100 font-mono text-xs dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900">
                            <tr>
                                @foreach ($this->preview['columns'] as $column)
                                    <th class="whitespace-nowrap px-3 py-2 text-left text-gray-600 dark:text-gray-300">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                            @foreach ($this->preview['rows'] as $row)
                                <tr>
                                    @foreach ($row as $value)
                                        <td class="whitespace-nowrap px-3 py-1.5 text-gray-800 dark:text-gray-200">{{ $value ?? 'NULL' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif ($this->preview)
                <p class="mt-4 text-sm text-rose-600">{{ $this->preview['error'] }}</p>
            @endif
        </section>
    @endif

    <section class="{{ $card }}">
        <h2 class="{{ $h2 }}">Historique des imports</h2>
        <ul class="mt-3 space-y-3 text-sm">
            @forelse ($imports as $import)
                <li wire:key="import-{{ $import->id }}">
                    <span class="{{ $statusColors[$import->status->value] ?? '' }}">{{ $import->status->label() }}</span>
                    · {{ $import->original_filename }} · {{ number_format($import->rows_imported, 0, ',', ' ') }} lignes
                    · {{ $import->user?->name }} · {{ $import->created_at->diffForHumans() }}
                    @if ($import->errors['message'] ?? null)
                        <p class="mt-1 text-rose-600" data-testid="import-error">{{ $import->errors['message'] }}</p>
                    @endif
                    @if (! empty($import->errors['warnings']))
                        <details class="mt-1 text-amber-700 dark:text-amber-300">
                            <summary class="cursor-pointer">{{ count($import->errors['warnings']) }} avertissement(s)</summary>
                            <ul class="list-disc ps-5">
                                @foreach ($import->errors['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </li>
            @empty
                <li class="text-gray-500">Jeu créé hors de l'assistant d'import.</li>
            @endforelse
        </ul>
    </section>
</div>
