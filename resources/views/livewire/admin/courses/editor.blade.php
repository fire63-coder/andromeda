@php
    $input = 'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
    $label = 'block text-sm font-medium text-gray-700 dark:text-gray-300';
    $card = 'rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10';
    $iconButton = 'rounded px-1.5 py-0.5 text-gray-500 hover:bg-gray-100 hover:text-gray-900 disabled:opacity-30 dark:hover:bg-gray-700 dark:hover:text-white';
@endphp

<div class="mx-auto max-w-5xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="{{ route('admin.courses.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Cours</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $course ? $course->title : 'Nouveau cours' }}</h1>
        </div>
        @if ($course?->status === \App\Enums\ContentStatus::Published)
            <a href="{{ route('courses.show', $course) }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400" target="_blank">Voir côté élève ↗</a>
        @endif
    </div>

    <form wire:submit="save" class="{{ $card }} space-y-4">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="title" class="{{ $label }}">Titre</label>
                <input id="title" type="text" wire:model.blur="title" class="{{ $input }}">
                @error('title') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="slug" class="{{ $label }}">Identifiant (URL)</label>
                <input id="slug" type="text" wire:model="slug" class="{{ $input }} font-mono">
                @error('slug') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="level" class="{{ $label }}">Niveau</label>
                <select id="level" wire:model="levelId" class="{{ $input }}">
                    @foreach ($levels as $level)
                        <option value="{{ $level->id }}">{{ $level->position }}. {{ $level->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="dialect" class="{{ $label }}">Dialecte</label>
                <select id="dialect" wire:model="dialectId" class="{{ $input }}">
                    <option value="">SQL standard (tous moteurs)</option>
                    @foreach ($dialects as $dialect)
                        <option value="{{ $dialect->id }}">{{ $dialect->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="summary" class="{{ $label }}">Résumé (catalogue)</label>
                <input id="summary" type="text" wire:model="summary" class="{{ $input }}">
            </div>
            <div class="sm:col-span-2">
                <label for="description" class="{{ $label }}">Présentation (Markdown)</label>
                <textarea id="description" wire:model="description" rows="4" class="{{ $input }} font-mono text-sm"></textarea>
            </div>
            <div>
                <label for="minutes" class="{{ $label }}">Durée estimée (min)</label>
                <input id="minutes" type="number" min="1" wire:model="estimatedMinutes" class="{{ $input }}">
            </div>
            <div>
                <label for="status" class="{{ $label }}">Statut</label>
                <select id="status" wire:model="status" class="{{ $input }}">
                    @foreach ($statuses as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </select>
                @cannot('publish', App\Models\Course::class)
                    <p class="mt-1 text-xs text-gray-500">Passez le cours « En relecture » : un administrateur le publiera.</p>
                @endcannot
                @error('status') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex items-center justify-end gap-3">
            @if ($saved) <span class="text-sm text-emerald-600" x-data x-init="setTimeout(() => $el.remove(), 3000)">{{ $saved }}</span> @endif
            <button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">{{ $course ? 'Enregistrer' : 'Créer le cours' }}</button>
        </div>
    </form>

    @if ($course)
        <section class="{{ $card }}">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Chapitres et leçons</h2>
            @error('chapters') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror

            <ol class="mt-4 space-y-4">
                @foreach ($chapters as $chapter)
                    <li wire:key="chapter-{{ $chapter->id }}" class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-gray-900 dark:text-white">{{ $loop->iteration }}. {{ $chapter->title }}</span>
                            @if ($chapter->schema_diagram) <span class="text-xs text-sky-600" title="Schéma relationnel défini">◇ schéma</span> @endif
                            <span class="ms-auto flex gap-1 text-sm">
                                <button type="button" wire:click="moveChapter({{ $chapter->id }}, -1)" class="{{ $iconButton }}" @disabled($loop->first) aria-label="Monter">↑</button>
                                <button type="button" wire:click="moveChapter({{ $chapter->id }}, 1)" class="{{ $iconButton }}" @disabled($loop->last) aria-label="Descendre">↓</button>
                                <button type="button" wire:click="editChapter({{ $chapter->id }})" class="{{ $iconButton }}">Modifier</button>
                                <button type="button" wire:click="deleteChapter({{ $chapter->id }})" wire:confirm="Supprimer ce chapitre ?" class="{{ $iconButton }} text-rose-600">Supprimer</button>
                            </span>
                        </div>

                        @if ($editingChapterId === $chapter->id)
                            <form wire:submit="saveChapter" class="mt-3 space-y-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-900/50">
                                <input type="text" wire:model="chapterForm.title" class="{{ $input }}" aria-label="Titre du chapitre">
                                <textarea wire:model="chapterForm.summary" rows="2" class="{{ $input }}" placeholder="Résumé"></textarea>
                                <div>
                                    <label class="{{ $label }}">Schéma relationnel (Mermaid erDiagram)</label>
                                    <div class="mt-1 flex gap-2">
                                        <select wire:model="chapterForm.dataset_id" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                            <option value="">Reprendre le schéma d'un jeu…</option>
                                            @foreach ($datasets as $dataset)
                                                <option value="{{ $dataset->id }}">{{ $dataset->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" wire:click="useDatasetDiagram" class="rounded-md bg-white px-3 py-1.5 text-sm ring-1 ring-gray-300 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">Utiliser</button>
                                    </div>
                                    <textarea wire:model="chapterForm.schema_diagram" rows="6" class="{{ $input }} font-mono text-xs"></textarea>
                                </div>
                                <div class="flex justify-end gap-2">
                                    <button type="button" wire:click="$set('editingChapterId', null)" class="rounded-md px-3 py-1.5 text-sm text-gray-600 dark:text-gray-300">Annuler</button>
                                    <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white">Enregistrer le chapitre</button>
                                </div>
                            </form>
                        @endif

                        <ol class="mt-3 space-y-1 ps-4">
                            @foreach ($chapter->lessons as $lesson)
                                <li wire:key="lesson-{{ $lesson->id }}" class="flex items-center gap-2 text-sm">
                                    <a href="{{ route('admin.lessons.edit', $lesson) }}" wire:navigate class="text-indigo-600 hover:underline dark:text-indigo-400">{{ $lesson->title }}</a>
                                    <span class="text-xs text-gray-500">{{ $lesson->status->label() }}</span>
                                    <span class="ms-auto flex gap-1">
                                        <button type="button" wire:click="moveLesson({{ $lesson->id }}, -1)" class="{{ $iconButton }}" @disabled($loop->first) aria-label="Monter">↑</button>
                                        <button type="button" wire:click="moveLesson({{ $lesson->id }}, 1)" class="{{ $iconButton }}" @disabled($loop->last) aria-label="Descendre">↓</button>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                        <form wire:submit="addLesson({{ $chapter->id }})" class="mt-2 flex gap-2 ps-4">
                            <input type="text" wire:model="newLesson.{{ $chapter->id }}" placeholder="Titre d'une nouvelle leçon" class="flex-1 rounded-md border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                            <button type="submit" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600">+ Leçon</button>
                        </form>
                        @error("newLesson.{$chapter->id}") <p class="ps-4 text-sm text-rose-600">{{ $message }}</p> @enderror
                    </li>
                @endforeach
            </ol>

            <form wire:submit="addChapter" class="mt-4 flex gap-2">
                <input type="text" wire:model="newChapter" placeholder="Titre d'un nouveau chapitre" class="flex-1 rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">+ Chapitre</button>
            </form>
            @error('newChapter') <p class="mt-1 text-sm text-rose-600">{{ $message }}</p> @enderror
        </section>
    @endif
</div>
