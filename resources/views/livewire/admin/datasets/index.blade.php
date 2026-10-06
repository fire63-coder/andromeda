@php
    $buildColors = ['ready' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300', 'failed' => 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300'];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Jeux de données</h1>
        <div class="flex gap-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher…"
                   class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            @can('create', App\Models\Dataset::class)
                <a href="{{ route('admin.datasets.import') }}" wire:navigate class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Importer</a>
            @endcan
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">Nom</th>
                    <th class="px-4 py-3">Tables / lignes</th>
                    <th class="px-4 py-3">Moteurs</th>
                    <th class="px-4 py-3">Exercices</th>
                    <th class="px-4 py-3">Créé par</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @forelse ($datasets as $dataset)
                    <tr wire:key="dataset-{{ $dataset->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.datasets.show', $dataset) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $dataset->name }}</a>
                            <p class="text-xs text-gray-500">{{ $dataset->source_format->label() }}{{ $dataset->domain ? ' · '.$dataset->domain : '' }}{{ $dataset->is_public ? '' : ' · privé' }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ count($dataset->tables_meta ?? []) }} / {{ number_format($dataset->total_rows, 0, ',', ' ') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @forelse ($dataset->builds as $build)
                                    <span class="rounded px-1.5 py-0.5 text-xs {{ $buildColors[$build->status->value] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}"
                                          title="{{ $build->status->label() }}">{{ $dialects[$build->sql_dialect_id]->name ?? '?' }}</span>
                                @empty
                                    <span class="text-xs text-gray-500">{{ $dataset->status->label() }}</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $dataset->exercises_count }}</td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $dataset->creator?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Aucun jeu de données.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $datasets->links() }}</div>
</div>
