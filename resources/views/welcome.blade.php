<x-guest-layout>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
        <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-5 sm:px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                <x-application-mark class="block h-9 w-auto" />
                <span class="hidden text-lg sm:inline">{{ config('app.name') }}</span>
            </a>
            <nav class="flex items-center gap-3 whitespace-nowrap text-sm">
                <a href="{{ route('login') }}" class="font-medium text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Se connecter</a>
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="rounded-md bg-indigo-600 px-3 py-2 font-semibold text-white hover:bg-indigo-500 sm:px-4">Créer un compte</a>
                @endif
            </nav>
        </header>

        <main>
            <section class="mx-auto grid max-w-6xl items-center gap-10 px-4 pb-16 pt-10 sm:px-6 lg:grid-cols-2">
                <div>
                    <h1 class="text-4xl font-bold tracking-tight text-gray-900 sm:text-5xl dark:text-white">Apprenez le SQL en écrivant du SQL.</h1>
                    <p class="mt-5 text-lg text-gray-600 dark:text-gray-300">
                        Des cours courts, des exercices corrigés instantanément sur de vraies bases de données,
                        et une progression jusqu'à la certification : des premiers <code class="rounded bg-gray-200 px-1 text-base dark:bg-gray-800">SELECT</code>
                        aux triggers PL/pgSQL et aux plans d'exécution.
                    </p>
                    <div class="mt-8 flex flex-wrap gap-3">
                        @if (Route::has('register'))
                            <a href="{{ route('register') }}" class="rounded-md bg-indigo-600 px-5 py-3 font-semibold text-white shadow-sm hover:bg-indigo-500">Commencer gratuitement</a>
                        @endif
                        <a href="{{ route('login') }}" class="rounded-md bg-white px-5 py-3 font-semibold text-gray-800 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-100 dark:ring-gray-700">J'ai déjà un compte</a>
                    </div>
                    <dl class="mt-10 grid grid-cols-3 gap-4 text-center sm:text-left">
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">Cours</dt><dd class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $stats['courses'] }}</dd></div>
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">Exercices</dt><dd class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $stats['exercises'] }}</dd></div>
                        <div><dt class="text-sm text-gray-500 dark:text-gray-400">Moteurs</dt><dd class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $stats['engines']->count() }}</dd></div>
                    </dl>
                </div>

                {{-- Aperçu d'un exercice : l'énoncé, la requête, le verdict. --}}
                <div class="overflow-hidden rounded-2xl bg-gray-900 shadow-xl ring-1 ring-white/10" aria-hidden="true">
                    <div class="flex items-center gap-2 border-b border-white/10 px-4 py-3 text-xs text-gray-400">
                        <span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span><span class="h-2.5 w-2.5 rounded-full bg-emerald-400"></span>
                        <span class="ms-2">Les mieux payés de chaque département · PostgreSQL</span>
                    </div>
                    <pre class="overflow-x-auto px-5 py-4 font-mono text-sm leading-6 text-gray-100"><span class="text-violet-300">WITH</span> classement <span class="text-violet-300">AS</span> (
  <span class="text-violet-300">SELECT</span> d.name, e.name, e.salary,
         <span class="text-sky-300">RANK</span>() <span class="text-violet-300">OVER</span> (
           <span class="text-violet-300">PARTITION BY</span> e.department_id
           <span class="text-violet-300">ORDER BY</span> e.salary <span class="text-violet-300">DESC</span>) <span class="text-violet-300">AS</span> rang
  <span class="text-violet-300">FROM</span> employees e
  <span class="text-violet-300">JOIN</span> departments d <span class="text-violet-300">ON</span> d.id = e.department_id
)
<span class="text-violet-300">SELECT</span> * <span class="text-violet-300">FROM</span> classement <span class="text-violet-300">WHERE</span> rang = <span class="text-amber-300">1</span>;</pre>
                    <div class="border-t border-white/10 bg-emerald-500/10 px-5 py-3 text-sm text-emerald-300">
                        🎉 Bravo ! Votre requête est correcte, y compris sur les jeux de test cachés. <span class="font-semibold">+50 XP</span>
                    </div>
                </div>
            </section>

            <section class="border-y border-gray-200 bg-white py-14 dark:border-gray-800 dark:bg-gray-950/40">
                <div class="mx-auto max-w-6xl px-4 sm:px-6">
                    <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Quatre niveaux, du premier SELECT à la certification</h2>
                    <ol class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($stats['levels'] as $level)
                            <li class="rounded-xl bg-gray-50 p-5 ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
                                <span class="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold text-white" style="background-color: {{ $level->color }}">{{ $level->position }}</span>
                                <h3 class="mt-3 font-semibold text-gray-900 dark:text-white">{{ $level->name }}</h3>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $level->description }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </section>

            <section class="mx-auto grid max-w-6xl gap-8 px-4 py-14 sm:px-6 md:grid-cols-3">
                @foreach ([
                    ['🧪', 'Un vrai bac à sable', 'Vos requêtes s\'exécutent sur '.($stats['engines']->isNotEmpty() ? $stats['engines']->join(', ', ' et ') : 'de vraies bases').', isolées et annulées après chaque essai : rien ne peut casser.'],
                    ['🎯', 'Une correction qui explique', 'Lignes manquantes, colonnes en trop, jeu de test caché, plan d\'exécution : chaque erreur est expliquée, avec des indices si besoin.'],
                    ['🏆', 'Progresser en jouant', 'XP, rangs, badges, défi quotidien, arène chronométrée et classements, puis des certifications vérifiables en ligne.'],
                ] as [$icon, $title, $text])
                    <div>
                        <div class="text-3xl">{{ $icon }}</div>
                        <h3 class="mt-3 font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $text }}</p>
                    </div>
                @endforeach
            </section>
        </main>

        <footer class="border-t border-gray-200 py-6 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
            {{ config('app.name') }} · Plateforme d'apprentissage du SQL
        </footer>
    </div>
</x-guest-layout>
