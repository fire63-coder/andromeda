<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Arène</h1>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Des défis limités dans le temps : chaque exercice réussi rapporte des points, convertis en XP bonus.</p>
    </div>

    @if ($today)
        @php
            $participation = $mine->get($today->id);
        @endphp
        <a href="{{ route('arena.show', $today) }}" wire:navigate data-testid="daily"
           class="block rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 p-6 text-amber-950 shadow-sm transition hover:shadow-md">
            <p class="text-xs font-bold uppercase tracking-widest">☀️ Défi du jour · XP ×{{ rtrim(rtrim(number_format($today->xp_multiplier, 2), '0'), '.') }}</p>
            <h2 class="mt-1 text-xl font-bold">{{ $today->title }}</h2>
            <p class="mt-1 text-sm">{{ $today->exercises_count }} exercices · {{ $today->participations_count }} participant(s) · se termine {{ $today->ends_at->diffForHumans() }}</p>
            <p class="mt-3 text-sm font-semibold">
                {{ $participation ? $participation->solved_count.' / '.$today->exercises_count.' résolu(s) · '.$participation->score.' pts' : 'Relever le défi →' }}
            </p>
        </a>
    @endif

    <section>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Contre-la-montre et événements</h2>
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            @forelse ($others as $challenge)
                @php
                    $participation = $mine->get($challenge->id);
                @endphp
                <a href="{{ route('arena.show', $challenge) }}" wire:navigate wire:key="challenge-{{ $challenge->id }}"
                   class="block rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-900/5 transition hover:ring-indigo-500 dark:bg-gray-800 dark:ring-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                        {{ $challenge->type->label() }}{{ $challenge->organization_id ? ' · privé' : '' }}
                    </p>
                    <h3 class="mt-1 font-semibold text-gray-900 dark:text-white">{{ $challenge->title }}</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {{ $challenge->exercises_count }} exercices
                        @if ($challenge->duration_seconds) · ⏱ {{ intdiv($challenge->duration_seconds, 60) }} min @endif
                        · {{ $challenge->participations_count }} participant(s)
                    </p>
                    <p class="mt-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                        {{ $participation ? $participation->score.' pts' : 'Participer →' }}
                    </p>
                </a>
            @empty
                <p class="text-sm text-gray-500">Aucun autre défi en cours.</p>
            @endforelse
        </div>
    </section>
</div>
