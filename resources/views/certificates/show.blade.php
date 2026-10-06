<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Certificat {{ $attempt->certificate_code }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gray-100 font-sans antialiased print:bg-white">
    <main class="mx-auto max-w-3xl px-4 py-12">
        <article class="rounded-2xl border-8 border-double border-indigo-200 bg-white p-10 text-center shadow-sm print:shadow-none" data-testid="certificate">
            <p class="text-sm font-semibold uppercase tracking-[0.3em] text-indigo-600">Certificat de réussite</p>
            <p class="mt-8 text-gray-500">Décerné à</p>
            <h1 class="mt-2 text-4xl font-bold text-gray-900">{{ $attempt->user->name }}</h1>
            <p class="mt-8 text-gray-500">pour avoir réussi l'épreuve</p>
            <h2 class="mt-2 text-2xl font-semibold text-gray-900">{{ $attempt->certification->title }}</h2>
            <p class="mt-2 text-gray-600">
                Niveau {{ $attempt->certification->level->position }} · {{ $attempt->certification->level->name }}
                @if ($attempt->certification->dialect) · {{ $attempt->certification->dialect->name }} @endif
            </p>
            <p class="mt-6 text-lg text-gray-800">Score : <strong>{{ $attempt->score }} %</strong> <span class="text-gray-500">(seuil {{ $attempt->certification->passing_score }} %)</span></p>
            <p class="mt-1 text-gray-600">Délivré le {{ $attempt->issued_at->isoFormat('LL') }}</p>

            <div class="mt-10 inline-flex items-center gap-2 rounded-full bg-emerald-50 px-4 py-2 text-sm text-emerald-700">
                ✓ Certificat authentique — code <span class="font-mono font-semibold">{{ $attempt->certificate_code }}</span>
            </div>
        </article>
        <p class="mt-6 text-center text-sm text-gray-500 print:hidden">
            <button type="button" onclick="window.print()" class="underline">Imprimer</button> ·
            Lien de vérification : <span class="font-mono">{{ url()->current() }}</span>
        </p>
    </main>
</body>
</html>
