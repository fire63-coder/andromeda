@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $mono = $input.' font-mono text-sm';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
    $h2 = 'text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $error = fn ($key) => $errors->first($key);
@endphp

<form wire:submit="save" class="mx-auto max-w-6xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.exercises.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Exercices</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $exercise ? $title : 'Nouvel exercice' }}</h1>
            @if ($exercise?->reviewed_at)
                <p class="text-xs text-gray-500">Relu par {{ $exercise->reviewer?->name }} le {{ $exercise->reviewed_at->isoFormat('LL') }}</p>
            @endif
        </div>
        <div class="flex items-center gap-3">
            @if ($saved) <span class="text-sm text-emerald-600" x-data x-init="setTimeout(() => $el.remove(), 3000)">{{ $saved }}</span> @endif
            <button type="button" wire:click="runTest" wire:loading.attr="disabled" class="rounded-md bg-white px-4 py-2 text-sm font-semibold text-gray-800 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-700 dark:text-gray-100 dark:ring-gray-600">
                <span wire:loading.remove wire:target="runTest">Tester la solution</span>
                <span wire:loading wire:target="runTest">Test…</span>
            </button>
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Enregistrer</button>
        </div>
    </div>

    @if ($errors->any())
        <div class="rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" role="alert">
            Corrigez les champs signalés : {{ implode(' ', array_slice($errors->all(), 0, 3)) }}
        </div>
    @endif

    @if ($test)
        <section @class([$card, 'ring-2', 'ring-emerald-400' => $test['passed'], 'ring-rose-400' => ! $test['passed']]) data-testid="solution-test">
            <h2 class="{{ $h2 }}">{{ $test['passed'] ? '✅ Test réussi' : '❌ Test en échec' }}</h2>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($test['checks'] as $check)
                    <li @class(['text-emerald-700 dark:text-emerald-400' => $check['ok'], 'text-rose-600 dark:text-rose-400' => ! $check['ok']])>
                        {{ $check['ok'] ? '✓' : '✗' }} <strong>{{ $check['label'] }}</strong> — {{ $check['message'] }}
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="{{ $card }} grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="sm:col-span-2">
            <label class="{{ $label }}" for="title">Titre</label>
            <input id="title" type="text" wire:model.blur="title" class="{{ $input }}">
            @if ($error('title')) <p class="mt-1 text-sm text-rose-600">{{ $error('title') }}</p> @endif
        </div>
        <div>
            <label class="{{ $label }}" for="slug">Identifiant</label>
            <input id="slug" type="text" wire:model="slug" class="{{ $mono }}">
            @if ($error('slug')) <p class="mt-1 text-sm text-rose-600">{{ $error('slug') }}</p> @endif
        </div>
        <div>
            <label class="{{ $label }}" for="type">Type</label>
            <select id="type" wire:model.live="type" class="{{ $input }}">
                @foreach ($types as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $label }}" for="level">Niveau</label>
            <select id="level" wire:model="levelId" class="{{ $input }}">
                @foreach ($levels as $level)
                    <option value="{{ $level->id }}">{{ $level->position }}. {{ $level->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $label }}" for="lesson">Leçon</label>
            <select id="lesson" wire:model="lessonId" class="{{ $input }}">
                <option value="">— Réservé (certifications, défis) —</option>
                @foreach ($lessons as $lesson)
                    <option value="{{ $lesson->id }}">{{ $lesson->chapter->course->title }} › {{ $lesson->title }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="{{ $label }}" for="dialect">Moteur imposé</label>
            <select id="dialect" wire:model="dialectId" class="{{ $input }}">
                <option value="">Aucun (SQL standard)</option>
                @foreach ($dialects as $dialect)
                    <option value="{{ $dialect->id }}">{{ $dialect->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="{{ $label }}" for="difficulty">Difficulté</label>
                <input id="difficulty" type="number" min="1" max="5" wire:model="difficulty" class="{{ $input }}">
            </div>
            <div>
                <label class="{{ $label }}" for="xp">XP</label>
                <input id="xp" type="number" min="0" wire:model="xpReward" class="{{ $input }}">
            </div>
        </div>
        <div>
            <label class="{{ $label }}" for="status">Statut</label>
            <select id="status" wire:model="status" class="{{ $input }}">
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
            @if ($error('status')) <p class="mt-1 text-sm text-rose-600">{{ $error('status') }}</p> @endif
            @cannot('publish', App\Models\Exercise::class)
                <p class="mt-1 text-xs text-gray-500">« En relecture » : un administrateur publiera après test.</p>
            @endcannot
        </div>
    </section>

    <section class="{{ $card }} grid gap-4 lg:grid-cols-2">
        <div>
            <label class="{{ $label }}" for="statement">Énoncé (Markdown)</label>
            <textarea id="statement" wire:model.live.debounce.600ms="statement" rows="8" class="{{ $mono }}"></textarea>
            @if ($error('statement')) <p class="mt-1 text-sm text-rose-600">{{ $error('statement') }}</p> @endif
        </div>
        <div>
            <p class="{{ $label }}">Aperçu</p>
            <div class="prose prose-sm mt-1 max-w-none rounded-lg bg-gray-50 p-4 dark:prose-invert dark:bg-gray-900/50 prose-code:before:content-none prose-code:after:content-none">{!! $statementHtml !!}</div>
        </div>
    </section>

    @if ($isMcq)
        <section class="{{ $card }}">
            <h2 class="{{ $h2 }}">Réponses</h2>
            @if ($error('choices')) <p class="mt-2 text-sm text-rose-600">{{ $error('choices') }}</p> @endif
            <div class="mt-3 space-y-3">
                @foreach ($choices as $i => $choice)
                    <div wire:key="choice-{{ $i }}" class="grid gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-12 dark:border-gray-700">
                        <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="choices.{{ $i }}.is_correct" class="rounded border-gray-300 text-emerald-600"> Correcte</label>
                        <input type="text" wire:model="choices.{{ $i }}.body" placeholder="Réponse" class="rounded-md border-gray-300 text-sm sm:col-span-4 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <input type="text" wire:model="choices.{{ $i }}.explanation" placeholder="Explication affichée après réponse" class="rounded-md border-gray-300 text-sm sm:col-span-5 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                        <button type="button" wire:click="removeChoice({{ $i }})" class="text-sm text-rose-600 sm:col-span-1">Retirer</button>
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="addChoice" class="mt-3 text-sm font-medium text-indigo-600 dark:text-indigo-400">+ Ajouter une réponse</button>
        </section>
    @else
        <section class="{{ $card }} grid gap-4 lg:grid-cols-2">
            <div>
                <label class="{{ $label }}" for="starter">Code de départ {{ $type === 'bug_fix' ? '(requête boguée)' : '(optionnel)' }}</label>
                <textarea id="starter" wire:model="starterSql" rows="7" class="{{ $mono }}"></textarea>
                @if ($error('starterSql')) <p class="mt-1 text-sm text-rose-600">{{ $error('starterSql') }}</p> @endif
            </div>
            <div>
                <label class="{{ $label }}" for="solution">Solution de référence (jamais montrée aux élèves)</label>
                <textarea id="solution" wire:model="solutionSql" rows="7" class="{{ $mono }}"></textarea>
                @if ($error('solutionSql')) <p class="mt-1 text-sm text-rose-600">{{ $error('solutionSql') }}</p> @endif
            </div>
        </section>

        <section class="{{ $card }} grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <h2 class="{{ $h2 }} sm:col-span-2 lg:col-span-3">Validation</h2>
            <div>
                <label class="{{ $label }}" for="strategy">Stratégie</label>
                <select id="strategy" wire:model.live="strategy" class="{{ $input }}">
                    @foreach ($strategies as $option)
                        @continue($option->value === 'choices')
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
            </div>
            <fieldset>
                <legend class="{{ $label }}">Instructions autorisées</legend>
                <div class="mt-2 flex flex-wrap gap-4 text-sm text-gray-700 dark:text-gray-300">
                    @foreach (['select' => 'SELECT', 'dml' => 'INSERT / UPDATE / DELETE', 'ddl' => 'CREATE / ALTER / DROP', 'routine' => 'Fonctions et procédures (PostgreSQL)'] as $value => $text)
                        <label class="flex items-center gap-2"><input type="checkbox" value="{{ $value }}" wire:model="allowedStatements" class="rounded border-gray-300 text-indigo-600"> {{ $text }}</label>
                    @endforeach
                </div>
                <p class="mt-1 text-xs text-gray-500">Code stocké : l'exercice n'est proposé que sur PostgreSQL. Vérifiez-le avec des requêtes de contrôle (« état des données »), par exemple <code>SELECT ma_fonction(id) FROM ...</code>.</p>
            </fieldset>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="{{ $label }}" for="tolerance">Tolérance num.</label>
                    <input id="tolerance" type="text" wire:model="floatTolerance" placeholder="0.000001" class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}" for="maxms">Délai (ms)</label>
                    <input id="maxms" type="number" wire:model="maxExecutionMs" class="{{ $input }}">
                </div>
            </div>
            <div>
                <label class="{{ $label }}" for="required">Mots-clés imposés</label>
                <input id="required" type="text" wire:model="requiredKeywords" placeholder="JOIN, GROUP" class="{{ $mono }}">
            </div>
            <div>
                <label class="{{ $label }}" for="forbidden">Mots-clés interdits</label>
                <input id="forbidden" type="text" wire:model="forbiddenKeywords" placeholder="LIMIT" class="{{ $mono }}">
            </div>
            <label class="flex items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model="checkColumnNames" class="rounded border-gray-300 text-indigo-600"> Noms de colonnes imposés
            </label>
            @if ($strategy === 'state_check')
                <div class="sm:col-span-2 lg:col-span-3">
                    <label class="{{ $label }}" for="checks">Requêtes de contrôle (une par ligne : <code>nom: SELECT …</code>)</label>
                    <textarea id="checks" wire:model="checkQueries" rows="3" class="{{ $mono }}"></textarea>
                    @if ($error('checkQueries')) <p class="mt-1 text-sm text-rose-600">{{ $error('checkQueries') }}</p> @endif
                </div>
            @endif
            @if ($type === 'timed')
                <div>
                    <label class="{{ $label }}" for="timelimit">Temps limite (s)</label>
                    <input id="timelimit" type="number" wire:model="timeLimitSeconds" class="{{ $input }}">
                    @if ($error('timeLimitSeconds')) <p class="mt-1 text-sm text-rose-600">{{ $error('timeLimitSeconds') }}</p> @endif
                </div>
            @endif
        </section>

        <section class="{{ $card }} grid gap-4 sm:grid-cols-2">
            <h2 class="{{ $h2 }} sm:col-span-2">Jeux de données</h2>
            <div>
                <label class="{{ $label }}" for="primary">Jeu visible (montré à l'élève)</label>
                <select id="primary" wire:model="primaryDatasetId" class="{{ $input }}">
                    <option value="">—</option>
                    @foreach ($datasets as $dataset)
                        <option value="{{ $dataset->id }}">{{ $dataset->name }}</option>
                    @endforeach
                </select>
                @if ($error('primaryDatasetId')) <p class="mt-1 text-sm text-rose-600">{{ $error('primaryDatasetId') }}</p> @endif
            </div>
            <fieldset>
                <legend class="{{ $label }}">Jeux de test cachés (anti « valeurs codées en dur »)</legend>
                <div class="mt-2 space-y-1 text-sm text-gray-700 dark:text-gray-300">
                    @foreach ($datasets as $dataset)
                        <label class="flex items-center gap-2"><input type="checkbox" value="{{ $dataset->id }}" wire:model="hiddenDatasetIds" class="rounded border-gray-300 text-indigo-600"> {{ $dataset->name }}</label>
                    @endforeach
                </div>
                @if ($error('hiddenDatasetIds')) <p class="mt-1 text-sm text-rose-600">{{ $error('hiddenDatasetIds') }}</p> @endif
            </fieldset>
        </section>
    @endif

    <section class="{{ $card }}">
        <h2 class="{{ $h2 }}">Indices</h2>
        <div class="mt-3 space-y-2">
            @foreach ($hints as $i => $hint)
                <div wire:key="hint-{{ $i }}" class="flex gap-2">
                    <input type="text" wire:model="hints.{{ $i }}.text" placeholder="Indice {{ $i + 1 }} (Markdown)" class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    <input type="number" min="0" wire:model="hints.{{ $i }}.xp_penalty" class="w-24 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200" aria-label="Pénalité XP">
                    <button type="button" wire:click="removeHint({{ $i }})" class="text-sm text-rose-600">Retirer</button>
                </div>
            @endforeach
        </div>
        <button type="button" wire:click="addHint" class="mt-3 text-sm font-medium text-indigo-600 dark:text-indigo-400">+ Ajouter un indice</button>
    </section>

    <section class="{{ $card }}">
        <h2 class="{{ $h2 }}">Compétences travaillées</h2>
        <div class="mt-3 grid gap-2 text-sm text-gray-700 sm:grid-cols-3 lg:grid-cols-4 dark:text-gray-300">
            @foreach ($skills as $skill)
                <label class="flex items-center gap-2"><input type="checkbox" value="{{ $skill->id }}" wire:model="skillIds" class="rounded border-gray-300 text-indigo-600"> {{ $skill->name }}</label>
            @endforeach
        </div>
    </section>
</form>
