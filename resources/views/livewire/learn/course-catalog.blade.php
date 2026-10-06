<div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Cours</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Du SELECT aux procédures stockées : chaque leçon contient des exemples à exécuter et des exercices.</p>
    </div>

    @forelse ($levels as $group)
        <section>
            <h2 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <span class="size-2.5 rounded-full" style="background-color: {{ $group['level']->color }}"></span>
                Niveau {{ $group['level']->position }} · {{ $group['level']->name }}
            </h2>
            <ul class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($group['courses'] as $item)
                    <li wire:key="course-{{ $item['course']->id }}">
                        <a href="{{ route('courses.show', $item['course']) }}" wire:navigate
                           class="flex h-full flex-col rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 transition hover:ring-indigo-500 dark:bg-gray-800 dark:ring-white/10">
                            <h3 class="font-semibold text-gray-900 dark:text-white">{{ $item['course']->title }}</h3>
                            <p class="mt-1 flex-1 text-sm text-gray-600 dark:text-gray-300">{{ $item['course']->summary }}</p>
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                {{ $item['course']->chapters_count }} chapitre(s) · {{ $item['lessons'] }} leçon(s)
                                · {{ $item['course']->dialect?->name ?? 'SQL standard' }}
                            </p>
                            <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $item['percent'] }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-indigo-500" style="width: {{ $item['percent'] }}%"></div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @empty
        <p class="text-gray-500">Aucun cours publié pour le moment.</p>
    @endforelse
</div>
