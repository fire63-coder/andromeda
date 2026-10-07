<nav class="flex gap-1 border-b border-gray-200 dark:border-gray-700" aria-label="Administration">
    @foreach ([
        ['admin.courses.index', 'admin.courses.*', 'Cours', auth()->user()->canAuthorContent()],
        ['admin.exercises.index', 'admin.exercises.*', 'Exercices', auth()->user()->canAuthorContent()],
        ['admin.datasets.index', 'admin.datasets.*', 'Jeux de données', auth()->user()->canAuthorContent()],
        ['admin.certifications.index', 'admin.certifications.*', 'Certifications', auth()->user()->can('viewAny', \App\Models\Certification::class)],
        ['admin.challenges.index', 'admin.challenges.*', 'Défis', auth()->user()->can('viewAny', \App\Models\Challenge::class)],
        ['admin.users.index', 'admin.users.*', 'Utilisateurs', auth()->user()->can('viewAny', \App\Models\User::class)],
        ['admin.organizations.index', 'admin.organizations.*', 'Organisations', auth()->user()->can('viewAny', \App\Models\Organization::class)],
    ] as [$route, $pattern, $label, $visible])
        @if ($visible && Route::has($route))
            <a href="{{ route($route) }}" wire:navigate
               @class([
                   '-mb-px border-b-2 px-4 py-2 text-sm font-medium',
                   'border-indigo-500 text-indigo-600 dark:text-indigo-400' => request()->routeIs($pattern) || request()->routeIs(str_replace('.*', '', $pattern).'*'),
                   'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => ! request()->routeIs($pattern),
               ])>{{ $label }}</a>
        @endif
    @endforeach
</nav>
