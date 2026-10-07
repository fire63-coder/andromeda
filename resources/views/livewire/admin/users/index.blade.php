<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    @include('livewire.admin.partials.tabs')

    <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Utilisateurs</h1>
        <div class="flex gap-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Nom ou e-mail…" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
            <select wire:model.live="role" class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                <option value="">Tous les rôles</option>
                @foreach ($roles as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if ($flash)
        <p class="mt-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" role="status">{{ $flash }}</p>
    @endif
    @error('users') <p class="mt-4 rounded-lg bg-rose-50 px-4 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300" role="alert">{{ $message }}</p> @enderror

    <div class="mt-4 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
        <table class="min-w-full divide-y divide-gray-100 text-sm dark:divide-gray-700">
            <thead class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                <tr>
                    <th class="px-4 py-3">Utilisateur</th>
                    <th class="px-4 py-3">Progression</th>
                    <th class="px-4 py-3">Dernière activité</th>
                    <th class="px-4 py-3">Rôle</th>
                    <th class="px-4 py-3">Compte</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60">
                @foreach ($users as $user)
                    @php
                        $self = $user->is(auth()->user());
                    @endphp
                    <tr wire:key="user-{{ $user->id }}" @class(['opacity-60' => ! $user->is_active])>
                        <td class="px-4 py-3">
                            <p class="font-medium text-gray-900 dark:text-white">{{ $user->name }}{{ $self ? ' (vous)' : '' }}</p>
                            <p class="text-xs text-gray-500">{{ $user->email }}</p>
                        </td>
                        <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                            {{ number_format($user->xp, 0, ',', ' ') }} XP · {{ $user->rank?->name ?? '—' }}
                            <p class="text-xs text-gray-500">{{ $user->submissions_count }} soumission(s) · {{ $user->badges_count }} badge(s)</p>
                        </td>
                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ $user->last_activity_on?->isoFormat('LL') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <select wire:change="changeRole({{ $user->id }}, $event.target.value)" @disabled($self) aria-label="Rôle de {{ $user->name }}"
                                    class="rounded-md border-gray-300 py-1 text-sm disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                                @foreach ($roles as $option)
                                    <option value="{{ $option->value }}" @selected($user->role === $option)>{{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-4 py-3">
                            @unless ($self)
                                <button type="button" wire:click="toggleActive({{ $user->id }})"
                                        @if ($user->is_active) wire:confirm="Désactiver {{ $user->name }} ? Il sera déconnecté et ne pourra plus se connecter." @endif
                                        class="text-sm font-medium {{ $user->is_active ? 'text-rose-600 hover:underline' : 'text-emerald-600 hover:underline' }}">
                                    {{ $user->is_active ? 'Désactiver' : 'Réactiver' }}
                                </button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>
</div>
