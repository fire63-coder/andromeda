<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles
    </head>
    <body class="font-sans antialiased">
        <x-banner />

        <div class="min-h-screen bg-gray-100 dark:bg-gray-900">
            {{-- Épreuve en mode examen : pas de navigation vers le reste de l'application. --}}
            @unless ($exam ?? false)
                @livewire('navigation-menu')
            @endunless

            <!-- Page Heading -->
            @if (isset($header))
                <header class="bg-white dark:bg-gray-800 shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endif

            <!-- Page Content -->
            <main>
                {{ $slot }}
            </main>
        </div>

        @stack('modals')

        {{-- Notifications de gamification (événements Livewire « xp-gained », « badge-unlocked », « rank-up ») --}}
        <div x-data="{
                items: [],
                push(item) {
                    const id = Date.now() + Math.random();
                    this.items.push({ id, ...item });
                    setTimeout(() => this.items = this.items.filter(i => i.id !== id), item.kind === 'xp' ? 4000 : 7000);
                },
             }"
             x-on:xp-gained.window="push({ kind: 'xp', title: `+${$event.detail.amount} XP`, text: `Total : ${$event.detail.total} XP` })"
             x-on:badge-unlocked.window="push({ kind: 'badge', icon: $event.detail.icon ?? '🏅', title: `Badge débloqué : ${$event.detail.name}`, text: $event.detail.description })"
             x-on:rank-up.window="push({ kind: 'rank', icon: '⭐', title: 'Nouveau rang !', text: $event.detail.name })"
             class="pointer-events-none fixed bottom-6 end-6 z-50 flex w-80 flex-col gap-2" role="status" aria-live="polite">
            <template x-for="item in items" :key="item.id">
                <div x-transition.opacity
                     :class="item.kind === 'xp' ? 'bg-amber-400 text-amber-950' : 'bg-gray-900 text-white ring-1 ring-amber-400/60 dark:bg-gray-800'"
                     class="flex items-start gap-3 rounded-xl px-4 py-3 shadow-lg">
                    <span x-show="item.icon" x-text="item.icon" class="text-2xl leading-none"></span>
                    <div>
                        <p class="font-semibold" x-text="item.title"></p>
                        <p class="text-sm opacity-80" x-text="item.text"></p>
                    </div>
                </div>
            </template>
        </div>

        @livewireScripts
    </body>
</html>
