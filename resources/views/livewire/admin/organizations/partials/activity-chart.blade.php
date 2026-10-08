{{-- Soumissions par jour : une seule série (pas de légende, le titre la nomme), survol par barre, vue tableau. --}}
@php
    $width = 720;
    $height = 160;
    $top = 12;
    $base = $height - 24;
    $slot = $width / count($activity);
    $barWidth = min(18, $slot - 4);
    $bars = collect($activity)->values()->map(function (array $day, int $i) use ($slot, $barWidth, $base, $top, $activityMax) {
        $h = $day['submissions'] ? max(3, ($base - $top) * $day['submissions'] / $activityMax) : 0;
        $x = $i * $slot + ($slot - $barWidth) / 2;
        $y = $base - $h;
        $r = min(4, $h, $barWidth / 2);

        return [...$day, 'x' => $x, 'y' => $y, 'h' => $h, 'center' => $i * $slot + $slot / 2,
            // Extrémité arrondie (4px), base carrée sur l'axe.
            'path' => $h > 0 ? sprintf('M%.1f,%.1f V%.1f Q%.1f,%.1f %.1f,%.1f H%.1f Q%.1f,%.1f %.1f,%.1f V%.1f Z',
                $x, $base, $y + $r, $x, $y, $x + $r, $y, $x + $barWidth - $r, $x + $barWidth, $y, $x + $barWidth, $y + $r, $base) : null];
    });
@endphp

<style>
    .viz-activity { --series-1: #2a78d6; --grid: #e5e7eb; --axis-text: #6b7280; }
    @media (prefers-color-scheme: dark) { .viz-activity { --series-1: #3987e5; --grid: #374151; --axis-text: #9ca3af; } }
</style>

<div class="viz-activity relative" x-data="{ hover: null, days: @js($bars->map(fn ($b) => ['label' => $b['label'], 'submissions' => $b['submissions'], 'correct' => $b['correct'], 'center' => $b['center']])) }">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full" role="img" aria-label="Soumissions par jour sur 30 jours, maximum {{ $activityMax }}">
        <line x1="0" x2="{{ $width }}" y1="{{ $top }}" y2="{{ $top }}" stroke="var(--grid)" stroke-dasharray="2 4" />
        <text x="2" y="{{ $top - 3 }}" font-size="10" fill="var(--axis-text)">{{ $activityMax }}</text>
        <line x1="0" x2="{{ $width }}" y1="{{ $base }}" y2="{{ $base }}" stroke="var(--grid)" />
        @foreach ($bars as $i => $bar)
            @if ($bar['path'])
                <path d="{{ $bar['path'] }}" fill="var(--series-1)" :opacity="hover === null || hover === {{ $i }} ? 1 : 0.45" />
            @endif
            {{-- Une date par semaine, en partant d'aujourd'hui (aligné à droite) pour ne jamais déborder ni se chevaucher. --}}
            @if (($bars->count() - 1 - $i) % 7 === 0 && $i > 0)
                <text x="{{ $loop->last ? $width : $bar['center'] }}" y="{{ $height - 6 }}" font-size="10" text-anchor="{{ $loop->last ? 'end' : 'middle' }}" fill="var(--axis-text)">{{ $loop->last ? 'aujourd\'hui' : \Illuminate\Support\Carbon::parse($bar['date'])->isoFormat('D MMM') }}</text>
            @endif
            {{-- Zone de survol : toute la hauteur du créneau, plus large que la barre. --}}
            <rect x="{{ $i * $slot }}" y="0" width="{{ $slot }}" height="{{ $base }}" fill="transparent" @mouseenter="hover = {{ $i }}" @mouseleave="hover = null" />
        @endforeach
    </svg>

    <template x-if="hover !== null">
        <div class="pointer-events-none absolute top-0 whitespace-nowrap rounded-md bg-gray-900 px-2.5 py-1.5 text-xs text-white shadow-lg dark:bg-gray-100 dark:text-gray-900"
             :style="(() => { const p = days[hover].center / {{ $width }} * 100; return `left: ${p}%; transform: translateX(${p > 80 ? '-100%' : (p < 20 ? '0' : '-50%')})`; })()">
            <p class="font-semibold" x-text="days[hover].label"></p>
            <p><span x-text="days[hover].submissions"></span> soumission(s) · <span x-text="days[hover].correct"></span> réussie(s)</p>
        </div>
    </template>

    <details class="mt-2 text-xs text-gray-500">
        <summary class="cursor-pointer">Voir les données</summary>
        <table class="mt-2 w-full text-left">
            <thead><tr><th class="py-1">Jour</th><th>Soumissions</th><th>Réussies</th></tr></thead>
            <tbody>
                @foreach (array_reverse($activity) as $day)
                    @continue($day['submissions'] === 0)
                    <tr><td class="py-0.5">{{ $day['label'] }}</td><td>{{ $day['submissions'] }}</td><td>{{ $day['correct'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </details>
</div>
