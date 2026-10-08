@php
    $labels = [
        'on_time' => ['✅', 'Résolu', 'text-emerald-700 dark:text-emerald-300'],
        'late' => ['🕓', 'Résolu en retard', 'text-amber-700 dark:text-amber-300'],
        'tried' => ['✏️', 'Commencé', 'text-gray-600 dark:text-gray-300'],
        'todo' => ['○', 'À faire', 'text-gray-500 dark:text-gray-400'],
    ];
@endphp

<div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <a href="{{ route('dashboard') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Tableau de bord</a>
        <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $assignment->title }}</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ $assignment->organization->name }}
            @if ($assignment->due_at)
                · <span @class(['font-semibold text-rose-600 dark:text-rose-400' => $assignment->isOverdue()])>échéance {{ $assignment->due_at->isoFormat('dddd D MMMM YYYY, HH:mm') }}</span>
            @endif
        </p>
    </div>

    @if ($assignment->instructions)
        <div class="whitespace-pre-line rounded-xl bg-white p-5 text-sm text-gray-700 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:text-gray-300 dark:ring-white/10">{{ $assignment->instructions }}</div>
    @endif

    <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Exercices</h2>
            <span class="text-sm tabular-nums text-gray-600 dark:text-gray-300">{{ $progress['done'] }}/{{ $progress['total'] }} · {{ $progress['percent'] }} %</span>
        </div>
        <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $progress['percent'] }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-indigo-500" style="width: {{ $progress['percent'] }}%"></div>
        </div>
        <ol class="mt-4 divide-y divide-gray-100 dark:divide-gray-700/60" data-testid="assignment-exercises">
            @foreach ($assignment->exercises as $exercise)
                @php [$icon, $label, $color] = $labels[$progress['statuses'][$exercise->id] ?? 'todo']; @endphp
                <li class="flex items-center justify-between gap-3 py-3">
                    <a href="{{ route('exercises.show', $exercise) }}" wire:navigate class="font-medium text-gray-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400">
                        {{ $loop->iteration }}. {{ $exercise->title }}
                        <span class="text-xs font-normal text-gray-500">· niveau {{ $exercise->level->position }}</span>
                    </a>
                    <span class="shrink-0 text-sm {{ $color }}">{{ $icon }} {{ $label }}</span>
                </li>
            @endforeach
        </ol>
    </section>
</div>
