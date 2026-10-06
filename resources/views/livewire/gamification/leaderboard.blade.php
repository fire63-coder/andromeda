<div class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Classement</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">L'XP se gagne en résolvant des exercices et en débloquant des badges.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <div class="inline-flex rounded-lg bg-gray-200/70 p-1 dark:bg-gray-800" role="tablist">
                @foreach ($periods as $key => $label)
                    <button type="button" role="tab" wire:click="$set('period', '{{ $key }}')" aria-selected="{{ $period === $key ? 'true' : 'false' }}"
                            class="rounded-md px-3 py-1.5 text-sm font-medium {{ $period === $key ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-white' : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            @if ($this->organizations->isNotEmpty())
                <select wire:model.live="scope" class="rounded-lg border-gray-300 py-1.5 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    <option value="global">Tous les apprenants</option>
                    @foreach ($this->organizations as $organization)
                        <option value="{{ $organization->id }}">{{ $organization->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
    </div>

    <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10" wire:loading.class="opacity-60">
        @if ($standings->isEmpty())
            <p class="px-6 py-12 text-center text-sm text-gray-500 dark:text-gray-400">Personne n'a encore marqué de points sur cette période. À vous de jouer !</p>
        @else
            <table class="min-w-full divide-y divide-gray-100 dark:divide-gray-700" data-testid="leaderboard">
                <thead class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="w-20 px-4 py-3 text-left">#</th>
                        <th class="px-4 py-3 text-left">Apprenant</th>
                        <th class="hidden px-4 py-3 text-center sm:table-cell">Série</th>
                        <th class="hidden px-4 py-3 text-center sm:table-cell">Badges</th>
                        <th class="px-4 py-3 text-right">XP</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                    @foreach ($standings as $row)
                        @include('livewire.gamification.partials.leaderboard-row', ['row' => $row, 'before' => $previous[$row['user']->id] ?? null])
                    @endforeach
                    @if ($me)
                        <tr><td colspan="5" class="py-1 text-center text-gray-400">⋮</td></tr>
                        @include('livewire.gamification.partials.leaderboard-row', ['row' => [...$me, 'user' => auth()->user()->loadCount('badges')->load('rank')], 'before' => $previous[auth()->id()] ?? null])
                    @endif
                </tbody>
            </table>
        @endif
    </div>
</div>
