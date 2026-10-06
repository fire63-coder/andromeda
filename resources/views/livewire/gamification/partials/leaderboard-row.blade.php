@php
    $isMe = $row['user']->is(auth()->user());
    $medal = [1 => '🥇', 2 => '🥈', 3 => '🥉'][$row['position']] ?? null;
    $delta = $before !== null ? $before - $row['position'] : null;
@endphp
<tr @class(['bg-indigo-50 dark:bg-indigo-500/10' => $isMe]) wire:key="row-{{ $row['user']->id }}">
    <td class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-200">
        {{ $medal ?? $row['position'] }}
        @if ($delta > 0)
            <span class="ms-1 text-xs text-emerald-600" title="Progression depuis hier">▲{{ $delta }}</span>
        @elseif ($delta < 0)
            <span class="ms-1 text-xs text-rose-500" title="Recul depuis hier">▼{{ abs($delta) }}</span>
        @endif
    </td>
    <td class="px-4 py-3">
        <div class="flex items-center gap-3">
            <img src="{{ $row['user']->profile_photo_url }}" alt="" class="size-8 rounded-full object-cover">
            <div>
                <p class="font-medium text-gray-900 dark:text-white">{{ $row['user']->name }}{{ $isMe ? ' (vous)' : '' }}</p>
                @if ($row['user']->rank)
                    <p class="text-xs font-medium" style="color: {{ $row['user']->rank->color }}">{{ $row['user']->rank->name }}</p>
                @endif
            </div>
        </div>
    </td>
    <td class="hidden px-4 py-3 text-center text-sm text-gray-600 dark:text-gray-300 sm:table-cell">
        {{ $row['user']->current_streak > 0 ? '🔥 '.$row['user']->current_streak : '—' }}
    </td>
    <td class="hidden px-4 py-3 text-center text-sm text-gray-600 dark:text-gray-300 sm:table-cell">{{ $row['user']->badges_count }}</td>
    <td class="px-4 py-3 text-right font-mono font-semibold text-gray-900 dark:text-white">{{ number_format($row['score'], 0, ',', ' ') }}</td>
</tr>
