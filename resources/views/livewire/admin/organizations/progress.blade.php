@php
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
    $heading = 'text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
@endphp

<div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <a href="{{ route('admin.organizations.show', $organization) }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ $organization->name }}</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">Suivi pédagogique</h1>
        </div>
        <a href="{{ route('admin.organizations.progress.export', $organization) }}" class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">Exporter (CSV)</a>
    </div>

    {{-- Indicateurs clés --}}
    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6" data-testid="progress-summary">
        @foreach ([
            ['Membres', $summary['members']],
            ['Actifs (7 j)', $summary['active_7d']],
            ['Soumissions (7 j)', $summary['submissions_7d']],
            ['Réussite (7 j)', $summary['success_rate_7d'] !== null ? $summary['success_rate_7d'].' %' : '—'],
            ['Exercices résolus', $summary['solved_total']],
            ['XP cumulée', number_format($summary['xp_total'], 0, ',', ' ')],
        ] as [$label, $value])
            <div class="rounded-xl bg-white px-4 py-3 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                <dd class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <section class="{{ $card }}">
        <h2 class="{{ $heading }}">Soumissions par jour (30 derniers jours)</h2>
        <div class="mt-4">@include('livewire.admin.organizations.partials.activity-chart')</div>
    </section>

    {{-- Élèves --}}
    <section class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="{{ $heading }}">Élèves</h2>
            <div class="flex flex-wrap gap-2">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Rechercher…" aria-label="Rechercher un élève" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <select wire:model.live="sort" aria-label="Trier" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    @foreach ($sorts as $value => $label)
                        <option value="{{ $value }}">Tri : {{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm" data-testid="members-table">
                <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="py-2 pe-4">Élève</th>
                        <th class="py-2 pe-4 text-right">XP</th>
                        <th class="py-2 pe-4 text-right">Résolus</th>
                        <th class="py-2 pe-4 text-right">Réussite</th>
                        <th class="py-2 pe-4 text-right">Leçons</th>
                        <th class="py-2 pe-4 text-right">Certif.</th>
                        <th class="py-2">Dernière activité</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @forelse ($members as $row)
                        <tr wire:key="row-{{ $row['id'] }}">
                            <td class="py-2 pe-4">
                                <a href="{{ route('admin.organizations.member', [$organization, $row['id']]) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $row['name'] }}</a>
                                @if ($row['role'] === 'manager') <span class="ms-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-gray-700 dark:text-gray-300">responsable</span> @endif
                                <p class="text-xs text-gray-500">{{ $row['rank'] ?? '—' }}</p>
                            </td>
                            <td class="py-2 pe-4 text-right tabular-nums text-gray-800 dark:text-gray-100">{{ number_format($row['xp'], 0, ',', ' ') }}</td>
                            <td class="py-2 pe-4 text-right tabular-nums text-gray-800 dark:text-gray-100">{{ $row['solved'] }}</td>
                            <td class="py-2 pe-4 text-right tabular-nums">
                                @if ($row['success_rate'] === null)
                                    <span class="text-gray-400">—</span>
                                @else
                                    <span class="text-gray-800 dark:text-gray-100">{{ $row['success_rate'] }} %</span>
                                    <span class="block text-xs text-gray-500">{{ $row['submissions'] }} soumission(s)</span>
                                @endif
                            </td>
                            <td class="py-2 pe-4 text-right tabular-nums text-gray-800 dark:text-gray-100">{{ $row['lessons'] }}</td>
                            <td class="py-2 pe-4 text-right tabular-nums text-gray-800 dark:text-gray-100">{{ $row['certifications'] }}</td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">
                                @if ($row['last_activity'])
                                    <span title="{{ $row['last_activity']->isoFormat('LLL') }}">{{ $row['last_activity']->diffForHumans() }}</span>
                                    @if ($row['last_activity']->lt(now()->subDays(14))) <span class="ms-1 text-xs text-amber-600 dark:text-amber-400">⚠ inactif</span> @endif
                                @else
                                    <span class="text-gray-400">jamais</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-8 text-center text-gray-500">Aucun membre.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Exercices qui bloquent --}}
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Exercices qui bloquent</h2>
            <p class="mt-1 text-xs text-gray-500">Essayés par plusieurs élèves, rarement réussis. Messages d'échec les plus fréquents.</p>
            <ul class="mt-4 space-y-4 text-sm" data-testid="struggles">
                @forelse ($struggles as $row)
                    <li wire:key="struggle-{{ $row['exercise']->id }}">
                        <div class="flex items-baseline justify-between gap-3">
                            <a href="{{ route('exercises.show', $row['exercise']) }}" class="font-medium text-gray-900 hover:underline dark:text-white">{{ $row['exercise']->title }}</a>
                            <span class="shrink-0 tabular-nums text-gray-600 dark:text-gray-300">{{ $row['solved'] }}/{{ $row['tried'] }} élève(s) · {{ $row['attempts'] }} essai(s)</span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" role="img" aria-label="{{ $row['rate'] }} % de réussite">
                            <div class="h-full rounded-full bg-indigo-500" style="width: {{ max(2, $row['rate']) }}%"></div>
                        </div>
                        @if ($row['top_errors'])
                            <ul class="mt-2 space-y-0.5 text-xs text-gray-600 dark:text-gray-400">
                                @foreach ($row['top_errors'] as $message => $count)
                                    <li><span class="tabular-nums font-semibold">{{ $count }}×</span> {{ $message }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @empty
                    <li class="text-gray-500">Rien à signaler pour l'instant.</li>
                @endforelse
            </ul>
        </section>

        {{-- Compétences --}}
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Maîtrise par compétence</h2>
            <p class="mt-1 text-xs text-gray-500">Part des exercices d'entraînement de la compétence résolus, en moyenne par élève.</p>
            <ul class="mt-4 space-y-3 text-sm" data-testid="skills">
                @forelse ($skills as $row)
                    <li>
                        <div class="flex justify-between gap-3">
                            <span class="text-gray-800 dark:text-gray-100">{{ $row['skill']->name }}</span>
                            <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $row['mastery'] }} % <span class="text-xs text-gray-500">· {{ $row['exercises'] }} ex.</span></span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" aria-hidden="true">
                            <div class="h-full rounded-full bg-indigo-500" style="width: {{ max(1, $row['mastery']) }}%"></div>
                        </div>
                    </li>
                @empty
                    <li class="text-gray-500">Aucun exercice publié.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
