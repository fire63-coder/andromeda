@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
@endphp

<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <a href="{{ route('admin.datasets.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Jeux de données</a>
    <h1 class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white">Importer un jeu de données</h1>

    <ol class="mt-4 flex gap-4 text-sm">
        @foreach ([1 => 'Fichiers', 2 => 'Vérification'] as $number => $title)
            <li @class(['font-semibold text-indigo-600 dark:text-indigo-400' => $step === $number, 'text-gray-500' => $step !== $number])>
                {{ $number }}. {{ $title }}
            </li>
        @endforeach
    </ol>

    @if ($step === 1)
        <form wire:submit="analyze" class="mt-6 space-y-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="{{ $label }}">Nom</label>
                    <input id="name" type="text" wire:model.blur="name" class="{{ $input }}" placeholder="Ressources humaines">
                    @error('name') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="slug" class="{{ $label }}">Identifiant</label>
                    <input id="slug" type="text" wire:model="slug" class="{{ $input }} font-mono">
                    @error('slug') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="description" class="{{ $label }}">Description</label>
                    <textarea id="description" wire:model="description" rows="2" class="{{ $input }}"></textarea>
                </div>
                <div>
                    <label for="domain" class="{{ $label }}">Domaine métier</label>
                    <input id="domain" type="text" wire:model="domain" class="{{ $input }}" placeholder="RH, banque, logistique…">
                </div>
                <label class="flex items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="isPublic" class="rounded border-gray-300 text-indigo-600">
                    Visible dans le bac à sable libre
                </label>
            </div>

            <fieldset>
                <legend class="{{ $label }}">Format</legend>
                <div class="mt-2 grid gap-3 sm:grid-cols-3">
                    @foreach (['csv' => ['CSV', 'Un fichier par table (nom du fichier = nom de la table).'], 'json' => ['JSON', 'Liste d\'objets, ou { "table": [ … ] } pour plusieurs tables.'], 'sql_dump' => ['Dump SQL', 'CREATE TABLE + INSERT en SQL standard / SQLite.']] as $value => [$title, $help])
                        <label class="cursor-pointer rounded-lg border p-3 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:border-gray-700 dark:has-[:checked]:bg-indigo-500/10">
                            <input type="radio" wire:model.live="format" value="{{ $value }}" class="sr-only">
                            <span class="block text-sm font-semibold text-gray-900 dark:text-white">{{ $title }}</span>
                            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">{{ $help }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label for="files" class="{{ $label }}">{{ $format === 'csv' ? 'Fichiers CSV (30 max.)' : 'Fichier' }} — 20 Mo max. par fichier</label>
                <input id="files" type="file" wire:model="files" @if ($format === 'csv') multiple @endif
                       accept="{{ ['csv' => '.csv,.tsv,.txt', 'json' => '.json', 'sql_dump' => '.sql,.txt'][$format] }}"
                       class="mt-1 block w-full text-sm text-gray-700 file:me-4 file:rounded-md file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 dark:text-gray-300 dark:file:bg-indigo-500/10 dark:file:text-indigo-300">
                <div wire:loading wire:target="files" class="mt-1 text-sm text-gray-500">Envoi…</div>
                @error('files') <p class="mt-1 text-sm text-rose-600" data-testid="files-error">{{ $message }}</p> @enderror
                @error('files.*') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            @if ($format === 'csv')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="delimiter" class="{{ $label }}">Séparateur</label>
                        <select id="delimiter" wire:model="delimiter" class="{{ $input }}">
                            <option value="auto">Détection automatique</option>
                            <option value=",">Virgule ( , )</option>
                            <option value=";">Point-virgule ( ; )</option>
                            <option value="tab">Tabulation</option>
                            <option value="|">Barre verticale ( | )</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="hasHeader" class="rounded border-gray-300 text-indigo-600">
                        La première ligne contient les noms de colonnes
                    </label>
                </div>
            @endif

            <div class="flex justify-end">
                <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">
                    <span wire:loading.remove wire:target="analyze">Analyser</span>
                    <span wire:loading wire:target="analyze">Analyse…</span>
                </button>
            </div>
        </form>
    @else
        <div class="mt-6 space-y-6">
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                <p class="text-sm text-gray-700 dark:text-gray-300" data-testid="summary">
                    <strong>{{ count($preview['tables']) }}</strong> table(s), <strong>{{ number_format($preview['total_rows'], 0, ',', ' ') }}</strong> ligne(s).
                    Les types, clés primaires et clés étrangères ont été déduits des données : vérifiez-les avant d'importer.
                </p>
                @if ($preview['warnings'] !== [])
                    <ul class="mt-3 list-disc space-y-1 ps-5 text-sm text-amber-700 dark:text-amber-300">
                        @foreach ($preview['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @foreach ($preview['tables'] as $table)
                <section wire:key="table-{{ $table['name'] }}" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-100 px-6 py-3 dark:border-gray-700">
                        <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                            Table
                            <input type="text" wire:model="renames.{{ $table['name'] }}" class="rounded-md border-gray-300 py-1 font-mono text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        </label>
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $table['rows'] }} lignes</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full font-mono text-xs">
                            <thead class="bg-gray-50 text-left dark:bg-gray-900">
                                <tr>
                                    @foreach ($table['columns'] as $column)
                                        <th class="whitespace-nowrap px-3 py-2 align-top">
                                            <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $column['name'] }}</span>
                                            @if ($column['primary']) <span class="text-amber-500">PK</span> @endif
                                            <span class="block font-normal text-gray-500">{{ strtolower($column['type']) }}</span>
                                            @if ($column['references']) <span class="block font-normal text-sky-500">→ {{ $column['references'] }}</span> @endif
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($table['sample'] as $row)
                                    <tr>
                                        @foreach ($row as $value)
                                            <td class="whitespace-nowrap px-3 py-1.5 text-gray-700 dark:text-gray-300">{{ $value ?? 'NULL' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endforeach

            <div class="flex justify-between">
                <button type="button" wire:click="back" class="rounded-md px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800">← Modifier</button>
                <div class="flex gap-3">
                    <button type="button" wire:click="reanalyze" class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-600">Appliquer les noms</button>
                    <button type="button" wire:click="import" wire:loading.attr="disabled" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50">Importer</button>
                </div>
            </div>
        </div>
    @endif
</div>
