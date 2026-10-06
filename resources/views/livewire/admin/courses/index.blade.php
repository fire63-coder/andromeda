@php
    $statusColors = ['published' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300', 'in_review' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300', 'archived' => 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.admin.partials.tabs')

    <div class="mt-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Cours</h1>
        <a href="{{ route('admin.courses.create') }}" wire:navigate class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Nouveau cours</a>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">Titre</th>
                    <th class="px-4 py-3">Niveau</th>
                    <th class="px-4 py-3">Contenu</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-4 py-3">Auteur</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @forelse ($courses as $course)
                    <tr wire:key="course-{{ $course->id }}">
                        <td class="px-4 py-3">
                            @can('update', $course)
                                <a href="{{ route('admin.courses.edit', $course) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $course->title }}</a>
                            @else
                                <span class="font-medium text-gray-900 dark:text-white">{{ $course->title }}</span>
                            @endcan
                            <p class="text-xs text-gray-500">{{ $course->dialect?->name ?? 'SQL standard' }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $course->level->position }}. {{ $course->level->name }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $course->chapters_count }} chap. · {{ $course->lessons_count }} leçons</td>
                        <td class="px-4 py-3"><span class="rounded px-2 py-0.5 text-xs {{ $statusColors[$course->status->value] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}">{{ $course->status->label() }}</span></td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $course->author?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Aucun cours.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
