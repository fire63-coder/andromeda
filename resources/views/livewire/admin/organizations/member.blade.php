@php
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
    $heading = 'text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $statusColors = [
        'correct' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
        'wrong' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    ];
@endphp

<div class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <a href="{{ route('admin.organizations.progress', $organization) }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Suivi de {{ $organization->name }}</a>
        <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $member->name }}</h1>
        <p class="text-sm text-gray-500">{{ $member->email }} · {{ number_format($member->xp, 0, ',', ' ') }} XP · {{ $member->rank?->name ?? 'sans rang' }} · série de {{ $member->current_streak ?? 0 }} jour(s)</p>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Cours commencés</h2>
            <ul class="mt-3 space-y-3 text-sm">
                @forelse ($courses as $row)
                    <li>
                        <div class="flex justify-between gap-2">
                            <span class="text-gray-800 dark:text-gray-100">{{ $row['course']->title }}</span>
                            <span class="tabular-nums text-gray-600 dark:text-gray-300">{{ $row['percent'] }} %</span>
                        </div>
                        <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700" aria-hidden="true">
                            <div class="h-full rounded-full bg-indigo-500" style="width: {{ $row['percent'] }}%"></div>
                        </div>
                    </li>
                @empty
                    <li class="text-gray-500">Aucun cours commencé.</li>
                @endforelse
            </ul>
        </section>

        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Certifications</h2>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($attempts as $attempt)
                    <li class="flex justify-between gap-2">
                        <span class="text-gray-800 dark:text-gray-100">{{ $attempt->certification->title }}</span>
                        <span class="text-gray-600 dark:text-gray-300">{{ $attempt->status->label() }}@if ($attempt->score !== null) · {{ $attempt->score }} %@endif</span>
                    </li>
                @empty
                    <li class="text-gray-500">Aucune tentative.</li>
                @endforelse
            </ul>
        </section>

        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">Badges</h2>
            <ul class="mt-3 flex flex-wrap gap-2 text-sm">
                @forelse ($badges as $badge)
                    <li class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-700 dark:bg-gray-700 dark:text-gray-200" title="{{ $badge->description }}">{{ $badge->icon }} {{ $badge->name }}</li>
                @empty
                    <li class="text-gray-500">Aucun badge.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="{{ $card }}">
        <h2 class="{{ $heading }}">Dernières soumissions</h2>
        <ul class="mt-3 divide-y divide-gray-100 text-sm dark:divide-gray-700/60" data-testid="member-submissions">
            @forelse ($submissions as $submission)
                <li class="py-2" x-data="{ open: false }" wire:key="sub-{{ $submission->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            <span class="font-medium text-gray-900 dark:text-white">{{ $submission->exercise?->title ?? 'Exercice supprimé' }}</span>
                            <span class="ms-2 rounded px-1.5 py-0.5 text-xs {{ $statusColors[$submission->status->value] ?? 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300' }}">{{ $submission->status->label() }}</span>
                        </span>
                        <span class="text-xs text-gray-500">
                            {{ $submission->dialect?->name }} · <span title="{{ $submission->created_at->isoFormat('LLL') }}">{{ $submission->created_at->diffForHumans() }}</span>
                            @if ($submission->query_sql) · <button type="button" @click="open = !open" class="text-indigo-600 hover:underline dark:text-indigo-400" x-text="open ? 'masquer' : 'voir la requête'"></button> @endif
                        </span>
                    </div>
                    @if (! $submission->is_correct && ($submission->error_message || ($submission->feedback['message'] ?? null)))
                        <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Str::limit($submission->error_message ?? $submission->feedback['message'], 200) }}</p>
                    @endif
                    @if ($submission->query_sql)
                        <pre x-show="open" x-cloak class="mt-2 overflow-x-auto rounded-md bg-gray-900 p-3 text-xs text-gray-100">{{ $submission->query_sql }}</pre>
                    @endif
                </li>
            @empty
                <li class="py-6 text-center text-gray-500">Aucune soumission.</li>
            @endforelse
        </ul>
    </section>
</div>
