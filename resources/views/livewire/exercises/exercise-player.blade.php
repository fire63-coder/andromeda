@php
    $verdictStyles = [
        'correct' => 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
        'wrong' => 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-200',
        'error' => 'border-rose-500/40 bg-rose-500/10 text-rose-800 dark:text-rose-200',
        'timeout' => 'border-rose-500/40 bg-rose-500/10 text-rose-800 dark:text-rose-200',
        'rejected' => 'border-sky-500/40 bg-sky-500/10 text-sky-800 dark:text-sky-200',
    ];
    $solved = $this->progress?->status === \App\Enums\ProgressStatus::Completed;
@endphp

<div class="mx-auto max-w-screen-2xl px-4 py-6 sm:px-6 lg:px-8">
    <div class="grid gap-6 lg:grid-cols-5">

        {{-- ─── Colonne gauche : énoncé, schéma, indices ─── --}}
        <section class="space-y-4 lg:col-span-2">
            <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                <div class="flex flex-wrap items-center gap-2 text-xs font-medium">
                    <span class="rounded-full px-2.5 py-1 text-white" style="background-color: {{ $exercise->level->color ?? '#6b7280' }}">
                        Niv. {{ $exercise->level->position }} · {{ $exercise->level->name }}
                    </span>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-700 dark:bg-gray-700 dark:text-gray-200">{{ $exercise->type->label() }}</span>
                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300" title="Difficulté">
                        {{ str_repeat('●', $exercise->difficulty) }}{{ str_repeat('○', 5 - $exercise->difficulty) }}
                    </span>
                    @if ($solved)
                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">✓ Résolu</span>
                    @endif
                    <span class="ms-auto rounded-full bg-amber-100 px-2.5 py-1 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300" title="XP gagnés à la première réussite">
                        {{ $solved ? 'XP déjà obtenus' : '+'.$this->potentialXp.' XP' }}
                    </span>
                </div>

                <h1 class="mt-4 text-xl font-semibold text-gray-900 dark:text-white">{{ $exercise->title }}</h1>

                <div class="prose prose-sm mt-3 max-w-none dark:prose-invert">
                    {!! $this->statementHtml !!}
                </div>

                @if ($exercise->time_limit_seconds && $startedAt)
                    <div class="mt-4 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
                         x-data="{ left: {{ max(0, $exercise->time_limit_seconds - now()->diffInSeconds(\Illuminate\Support\Carbon::parse($startedAt), true)) }} }"
                         x-init="setInterval(() => left = Math.max(0, left - 1), 1000)">
                        ⏱ Temps restant : <span class="font-mono font-semibold" x-text="`${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`"></span>
                    </div>
                @endif
            </article>

            @if ($this->tables !== [])
                <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Schéma · {{ $exercise->primaryDataset()->name }}</h2>
                    <div class="mt-3 space-y-2">
                        @foreach ($this->tables as $table)
                            <details class="group rounded-lg border border-gray-200 dark:border-gray-700" @if ($loop->first) open @endif>
                                <summary class="flex cursor-pointer items-center justify-between px-3 py-2 font-mono text-sm text-gray-800 dark:text-gray-100">
                                    <span>{{ $table['name'] }}</span>
                                    <span class="text-xs text-gray-400">{{ $table['rows'] }} lignes</span>
                                </summary>
                                <ul class="border-t border-gray-200 px-3 py-2 font-mono text-xs dark:border-gray-700">
                                    @foreach ($table['columns'] as $column)
                                        <li class="flex items-center gap-2 py-0.5 text-gray-700 dark:text-gray-300">
                                            <span class="w-6 text-[10px] font-bold {{ $column['primary'] ? 'text-amber-500' : 'text-sky-500' }}">
                                                {{ $column['primary'] ? 'PK' : ($column['references'] ? 'FK' : '') }}
                                            </span>
                                            <span>{{ $column['name'] }}</span>
                                            <span class="text-gray-400">{{ strtolower($column['type']) }}</span>
                                            @if ($column['references'])
                                                <span class="ms-auto text-gray-400">→ {{ $column['references'] }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endforeach
                    </div>
                </article>
            @endif

            @if (! empty($exercise->hints))
                <article class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        Indices ({{ $hintsRevealed }}/{{ count($exercise->hints) }})
                    </h2>
                    <ol class="mt-3 space-y-2 text-sm">
                        @foreach ($this->revealedHints as $hint)
                            <li class="prose prose-sm max-w-none rounded-lg bg-indigo-50 px-3 py-2 dark:prose-invert dark:bg-indigo-500/10">
                                {!! Str::markdown($hint['text'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                            </li>
                        @endforeach
                    </ol>
                    @if ($this->nextHintPenalty !== null)
                        <button type="button" wire:click="revealHint"
                                class="mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                            Afficher un indice{{ $this->nextHintPenalty > 0 && ! $solved ? ' (−'.$this->nextHintPenalty.' XP)' : '' }}
                        </button>
                    @endif
                </article>
            @endif
        </section>

        {{-- ─── Colonne droite : éditeur, verdict, résultats ─── --}}
        <section class="space-y-4 lg:col-span-3">
            @if ($this->isSqlExercise())
                <div class="overflow-hidden rounded-xl bg-[#282c34] shadow-sm ring-1 ring-white/10">
                    <div class="flex flex-wrap items-center gap-3 border-b border-white/10 px-4 py-2 text-sm text-gray-300">
                        <label class="flex items-center gap-2">
                            <span class="sr-only">Moteur SQL</span>
                            <select wire:model.live="dialect"
                                    class="rounded-md border-white/10 bg-white/5 py-1 text-sm text-gray-100 focus:border-indigo-400 focus:ring-indigo-400"
                                    @disabled($this->dialects->count() < 2)>
                                @forelse ($this->dialects as $option)
                                    <option value="{{ $option->slug }}" class="text-gray-900">{{ $option->name }}</option>
                                @empty
                                    <option value="">Aucun moteur disponible</option>
                                @endforelse
                            </select>
                        </label>
                        <button type="button" wire:click="resetEditor" wire:confirm="Revenir au code de départ ?"
                                class="rounded-md px-2 py-1 text-gray-400 hover:bg-white/5 hover:text-white">
                            Réinitialiser
                        </button>
                        <span class="ms-auto hidden text-xs text-gray-500 sm:inline">
                            <kbd class="rounded bg-white/10 px-1">Ctrl</kbd>+<kbd class="rounded bg-white/10 px-1">Entrée</kbd> exécuter ·
                            <kbd class="rounded bg-white/10 px-1">Ctrl</kbd>+<kbd class="rounded bg-white/10 px-1">Maj</kbd>+<kbd class="rounded bg-white/10 px-1">Entrée</kbd> valider
                        </span>
                    </div>

                    <div wire:ignore
                         x-data="sqlEditor({ doc: @js($sql), mode: @js($this->currentDialect()?->editor_mode ?? 'sqlite'), schema: @js($this->completionSchema) })"
                         x-on:sql-editor:replace.window="replace($event.detail.sql)"
                         x-on:sql-editor:mode.window="setMode($event.detail.mode)"
                         class="relative h-72">
                        <div x-ref="editor" class="h-full" data-testid="sql-editor"></div>
                        <div x-show="! ready" class="absolute inset-0 grid place-items-center text-sm text-gray-500">Chargement de l'éditeur…</div>
                    </div>

                    <div class="flex items-center justify-end gap-3 border-t border-white/10 px-4 py-3">
                        <span wire:loading wire:target="run,submit" class="text-sm text-gray-400">Exécution…</span>
                        <button type="button" wire:click="run" wire:loading.attr="disabled" wire:target="run,submit"
                                class="rounded-md bg-white/10 px-4 py-2 text-sm font-semibold text-white hover:bg-white/20 disabled:opacity-50">
                            ▶ Exécuter
                        </button>
                        <button type="button" wire:click="submit" wire:loading.attr="disabled" wire:target="run,submit"
                                class="rounded-md bg-indigo-500 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-400 disabled:opacity-50">
                            Valider
                        </button>
                    </div>
                </div>
            @else
                <form wire:submit="submit" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                    <fieldset class="space-y-2">
                        <legend class="sr-only">Réponses</legend>
                        @foreach ($choices as $choice)
                            <label wire:key="choice-{{ $choice->id }}"
                                   class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-4 py-3 hover:bg-gray-50 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:border-gray-700 dark:hover:bg-gray-700/50 dark:has-[:checked]:bg-indigo-500/10">
                                <input type="checkbox" value="{{ $choice->id }}" wire:model="selectedChoices"
                                       class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                <span class="font-mono text-sm text-gray-800 dark:text-gray-100">{{ $choice->body }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <div class="mt-4 flex justify-end">
                        <button type="submit" wire:loading.attr="disabled"
                                class="rounded-md bg-indigo-500 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-400 disabled:opacity-50">
                            Valider
                        </button>
                    </div>
                </form>
            @endif

            {{-- Verdict --}}
            @if ($verdict)
                <div wire:key="verdict-{{ md5(json_encode($verdict)) }}" role="status" data-testid="verdict"
                     class="rounded-xl border px-5 py-4 {{ $verdictStyles[$verdict['status']] ?? $verdictStyles['error'] }}">
                    <div class="flex items-start gap-3">
                        <span class="text-xl leading-none">{{ ['correct' => '🎉', 'wrong' => '🧐', 'rejected' => '🛑'][$verdict['status']] ?? '⚠️' }}</span>
                        <div class="flex-1">
                            <p class="font-semibold">{{ $verdict['label'] }}@if ($verdict['score'] > 0 && $verdict['score'] < 100) · {{ $verdict['score'] }} %@endif</p>
                            <p class="mt-1 text-sm">{{ $verdict['message'] }}</p>

                            @if (! empty($verdict['feedback']['expected_columns']))
                                <p class="mt-2 text-sm">Colonnes attendues :
                                    @foreach ($verdict['feedback']['expected_columns'] as $column)
                                        <code class="rounded bg-black/5 px-1 dark:bg-white/10">{{ $column }}</code>
                                    @endforeach
                                </p>
                            @endif

                            @foreach (['missing_rows' => ['Lignes attendues manquantes', 'missing_count'], 'extra_rows' => ['Lignes en trop', 'extra_count']] as $key => [$title, $countKey])
                                @if (! empty($verdict['feedback'][$key]))
                                    <div class="mt-3">
                                        <p class="text-xs font-semibold uppercase tracking-wide opacity-80">{{ $title }} ({{ $verdict['feedback'][$countKey] }})</p>
                                        <table class="mt-1 text-left font-mono text-xs">
                                            @foreach ($verdict['feedback'][$key] as $row)
                                                <tr>
                                                    @foreach ($row as $value)
                                                        <td class="pe-4">{{ $value ?? 'NULL' }}</td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </table>
                                    </div>
                                @endif
                            @endforeach

                            @if (! empty($verdict['feedback']['choices']))
                                <ul class="mt-3 space-y-1 text-sm">
                                    @foreach ($verdict['feedback']['choices'] as $choice)
                                        <li><span class="font-mono">{{ $choice['correct'] ? '✓' : '✗' }} {{ $choice['body'] }}</span> — {{ $choice['explanation'] }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                        @if ($verdict['xp'] > 0)
                            <span class="rounded-full bg-amber-400 px-3 py-1 text-sm font-bold text-amber-950">+{{ $verdict['xp'] }} XP</span>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Résultat --}}
            @if ($result)
                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10" data-testid="result">
                    @if (! $result['success'])
                        <div class="px-5 py-4">
                            <p class="text-sm font-semibold text-rose-600 dark:text-rose-400">
                                {{ ['timeout' => 'Délai dépassé', 'rejected' => 'Requête refusée', 'internal' => 'Exécution impossible'][$result['error_type']] ?? 'Erreur SQL' }}
                            </p>
                            <pre class="mt-2 whitespace-pre-wrap font-mono text-sm text-gray-700 dark:text-gray-300">{{ $result['error'] }}</pre>
                        </div>
                    @elseif (empty($result['columns']))
                        <p class="px-5 py-4 text-sm text-gray-700 dark:text-gray-300">
                            Requête exécutée{{ $result['affected_rows'] !== null ? ' : '.$result['affected_rows'].' ligne(s) affectée(s)' : '' }}.
                            <span class="text-gray-400">({{ $result['duration_ms'] }} ms, modifications annulées)</span>
                        </p>
                    @else
                        <div class="flex items-center justify-between border-b border-gray-200 px-5 py-2 text-xs text-gray-500 dark:border-gray-700 dark:text-gray-400">
                            <span>
                                @isset($result['state_of'])
                                    État de « {{ $result['state_of'] }} » après exécution ({{ $result['affected_rows'] ?? 0 }} ligne(s) modifiée(s), puis annulées) ·
                                @endisset
                                {{ $result['row_count'] }} ligne{{ $result['row_count'] > 1 ? 's' : '' }}{{ $result['truncated'] ? ' (aperçu tronqué)' : '' }}
                            </span>
                            <span>{{ $result['duration_ms'] }} ms</span>
                        </div>
                        <div class="max-h-96 overflow-auto">
                            <table class="min-w-full divide-y divide-gray-200 font-mono text-sm dark:divide-gray-700">
                                <thead class="sticky top-0 bg-gray-50 dark:bg-gray-900">
                                    <tr>
                                        @foreach ($result['columns'] as $column)
                                            <th class="whitespace-nowrap px-4 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $column }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                                    @foreach ($result['rows'] as $row)
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                            @foreach ($row as $value)
                                                <td class="whitespace-nowrap px-4 py-1.5 text-gray-800 dark:text-gray-200">
                                                    @if ($value === null)
                                                        <span class="italic text-gray-400">NULL</span>
                                                    @elseif (is_bool($value))
                                                        {{ $value ? 'true' : 'false' }}
                                                    @else
                                                        {{ $value }}
                                                    @endif
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </section>
    </div>
</div>
