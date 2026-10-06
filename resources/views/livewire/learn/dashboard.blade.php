@php
    $rank = $this->rankProgress;
    $statusIcons = ['correct' => '✅', 'wrong' => '🧐', 'error' => '⚠️', 'timeout' => '⏱', 'rejected' => '🛑', 'pending' => '…'];
@endphp

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    {{-- En-tête : rang et progression --}}
    <section class="rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-700 p-6 text-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-6">
            <div>
                <p class="text-sm text-indigo-100">Bonjour {{ $user->name }}</p>
                <p class="mt-1 text-2xl font-semibold" data-testid="rank">{{ $rank['current']?->name ?? 'Novice' }}</p>
                <p class="mt-1 text-sm text-indigo-100">
                    {{ number_format($user->xp, 0, ',', ' ') }} XP
                    @if ($rank['next'])
                        · encore {{ number_format($rank['next']->min_xp - $user->xp, 0, ',', ' ') }} XP avant « {{ $rank['next']->name }} »
                    @endif
                </p>
            </div>
            <dl class="grid grid-cols-3 gap-6 text-center">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-indigo-200">Série</dt>
                    <dd class="mt-1 text-2xl font-semibold">🔥 {{ $user->current_streak }}</dd>
                    <dd class="text-xs text-indigo-200">record {{ $user->longest_streak }} j</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-indigo-200">Réussite</dt>
                    <dd class="mt-1 text-2xl font-semibold">{{ $successRate !== null ? $successRate.' %' : '—' }}</dd>
                    <dd class="text-xs text-indigo-200">{{ $attempts }} tentative{{ $attempts > 1 ? 's' : '' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-indigo-200">Classement</dt>
                    <dd class="mt-1 text-2xl font-semibold">{{ $position ? '#'.$position['position'] : '—' }}</dd>
                    <dd class="text-xs text-indigo-200"><a href="{{ route('leaderboard') }}" wire:navigate class="underline">voir</a></dd>
                </div>
            </dl>
        </div>
        <div class="mt-5 h-2 overflow-hidden rounded-full bg-white/20" role="progressbar" aria-valuenow="{{ $rank['percent'] }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-amber-300" style="width: {{ $rank['percent'] }}%"></div>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Progression par niveau + prochain exercice --}}
        <section class="space-y-4 lg:col-span-2">
            @if ($this->nextExercise)
                <a href="{{ route('exercises.show', $this->nextExercise) }}" wire:navigate
                   class="flex items-center justify-between rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 transition hover:ring-indigo-500 dark:bg-gray-800 dark:ring-white/10">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Prochain exercice</p>
                        <p class="mt-1 font-medium text-gray-900 dark:text-white">{{ $this->nextExercise->title }}</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Niveau {{ $this->nextExercise->level->position }} · {{ $this->nextExercise->type->label() }}</p>
                    </div>
                    <span class="rounded-lg bg-indigo-500 px-4 py-2 text-sm font-semibold text-white">Continuer →</span>
                </a>
            @endif

            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Progression par niveau</h2>
                <ul class="mt-4 space-y-4">
                    @foreach ($this->levels as $row)
                        @php
                            $percent = $row['total'] > 0 ? (int) round(100 * $row['solved'] / $row['total']) : 0;
                        @endphp
                        <li>
                            <div class="flex justify-between text-sm">
                                <span class="font-medium text-gray-800 dark:text-gray-100">{{ $row['level']->position }}. {{ $row['level']->name }}</span>
                                <span class="text-gray-500 dark:text-gray-400">{{ $row['solved'] }} / {{ $row['total'] }}</span>
                            </div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                <div class="h-full rounded-full" style="width: {{ $percent }}%; background-color: {{ $row['level']->color }}"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Activité récente</h2>
                @forelse ($recent as $submission)
                    <a href="{{ route('exercises.show', $submission->exercise->slug) }}" wire:navigate
                       class="mt-3 flex items-center justify-between gap-3 text-sm">
                        <span class="truncate text-gray-800 dark:text-gray-100">{{ $statusIcons[$submission->status->value] ?? '' }} {{ $submission->exercise->title }}</span>
                        <span class="shrink-0 text-gray-500 dark:text-gray-400">
                            @if ($submission->xp_awarded > 0)
                                <span class="font-semibold text-amber-600 dark:text-amber-400">+{{ $submission->xp_awarded }} XP</span> ·
                            @endif
                            {{ $submission->created_at->diffForHumans() }}
                        </span>
                    </a>
                @empty
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Aucune soumission pour l'instant : lancez-vous !</p>
                @endforelse
            </div>
        </section>

        {{-- Badges --}}
        <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                Badges ({{ $this->badges->whereNotNull('awarded_at')->count() }}/{{ $this->badges->count() }})
            </h2>
            <ul class="mt-4 grid grid-cols-2 gap-3" data-testid="badges">
                @foreach ($this->badges as $item)
                    @php
                        [$badge, $earned] = [$item['badge'], $item['awarded_at'] !== null];
                        [$current, $target] = $item['progress'];
                        $hidden = $badge->is_secret && ! $earned;
                    @endphp
                    <li title="{{ $hidden ? 'Badge secret' : $badge->description }}"
                        @class([
                            'rounded-lg border p-3 text-center',
                            'border-amber-300 bg-amber-50 dark:border-amber-400/40 dark:bg-amber-400/10' => $earned,
                            'border-gray-200 opacity-70 grayscale dark:border-gray-700' => ! $earned,
                        ])>
                        <div class="text-2xl">{{ $hidden ? '❔' : ($badge->icon ?? '🏅') }}</div>
                        <p class="mt-1 text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $hidden ? '???' : $badge->name }}</p>
                        @if ($earned)
                            <p class="text-[11px] text-amber-700 dark:text-amber-300">{{ \Illuminate\Support\Carbon::parse($item['awarded_at'])->isoFormat('LL') }}</p>
                        @elseif (! $hidden)
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ min($current, $target) }} / {{ $target }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    </div>
</div>
