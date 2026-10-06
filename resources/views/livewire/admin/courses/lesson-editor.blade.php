@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
@endphp

<div class="mx-auto max-w-screen-2xl space-y-4 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.courses.edit', $course) }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← {{ $course->title }}</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $title ?: 'Leçon' }}</h1>
        </div>
        <div class="flex items-center gap-3">
            @if ($saved) <span class="text-sm text-emerald-600" x-data x-init="setTimeout(() => $el.remove(), 3000)">{{ $saved }}</span> @endif
            <button type="button" wire:click="checkSnippets" class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-600">Tester les exemples</button>
            <button type="button" wire:click="save" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Enregistrer</button>
        </div>
    </div>

    <div class="grid gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-900/5 sm:grid-cols-3 lg:grid-cols-6 dark:bg-gray-800 dark:ring-white/10">
        <div class="sm:col-span-2">
            <label class="{{ $label }}" for="title">Titre</label>
            <input id="title" type="text" wire:model.live.debounce.500ms="title" class="{{ $input }}">
            @error('title') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="slug">Identifiant</label>
            <input id="slug" type="text" wire:model="slug" class="{{ $input }} font-mono text-sm">
            @error('slug') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="dataset">Jeu des exemples</label>
            <select id="dataset" wire:model="datasetId" class="{{ $input }}">
                <option value="">Aucun</option>
                @foreach ($datasets as $dataset)
                    <option value="{{ $dataset->id }}">{{ $dataset->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="{{ $label }}" for="minutes">Durée</label>
                <input id="minutes" type="number" wire:model="estimatedMinutes" class="{{ $input }}" placeholder="min">
            </div>
            <div>
                <label class="{{ $label }}" for="xp">XP</label>
                <input id="xp" type="number" wire:model="xpReward" class="{{ $input }}">
            </div>
        </div>
        <div>
            <label class="{{ $label }}" for="status">Statut</label>
            <select id="status" wire:model="status" class="{{ $input }}">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            @error('status') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>
    </div>

    @if ($checks !== [])
        <ul class="space-y-1 rounded-xl bg-white p-4 text-sm shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10" data-testid="snippet-checks">
            @foreach ($checks as $check)
                <li @class(['font-mono', 'text-emerald-700 dark:text-emerald-400' => $check['ok'], 'text-rose-600 dark:text-rose-400' => ! $check['ok']])>
                    {{ $check['ok'] ? '✓' : '✗' }} Exemple {{ $check['index'] + 1 }} — {{ $check['message'] }}
                </li>
            @endforeach
        </ul>
    @endif

    <div class="grid gap-4 lg:grid-cols-2">
        <div>
            <label for="content" class="{{ $label }}">
                Contenu (Markdown) — <code class="text-xs">```sql runnable</code> pour un exemple exécutable, <code class="text-xs">```mermaid</code> pour un diagramme
            </label>
            <textarea id="content" wire:model.live.debounce.600ms="content" rows="32" spellcheck="false"
                      class="{{ $input }} font-mono text-sm leading-relaxed"></textarea>
            @error('content') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </div>

        <div class="max-h-[52rem] overflow-y-auto rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10" data-testid="preview">
            <p class="mb-4 text-xs font-semibold uppercase tracking-wide text-gray-500">Aperçu</p>
            <div class="space-y-4">
                @foreach ($segments as $segment)
                    @if ($segment['type'] === 'html')
                        <div class="prose max-w-none dark:prose-invert prose-code:before:content-none prose-code:after:content-none">{!! $segment['html'] !!}</div>
                    @elseif ($segment['type'] === 'sql')
                        <div class="rounded-lg bg-[#282c34] text-sm">
                            <p class="border-b border-white/10 px-3 py-1 text-xs text-emerald-400">▶ Exemple exécutable n° {{ $segment['index'] + 1 }}</p>
                            <pre class="overflow-x-auto px-4 py-3 font-mono text-gray-100">{{ $segment['sql'] }}</pre>
                        </div>
                    @else
                        <figure wire:key="mermaid-{{ md5($segment['source']) }}" x-data="mermaidDiagram(@js($segment['source']))" class="overflow-x-auto rounded-lg bg-gray-50 p-3 dark:bg-gray-900/50">
                            <div x-ref="target" wire:ignore></div>
                            <p x-show="error" x-text="error" class="text-sm text-rose-600"></p>
                        </figure>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
</div>
