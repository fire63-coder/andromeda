@php
    $certification = $attempt->certification;
@endphp

<div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
    <header class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-white px-6 py-4 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">Certification</p>
            <h1 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $certification->title }}</h1>
        </div>

        @if ($inProgress)
            <div x-data="{ left: {{ $attempt->secondsLeft() }}, timer: null }"
                 x-init="timer = setInterval(() => { left = Math.max(0, left - 1); if (left === 0) { clearInterval(timer); $wire.finish(); } }, 1000)"
                 x-on:livewire:navigating.window="clearInterval(timer)"
                 :class="left < 120 ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-100'"
                 class="rounded-lg px-4 py-2 font-mono text-lg font-semibold" data-testid="countdown" role="timer" aria-live="off">
                ⏱ <span x-text="`${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`"></span>
            </div>
            <button type="button" wire:click="finish" wire:confirm="Terminer l'épreuve ? Les questions sans réponse compteront 0."
                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                Terminer l'épreuve
            </button>
        @else
            <div class="text-right" data-testid="attempt-result">
                <p @class(['text-2xl font-bold', 'text-emerald-600 dark:text-emerald-400' => $attempt->status === \App\Enums\AttemptStatus::Passed, 'text-rose-600 dark:text-rose-400' => $attempt->status !== \App\Enums\AttemptStatus::Passed])>
                    {{ $attempt->score }} % — {{ $attempt->status === \App\Enums\AttemptStatus::Passed ? 'Réussie' : 'Non obtenue' }}
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Seuil : {{ $certification->passing_score }} %</p>
                @if ($attempt->certificate_code)
                    <a href="{{ route('certificates.show', $attempt->certificate_code) }}" class="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">🏆 Voir et partager le certificat</a>
                @endif
            </div>
        @endif
    </header>

    @if ($inProgress)
        <nav class="mt-4 flex flex-wrap gap-2" aria-label="Questions">
            @foreach ($this->exercises as $index => $exercise)
                <button type="button" wire:click="goTo({{ $index + 1 }})" wire:key="nav-{{ $exercise->id }}"
                        @class([
                            'size-10 rounded-lg text-sm font-semibold',
                            'bg-indigo-600 text-white' => $question === $index + 1,
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $question !== $index + 1 && $this->answered->has($exercise->id),
                            'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-700' => $question !== $index + 1 && ! $this->answered->has($exercise->id),
                        ])
                        aria-current="{{ $question === $index + 1 ? 'step' : 'false' }}"
                        title="{{ $this->answered->has($exercise->id) ? 'Répondue' : 'Sans réponse' }}">
                    {{ $index + 1 }}
                </button>
            @endforeach
        </nav>

        <div class="mt-4">
            @if ($current)
                <livewire:exercises.exercise-player :exercise="$current" mode="certification" :context-id="$attempt->id" :key="'q-'.$current->id" />
            @endif
        </div>
    @else
        <section class="mt-6 space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Correction</h2>
            @foreach ($this->review as $index => $row)
                <article wire:key="review-{{ $row['exercise']->id }}" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                    <div class="flex items-center justify-between gap-4">
                        <h3 class="font-medium text-gray-900 dark:text-white">{{ $index + 1 }}. {{ $row['exercise']->title }}</h3>
                        <span @class(['rounded-full px-3 py-1 text-sm font-semibold', 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300' => $row['score'] === 100, 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300' => $row['score'] > 0 && $row['score'] < 100, 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300' => $row['score'] === 0])>
                            {{ $row['score'] }} %
                        </span>
                    </div>
                    @if ($row['submission'])
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $row['submission']->feedback['message'] ?? '' }}</p>
                        @if ($row['submission']->query_sql)
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-gray-900 px-4 py-3 font-mono text-xs text-gray-100">{{ $row['submission']->query_sql }}</pre>
                        @endif
                    @else
                        <p class="mt-2 text-sm text-gray-500">Pas de réponse.</p>
                    @endif
                </article>
            @endforeach
        </section>
    @endif
</div>
