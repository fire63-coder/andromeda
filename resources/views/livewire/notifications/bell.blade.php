<div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false" wire:poll.60s.visible>
    <button type="button" x-on:click="open = ! open" data-testid="notification-bell"
            class="relative rounded-full p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
            aria-label="Notifications{{ $this->unreadCount ? ' ('.$this->unreadCount.' non lue'.($this->unreadCount > 1 ? 's' : '').')' : '' }}"
            :aria-expanded="open">
        <svg class="size-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        @if ($this->unreadCount)
            <span class="absolute -end-0.5 -top-0.5 flex min-w-5 items-center justify-center rounded-full bg-rose-600 px-1 text-xs font-semibold text-white" data-testid="notification-count">
                {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open" x-cloak x-transition.opacity
         class="absolute end-0 z-50 mt-2 w-80 overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-900/10 dark:bg-gray-800 dark:ring-white/10">
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2 dark:border-gray-700">
            <p class="text-sm font-semibold text-gray-900 dark:text-white">Notifications</p>
            @if ($this->unreadCount)
                <button type="button" wire:click="markAllAsRead" class="text-xs font-medium text-indigo-600 hover:underline dark:text-indigo-400">Tout marquer comme lu</button>
            @endif
        </div>

        <ul class="max-h-96 divide-y divide-gray-100 overflow-y-auto dark:divide-gray-700">
            @forelse ($this->latest as $notification)
                <li wire:key="bell-{{ $notification->id }}">
                    <button type="button" wire:click="open('{{ $notification->id }}')"
                            @class(['flex w-full gap-3 px-4 py-3 text-start hover:bg-gray-50 dark:hover:bg-gray-700/60', 'bg-indigo-50/60 dark:bg-indigo-500/5' => ! $notification->read_at])>
                        <span class="text-xl leading-none" aria-hidden="true">{{ $notification->data['icon'] ?? '🔔' }}</span>
                        <span class="min-w-0 flex-1">
                            <span @class(['block truncate text-sm', 'font-semibold text-gray-900 dark:text-white' => ! $notification->read_at, 'text-gray-700 dark:text-gray-300' => $notification->read_at])>{{ $notification->data['title'] ?? 'Notification' }}</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $notification->created_at->diffForHumans() }}</span>
                        </span>
                        @unless ($notification->read_at)
                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-indigo-600" aria-label="Non lue"></span>
                        @endunless
                    </button>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">Aucune notification.</li>
            @endforelse
        </ul>

        <a href="{{ route('notifications.index') }}" wire:navigate class="block border-t border-gray-100 px-4 py-2 text-center text-sm font-medium text-indigo-600 hover:bg-gray-50 dark:border-gray-700 dark:text-indigo-400 dark:hover:bg-gray-700/60">
            Voir toutes les notifications
        </a>
    </div>
</div>
