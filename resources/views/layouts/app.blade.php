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
            @livewire('navigation-menu')

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

        {{-- Notification de gain d'XP (événement Livewire « xp-gained ») --}}
        <div x-data="{ show: false, amount: 0, total: 0, timer: null }"
             x-on:xp-gained.window="amount = $event.detail.amount; total = $event.detail.total; show = true; clearTimeout(timer); timer = setTimeout(() => show = false, 4000)"
             x-show="show" x-transition.opacity x-cloak role="status"
             class="pointer-events-none fixed bottom-6 end-6 z-50 rounded-xl bg-amber-400 px-5 py-3 font-semibold text-amber-950 shadow-lg">
            +<span x-text="amount"></span> XP · total <span x-text="total"></span>
        </div>

        @livewireScripts
    </body>
</html>
