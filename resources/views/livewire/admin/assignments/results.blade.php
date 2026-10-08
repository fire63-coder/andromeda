@php
    $cells = [
        'on_time' => ['✓', 'Résolu à temps', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300'],
        'late' => ['✓', 'Résolu en retard', 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300'],
        'tried' => ['…', 'Essayé, pas encore réussi', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200'],
        'todo' => ['', 'Pas commencé', ''],
    ];
@endphp

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="{{ route('admin.organizations.show', $organization) }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ $organization->name }}</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $assignment->title }}</h1>
            <p class="text-sm text-gray-500">
                {{ $assignment->isPublished() ? 'Publié' : 'Brouillon' }}
                @if ($assignment->due_at) · échéance {{ $assignment->due_at->isoFormat('LLLL') }} @endif
                · <strong class="text-gray-700 dark:text-gray-300">{{ $completed }}/{{ $rows->count() }}</strong> élève(s) ont tout rendu
            </p>
        </div>
        <a href="{{ route('admin.assignments.edit', [$organization, $assignment]) }}" wire:navigate class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">Modifier</a>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <table class="min-w-full text-sm" data-testid="assignment-matrix">
            <thead class="text-left text-xs text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3 font-semibold uppercase tracking-wide">Élève</th>
                    @foreach ($assignment->exercises as $exercise)
                        <th class="px-2 py-3 text-center font-medium" title="{{ $exercise->title }}">
                            <span class="block max-w-28 truncate">{{ $loop->iteration }}. {{ $exercise->title }}</span>
                            <span class="font-normal tabular-nums">{{ $perExercise[$exercise->id] }}/{{ $rows->count() }}</span>
                        </th>
                    @endforeach
                    <th class="px-4 py-3 text-right font-semibold uppercase tracking-wide">Rendu</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @forelse ($rows as $row)
                    <tr>
                        <td class="px-4 py-2">
                            <a href="{{ route('admin.organizations.member', [$organization, $row['student']->id]) }}" wire:navigate class="text-gray-900 hover:text-indigo-600 dark:text-white">{{ $row['student']->name }}</a>
                        </td>
                        @foreach ($assignment->exercises as $exercise)
                            @php [$mark, $title, $class] = $cells[$row['statuses'][$exercise->id] ?? 'todo']; @endphp
                            <td class="px-2 py-2 text-center">
                                <span class="inline-flex h-7 w-7 items-center justify-center rounded-md text-sm font-semibold {{ $class ?: 'ring-1 ring-inset ring-gray-200 dark:ring-gray-700' }}" title="{{ $title }}"><span aria-hidden="true">{{ $mark }}</span><span class="sr-only">{{ $title }}</span></span>
                            </td>
                        @endforeach
                        <td class="px-4 py-2 text-right tabular-nums {{ $row['complete'] ? 'font-semibold text-emerald-700 dark:text-emerald-300' : 'text-gray-700 dark:text-gray-300' }}">{{ $row['done'] }}/{{ $assignment->exercises->count() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $assignment->exercises->count() + 2 }}" class="px-4 py-8 text-center text-gray-500">Aucun élève dans l'organisation.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <p class="flex flex-wrap gap-4 text-xs text-gray-500">
        @foreach ($cells as [$mark, $title, $class])
            <span class="flex items-center gap-1.5"><span class="inline-flex h-5 w-5 items-center justify-center rounded {{ $class ?: 'ring-1 ring-inset ring-gray-200 dark:ring-gray-700' }}">{{ $mark }}</span>{{ $title }}</span>
        @endforeach
    </p>
</div>
