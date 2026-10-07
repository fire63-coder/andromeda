<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.admin.partials.tabs')

    <div class="mt-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Certifications</h1>
        <a href="{{ route('admin.certifications.create') }}" wire:navigate class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Nouvelle certification</a>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">Certification</th>
                    <th class="px-4 py-3">Sujet</th>
                    <th class="px-4 py-3">Tentatives</th>
                    <th class="px-4 py-3">Réussite</th>
                    <th class="px-4 py-3">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @forelse ($certifications as $certification)
                    <tr wire:key="cert-{{ $certification->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.certifications.edit', $certification) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $certification->title }}</a>
                            <p class="text-xs text-gray-500">Niveau {{ $certification->level->position }} · {{ $certification->duration_minutes }} min · seuil {{ $certification->passing_score }} %</p>
                        </td>
                        <td @class(['px-4 py-3', 'text-rose-600' => $certification->exercise_pool_count < $certification->exercises_count, 'text-gray-700 dark:text-gray-300' => $certification->exercise_pool_count >= $certification->exercises_count])>
                            {{ $certification->exercises_count }} parmi {{ $certification->exercise_pool_count }}
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $certification->attempts_count }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $certification->attempts_count ? round(100 * $certification->passed_count / $certification->attempts_count).' %' : '—' }}
                            @if ($certification->average_score !== null) <span class="text-xs text-gray-500">· moy. {{ round($certification->average_score) }} %</span> @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $certification->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Aucune certification.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
