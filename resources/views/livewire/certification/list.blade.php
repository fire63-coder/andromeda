<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Certifications</h1>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Épreuves chronométrées, sujet tiré au sort, résultat révélé à la fin. Un certificat vérifiable est délivré en cas de réussite.</p>

    @if ($error)
        <p class="mt-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" role="alert">{{ $error }}</p>
    @endif

    <div class="mt-6 space-y-4">
        @forelse ($items as $item)
            @php
                [$certification, $attempts, $current, $passed] = [$item['certification'], $item['attempts'], $item['current'], $item['passed']];
            @endphp
            <article wire:key="cert-{{ $certification->id }}" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide" style="color: {{ $certification->level->color }}">Niveau {{ $certification->level->position }} · {{ $certification->level->name }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-gray-900 dark:text-white">{{ $certification->title }}</h2>
                        <p class="mt-1 max-w-2xl text-sm text-gray-600 dark:text-gray-300">{{ $certification->description }}</p>
                        <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                            <div>⏱ {{ $certification->duration_minutes }} min</div>
                            <div>📝 {{ $certification->exercises_count }} questions</div>
                            <div>🎯 {{ $certification->passing_score }} % pour réussir</div>
                            <div>🔁 {{ $certification->max_attempts ? $attempts->count().' / '.$certification->max_attempts.' tentatives' : 'tentatives illimitées' }}</div>
                            @if ($certification->dialect) <div>🗄 {{ $certification->dialect->name }}</div> @endif
                            <div>⭐ +{{ $certification->xp_reward }} XP</div>
                        </dl>
                    </div>

                    <div class="text-right">
                        @if ($passed)
                            <p class="font-semibold text-emerald-600 dark:text-emerald-400">✓ Obtenue ({{ $passed->score }} %)</p>
                            <a href="{{ route('certificates.show', $passed->certificate_code) }}" class="mt-1 inline-block text-sm text-indigo-600 hover:underline dark:text-indigo-400">Voir le certificat</a>
                        @elseif ($current)
                            <a href="{{ route('certifications.attempt', $current) }}" wire:navigate class="rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-400">Reprendre l'épreuve</a>
                        @elseif ($item['blocked'])
                            <p class="max-w-56 text-sm text-gray-500 dark:text-gray-400">{{ $item['blocked'] }}</p>
                        @else
                            <button type="button" wire:click="start({{ $certification->id }})"
                                    wire:confirm="Le chronomètre démarre immédiatement ({{ $certification->duration_minutes }} min). Commencer ?"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                                Commencer
                            </button>
                        @endif
                    </div>
                </div>

                @if ($attempts->isNotEmpty())
                    <ul class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                        @foreach ($attempts->take(3) as $attempt)
                            <li>
                                <a href="{{ route('certifications.attempt', $attempt) }}" wire:navigate class="hover:underline">
                                    {{ $attempt->started_at->isoFormat('LLL') }} — {{ $attempt->status->label() }}{{ $attempt->score !== null ? ' · '.$attempt->score.' %' : '' }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </article>
        @empty
            <p class="text-gray-500">Aucune certification ouverte pour le moment.</p>
        @endforelse
    </div>
</div>
