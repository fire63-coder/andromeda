@php
    $nav = $this->navigation;
@endphp

<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
    <nav class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <a href="{{ route('courses.show', $course) }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">← {{ $course->title }}</a>
        <span class="text-gray-500 dark:text-gray-400">Leçon {{ $nav['position'] }} / {{ $nav['total'] }} · {{ $lesson->chapter->title }}</span>
    </nav>

    <article class="mt-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 sm:p-8 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $lesson->title }}</h1>
            @if ($lesson->dataset && $this->dialects->count() > 1)
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    Moteur
                    <select wire:model.live="dialect" class="rounded-md border-gray-300 py-1 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        @foreach ($this->dialects as $option)
                            <option value="{{ $option->slug }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </div>

        <div class="mt-4 space-y-5">
            @foreach ($segments as $segment)
                @if ($segment['type'] === 'html')
                    <div class="prose max-w-none dark:prose-invert prose-code:before:content-none prose-code:after:content-none prose-pre:bg-gray-900">{!! $segment['html'] !!}</div>
                @elseif ($segment['type'] === 'mermaid')
                    <figure x-data="mermaidDiagram(@js($segment['source']))" class="overflow-x-auto rounded-lg bg-gray-50 p-4 dark:bg-gray-900/50">
                        <div x-ref="target"></div>
                        <p x-show="error" x-text="error" class="text-sm text-rose-600"></p>
                    </figure>
                @else
                    @php
                        $index = $segment['index'];
                        $result = $results[$index] ?? null;
                    @endphp
                    <div class="overflow-hidden rounded-lg bg-[#282c34] ring-1 ring-white/10" wire:key="snippet-{{ $index }}" data-testid="snippet-{{ $index }}"
                         x-data="sqlEditor({ doc: @js($snippets[$index] ?? ''), mode: @js($mode), schema: @js($schema), model: 'snippets.{{ $index }}', run: ['runSnippet', {{ $index }}], submit: null })"
                         x-on:sql-editor:replace.window="replace($event.detail)"
                         x-on:sql-editor:mode.window="setMode($event.detail.mode)">
                        <div wire:ignore class="min-h-16">
                            <div x-ref="editor"></div>
                        </div>
                        <div class="flex items-center justify-end gap-2 border-t border-white/10 px-3 py-2">
                            <span class="me-auto text-xs text-gray-500">Modifiez la requête puis <kbd class="rounded bg-white/10 px-1">Ctrl</kbd>+<kbd class="rounded bg-white/10 px-1">Entrée</kbd></span>
                            <button type="button" wire:click="resetSnippet({{ $index }})" class="rounded px-2 py-1 text-xs text-gray-400 hover:bg-white/5 hover:text-white">Réinitialiser</button>
                            <button type="button" x-on:click="call(['runSnippet', {{ $index }}])" wire:loading.attr="disabled" wire:target="runSnippet"
                                    class="rounded-md bg-white/10 px-3 py-1.5 text-xs font-semibold text-white hover:bg-white/20">▶ Exécuter</button>
                        </div>
                        @if ($result)
                            <div class="border-t border-white/10 bg-white text-sm dark:bg-gray-900" data-testid="snippet-result-{{ $index }}">
                                @if (! $result['success'])
                                    <p class="px-4 py-3 font-mono text-rose-600 dark:text-rose-400">{{ $result['error'] }}</p>
                                @elseif (empty($result['columns']))
                                    <p class="px-4 py-3 text-gray-600 dark:text-gray-300">{{ $result['affected_rows'] ?? 0 }} ligne(s) affectée(s) — modification annulée.</p>
                                @else
                                    <div class="max-h-72 overflow-auto">
                                        <table class="min-w-full font-mono text-xs">
                                            <thead class="sticky top-0 bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    @foreach ($result['columns'] as $column)
                                                        <th class="whitespace-nowrap px-3 py-1.5 text-left text-gray-600 dark:text-gray-300">{{ $column }}</th>
                                                    @endforeach
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                                @foreach ($result['rows'] as $row)
                                                    <tr>
                                                        @foreach ($row as $value)
                                                            <td class="whitespace-nowrap px-3 py-1 text-gray-800 dark:text-gray-200">{{ $value ?? 'NULL' }}</td>
                                                        @endforeach
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                    <p class="border-t border-gray-100 px-3 py-1 text-right text-xs text-gray-500 dark:border-gray-800">{{ $result['row_count'] }} ligne(s){{ $result['truncated'] ? ' (tronqué)' : '' }} · {{ $result['duration_ms'] }} ms</p>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    </article>

    @if ($exercises->isNotEmpty())
        <section class="mt-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">À vous de jouer</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($exercises as $exercise)
                    <li>
                        <a href="{{ route('exercises.show', $exercise) }}" wire:navigate class="flex items-center justify-between rounded-lg px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <span class="text-gray-800 dark:text-gray-100">{{ $solved->has($exercise->id) ? '✅' : '🎯' }} {{ $exercise->title }}</span>
                            <span class="text-xs text-amber-600 dark:text-amber-400">+{{ $exercise->xp_reward }} XP</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <footer class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            @if ($nav['previous'])
                <a href="{{ route('lessons.show', [$course, $nav['previous']]) }}" wire:navigate class="text-sm text-gray-600 hover:text-indigo-600 dark:text-gray-300">← {{ $nav['previous']->title }}</a>
            @endif
        </div>
        <div class="flex items-center gap-3">
            @if ($this->completed)
                <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400" data-testid="lesson-done">✓ Leçon terminée</span>
            @else
                <button type="button" wire:click="complete" class="rounded-md bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-500">
                    J'ai terminé cette leçon (+{{ $lesson->xp_reward }} XP)
                </button>
            @endif
            @if ($nav['next'])
                <a href="{{ route('lessons.show', [$course, $nav['next']]) }}" wire:navigate class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ $nav['next']->title }} →</a>
            @endif
        </div>
    </footer>
</div>
