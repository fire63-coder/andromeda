<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.admin.partials.tabs')

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Défis</h1>
        <div class="flex items-center gap-3">
            <select wire:model.live="type" aria-label="Type de défi" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                <option value="">Tous les types</option>
                @foreach ($types as $value => $text)
                    <option value="{{ $value }}">{{ $text }}</option>
                @endforeach
            </select>
            <a href="{{ route('admin.challenges.create') }}" wire:navigate class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Nouveau défi</a>
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">Défi</th>
                    <th class="px-4 py-3">Période</th>
                    <th class="px-4 py-3">Exercices</th>
                    <th class="px-4 py-3">Participants</th>
                    <th class="px-4 py-3">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @forelse ($challenges as $challenge)
                    <tr wire:key="challenge-{{ $challenge->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.challenges.edit', $challenge) }}" wire:navigate class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $challenge->title }}</a>
                            <p class="text-xs text-gray-500">
                                {{ $challenge->type->label() }}
                                @if ($challenge->organization) · {{ $challenge->organization->name }} @endif
                                @if ((float) $challenge->xp_multiplier !== 1.0) · XP ×{{ rtrim(rtrim($challenge->xp_multiplier, '0'), '.') }} @endif
                            </p>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $challenge->starts_at->isoFormat('D MMM YYYY HH:mm') }}
                            @if ($challenge->ends_at) → {{ $challenge->ends_at->isoFormat('D MMM HH:mm') }} @endif
                            @if ($challenge->isRunning()) <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">en cours</span> @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $challenge->exercises_count }}</td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ $challenge->participations_count }}
                            @if ($challenge->participations_count) <span class="text-xs text-gray-500">· {{ $challenge->finishers_count }} classé(s) · meilleur {{ $challenge->best_score }} pts</span> @endif
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $challenge->status->label() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Aucun défi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $challenges->links() }}</div>
</div>
