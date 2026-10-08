{{-- Frise d'un scénario de concurrence : une ligne par étape, une colonne par session. --}}
@php
    $sessions = collect($timeline)->pluck('session')->unique()->sort()->values();
    $explain = [
        '40001' => 'Échec de sérialisation : PostgreSQL annule cette transaction pour éviter une anomalie. L\'application doit la rejouer.',
        '40P01' => 'Interblocage : chaque session attendait un verrou détenu par l\'autre. PostgreSQL a annulé cette transaction.',
        '55P03' => 'Verrou non obtenu dans le délai imparti.',
        '57014' => 'Délai dépassé : la session est restée bloquée trop longtemps.',
        '25P02' => 'La transaction a déjà échoué : ses instructions sont ignorées jusqu\'au ROLLBACK (ou COMMIT, qui annule).',
    ];
    $sessionColors = ['A' => 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200', 'B' => 'bg-fuchsia-100 text-fuchsia-800 dark:bg-fuchsia-500/20 dark:text-fuchsia-200', 'C' => 'bg-lime-100 text-lime-800 dark:bg-lime-500/20 dark:text-lime-200'];
@endphp

<div class="overflow-x-auto" data-testid="scenario-timeline">
    <table class="min-w-full table-fixed text-left text-xs">
        <thead class="bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
            <tr>
                <th class="w-10 px-3 py-2">#</th>
                @foreach ($sessions as $session)
                    <th class="px-3 py-2"><span class="rounded px-1.5 py-0.5 font-semibold {{ $sessionColors[$session] ?? '' }}">Session {{ $session }}</span></th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
            @foreach ($timeline as $step)
                <tr class="align-top">
                    <td class="px-3 py-2 tabular-nums text-gray-400">{{ $step['step'] }}</td>
                    @foreach ($sessions as $session)
                        <td class="px-3 py-2">
                            @if ($step['session'] === $session)
                                <pre class="whitespace-pre-wrap font-mono text-[11px] leading-5 text-gray-800 dark:text-gray-100">{{ $step['sql'] }}</pre>

                                @php
                                    $resumed = match (true) {
                                        ! $step['completed_at_step'] => '',
                                        $step['completed_at_step'] >= count($timeline) => ', reprise à la fin du scénario',
                                        default => ', reprise après l\'étape '.$step['completed_at_step'],
                                    };
                                @endphp
                                @if ($step['waited'])
                                    <p class="mt-1 font-medium text-amber-700 dark:text-amber-300">⏳ Bloquée en attente d'un verrou{{ $resumed }}</p>
                                @elseif ($step['queued'] ?? false)
                                    <p class="mt-1 text-gray-500 dark:text-gray-400">… La session {{ $step['session'] }} est encore bloquée : cette étape attend son tour{{ $resumed }}</p>
                                @endif

                                @if ($step['error'])
                                    <p class="mt-1 font-medium text-rose-700 dark:text-rose-300">✖ {{ strtok($step['error'], "\n") }}</p>
                                    @isset($explain[$step['sqlstate']])
                                        <p class="text-gray-600 dark:text-gray-400">{{ $explain[$step['sqlstate']] }}</p>
                                    @endisset
                                @elseif (! empty($step['columns']))
                                    <table class="mt-1 font-mono text-[11px]">
                                        <tr class="text-gray-500">@foreach ($step['columns'] as $column)<th class="pe-3 font-semibold">{{ $column }}</th>@endforeach</tr>
                                        @foreach ($step['rows'] as $row)
                                            <tr class="text-gray-800 dark:text-gray-100">@foreach ($row as $value)<td class="pe-3">{{ $value ?? 'NULL' }}</td>@endforeach</tr>
                                        @endforeach
                                    </table>
                                @elseif ($step['status'] === 'done')
                                    <p class="mt-1 text-emerald-700 dark:text-emerald-300">✓ {{ $step['affected_rows'] ? $step['affected_rows'].' ligne(s) modifiée(s)' : 'OK' }}</p>
                                @endif
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
