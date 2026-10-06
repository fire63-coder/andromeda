<nav class="flex gap-1 border-b border-gray-200 dark:border-gray-700" aria-label="Administration">
    @foreach ([
        ['admin.courses.index', 'admin.courses.*', 'Cours'],
        ['admin.exercises.index', 'admin.exercises.*', 'Exercices'],
        ['admin.datasets.index', 'admin.datasets.*', 'Jeux de données'],
    ] as [$route, $pattern, $label])
        @if (Route::has($route))
            <a href="{{ route($route) }}" wire:navigate
               @class([
                   '-mb-px border-b-2 px-4 py-2 text-sm font-medium',
                   'border-indigo-500 text-indigo-600 dark:text-indigo-400' => request()->routeIs($pattern) || request()->routeIs(str_replace('.*', '', $pattern).'*'),
                   'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! request()->routeIs($pattern),
               ])>{{ $label }}</a>
        @endif
    @endforeach
</nav>
