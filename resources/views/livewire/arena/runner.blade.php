@php
    $deadline = $participation?->deadline() ?? $challenge->ends_at;
@endphp

<div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
    <header class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white px-6 py-4 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <div>
            <a href="{{ route('arena.index') }}" wire:navigate class="text-xs font-semibold uppercase tracking-wide text-indigo-600 hover:underline dark:text-indigo-400">← Arène · {{ $challenge->type->label() }}</a>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $challenge->title }}</h1>
        </div>

        @if ($participation)
            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="text-2xl font-bold text-gray-900 dark:text-white" data-testid="score">{{ $participation->score }} pts</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $participation->solved_count }} / {{ $this->exercises->count() }} résolu(s)</p>
                </div>
                @if ($open && $deadline)
                    <div x-data="{ left: {{ max(0, (int) now()->diffInSeconds($deadline, false)) }}, timer: null }"
                         x-init="timer = setInterval(() => { left = Math.max(0, left - 1); if (left === 0) { clearInterval(timer); $wire.$refresh(); } }, 1000)"
                         x-on:livewire:navigating.window="clearInterval(timer)"
                         :class="left < 60 ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100'"
                         class="rounded-lg px-4 py-2 font-mono text-lg font-semibold" data-testid="countdown" role="timer">
                        ⏱ <span x-text="left >= 3600 ? `${Math.floor(left / 3600)} h ${String(Math.floor(left % 3600 / 60)).padStart(2, '0')}` : `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`"></span>
                    </div>
                @elseif (! $open)
                    <span class="rounded-lg bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200">Terminé</span>
                @endif
            </div>
        @endif
    </header>

    <div class="mt-4 grid gap-6 xl:grid-cols-4">
        <div class="xl:col-span-3">
            @if (! $participation)
                <section class="rounded-xl bg-white p-8 text-center shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-gray-700 dark:text-gray-200">{{ $challenge->description }}</p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        {{ $this->exercises->count() }} exercices · points convertis en XP ×{{ rtrim(rtrim(number_format($challenge->xp_multiplier, 2), '0'), '.') }}
                        @if ($challenge->duration_seconds) · chrono individuel de {{ intdiv($challenge->duration_seconds, 60) }} min dès que vous commencez @endif
                        @if ($challenge->ends_at) · se termine {{ $challenge->ends_at->diffForHumans() }} @endif
                    </p>
                    @if ($error)
                        <p class="mt-4 text-sm text-rose-600" role="alert">{{ $error }}</p>
                    @endif
                    <button type="button" wire:click="join"
                            @if ($challenge->duration_seconds) wire:confirm="Le chronomètre de {{ intdiv($challenge->duration_seconds, 60) }} minutes démarre immédiatement. C'est parti ?" @endif
                            class="mt-6 rounded-md bg-indigo-600 px-6 py-3 font-semibold text-white hover:bg-indigo-500">
                        {{ $challenge->duration_seconds ? 'Lancer le chrono' : 'Participer' }}
                    </button>
                </section>
            @else
                <nav class="flex flex-wrap gap-2" aria-label="Exercices du défi">
                    @foreach ($this->exercises as $index => $exercise)
                        <button type="button" wire:click="goTo({{ $index + 1 }})" wire:key="nav-{{ $exercise->id }}"
                                @class([
                                    'rounded-lg px-3 py-2 text-sm font-semibold',
                                    'bg-indigo-600 text-white' => $question === $index + 1,
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $question !== $index + 1 && $this->solved->has($exercise->id),
                                    'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $question !== $index + 1 && ! $this->solved->has($exercise->id),
                                ])>
                            {{ $this->solved->has($exercise->id) ? '✓' : $index + 1 }} · {{ $exercise->pivot->points }} pts
                        </button>
                    @endforeach
                </nav>

                <div class="mt-4">
                    @if ($open && $current)
                        <livewire:exercises.exercise-player :exercise="$current" mode="challenge" :context-id="$participation->id" :key="'c-'.$current->id" />
                    @elseif (! $open)
                        <p class="rounded-xl bg-white p-8 text-center text-gray-700 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:text-gray-200 dark:ring-white/10">
                            {{ $participation->solved_count === $this->exercises->count() ? '🏁 Tout est résolu, bravo !' : '⏱ Temps écoulé.' }}
                            Score final : <strong>{{ $participation->score }} pts</strong>.
                        </p>
                    @endif
                </div>
            @endif
        </div>

        <aside class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10" wire:poll.15s.visible>
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Classement du défi</h2>
            <ol class="mt-3 space-y-2 text-sm" data-testid="challenge-standings">
                @forelse ($standings as $index => $row)
                    <li wire:key="standing-{{ $row->id }}" @class(['flex items-center justify-between gap-2', 'font-semibold text-indigo-600 dark:text-indigo-400' => $row->user_id === auth()->id(), 'text-gray-700 dark:text-gray-200' => $row->user_id !== auth()->id()])>
                        <span class="truncate">{{ [1 => '🥇', 2 => '🥈', 3 => '🥉'][$index + 1] ?? ($index + 1).'.' }} {{ $row->user->name }}</span>
                        <span class="shrink-0 font-mono">{{ $row->score }}</span>
                    </li>
                @empty
                    <li class="text-gray-500">Personne n'a encore marqué de points.</li>
                @endforelse
            </ol>
        </aside>
    </div>
</div>
