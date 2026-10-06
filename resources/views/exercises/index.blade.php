<x-app-layout>
    <x-slot name="header">
        <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">Exercices</h2>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        @forelse ($levels as $level)
            <section>
                <h3 class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <span class="size-2.5 rounded-full" style="background-color: {{ $level->color }}"></span>
                    Niveau {{ $level->position }} · {{ $level->name }}
                </h3>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($level->exercises as $exercise)
                        <li>
                            <a href="{{ route('exercises.show', $exercise) }}" wire:navigate
                               class="block h-full rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-900/5 transition hover:ring-indigo-500 dark:bg-gray-800 dark:ring-white/10">
                                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                    <span>{{ $exercise->type->label() }}</span>
                                    @if ($solved->has($exercise->id))
                                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">✓ Résolu</span>
                                    @else
                                        <span class="font-semibold text-amber-600 dark:text-amber-400">+{{ $exercise->xp_reward }} XP</span>
                                    @endif
                                </div>
                                <p class="mt-2 font-medium text-gray-900 dark:text-white">{{ $exercise->title }}</p>
                                <p class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($exercise->skills as $skill)
                                        <span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $skill->name }}</span>
                                    @endforeach
                                </p>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="text-gray-500">Aucun exercice publié pour le moment.</p>
        @endforelse
    </div>
</x-app-layout>
