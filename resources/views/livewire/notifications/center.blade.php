<div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 dark:text-white">Notifications</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $unreadCount ? $unreadCount.' non lue'.($unreadCount > 1 ? 's' : '') : 'Tout est lu.' }}
                · <a href="{{ route('profile.show') }}#notifications" class="text-indigo-600 hover:underline dark:text-indigo-400">Préférences d'e-mail</a>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" wire:model.live="unread" class="rounded border-gray-300 text-indigo-600 dark:border-gray-600 dark:bg-gray-900">
                Non lues seulement
            </label>
            @if ($unreadCount)
                <button type="button" wire:click="markAllAsRead" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200 dark:ring-gray-600 dark:hover:bg-gray-700">Tout marquer comme lu</button>
            @endif
            <button type="button" wire:click="deleteRead" wire:confirm="Supprimer toutes les notifications déjà lues ?" class="rounded-md px-3 py-1.5 text-sm font-medium text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">Supprimer les lues</button>
        </div>
    </div>

    <ul class="divide-y divide-gray-100 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-900/5 dark:divide-gray-700 dark:bg-gray-800 dark:ring-white/10" data-testid="notification-list">
        @forelse ($notifications as $notification)
            <li wire:key="n-{{ $notification->id }}" @class(['flex items-start gap-4 px-5 py-4', 'bg-indigo-50/60 dark:bg-indigo-500/5' => ! $notification->read_at])>
                <span class="text-2xl leading-none" aria-hidden="true">{{ $notification->data['icon'] ?? '🔔' }}</span>
                <button type="button" wire:click="open('{{ $notification->id }}')" class="min-w-0 flex-1 text-start">
                    <span @class(['block text-sm', 'font-semibold text-gray-900 dark:text-white' => ! $notification->read_at, 'text-gray-800 dark:text-gray-200' => $notification->read_at])>{{ $notification->data['title'] ?? 'Notification' }}</span>
                    @if (! empty($notification->data['body']))
                        <span class="mt-0.5 block text-sm text-gray-600 dark:text-gray-400">{{ $notification->data['body'] }}</span>
                    @endif
                    <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400" title="{{ $notification->created_at->isoFormat('LLLL') }}">{{ $notification->created_at->diffForHumans() }}</span>
                </button>
                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" wire:click="toggleRead('{{ $notification->id }}')" class="rounded p-1.5 text-xs text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700"
                            title="{{ $notification->read_at ? 'Marquer comme non lue' : 'Marquer comme lue' }}">
                        {{ $notification->read_at ? '●' : '○' }}<span class="sr-only">{{ $notification->read_at ? 'Marquer comme non lue' : 'Marquer comme lue' }}</span>
                    </button>
                    <button type="button" wire:click="delete('{{ $notification->id }}')" class="rounded p-1.5 text-xs text-gray-500 hover:bg-rose-50 hover:text-rose-600 dark:text-gray-400 dark:hover:bg-rose-500/10" title="Supprimer">
                        ✕<span class="sr-only">Supprimer</span>
                    </button>
                </div>
            </li>
        @empty
            <li class="px-5 py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ $unread ? 'Aucune notification non lue.' : 'Vous n\'avez pas encore de notification.' }}
            </li>
        @endforelse
    </ul>

    {{ $notifications->links() }}
</div>
