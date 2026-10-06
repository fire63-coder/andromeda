<div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
    <div>
        <a href="{{ route('courses.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">← Cours</a>
        <p class="mt-3 text-xs font-semibold uppercase tracking-wide" style="color: {{ $course->level->color }}">Niveau {{ $course->level->position }} · {{ $course->level->name }}</p>
        <h1 class="mt-1 text-2xl font-semibold text-gray-900 dark:text-white">{{ $course->title }}</h1>
        @if ($course->description)
            <div class="prose prose-sm mt-3 max-w-none dark:prose-invert">{!! app(\App\Services\Content\LessonRenderer::class)->html($course->description) !!}</div>
        @elseif ($course->summary)
            <p class="mt-2 text-gray-600 dark:text-gray-300">{{ $course->summary }}</p>
        @endif

        <div class="mt-4 flex items-center gap-4">
            <div class="h-2 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-indigo-500" style="width: {{ $percent }}%"></div>
            </div>
            <span class="text-sm text-gray-600 dark:text-gray-300" data-testid="course-percent">{{ $percent }} %</span>
            @if ($next)
                <a href="{{ route('lessons.show', [$course, $next]) }}" wire:navigate class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                    {{ $percent === 0 ? 'Commencer' : 'Continuer' }} →
                </a>
            @endif
        </div>
    </div>

    @foreach ($chapters as $index => $row)
        <section wire:key="chapter-{{ $row['chapter']->id }}" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-900/5 dark:bg-gray-800 dark:ring-white/10">
            <h2 class="font-semibold text-gray-900 dark:text-white">Chapitre {{ $loop->iteration }} · {{ $row['chapter']->title }}</h2>
            @if ($row['chapter']->summary)
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $row['chapter']->summary }}</p>
            @endif
            <ol class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($row['lessons'] as $lesson)
                    <li>
                        <a href="{{ route('lessons.show', [$course, $lesson]) }}" wire:navigate class="flex items-center justify-between gap-3 py-2.5 text-sm hover:text-indigo-600 dark:hover:text-indigo-400">
                            <span class="text-gray-800 dark:text-gray-100">{{ $completed->has($lesson->id) ? '✅' : '○' }} {{ $lesson->title }}</span>
                            <span class="shrink-0 text-xs text-gray-500">{{ $lesson->estimated_minutes ? $lesson->estimated_minutes.' min · ' : '' }}+{{ $lesson->xp_reward }} XP</span>
                        </a>
                    </li>
                @endforeach
            </ol>
            @if ($row['chapter']->schema_diagram)
                <details class="mt-3 text-sm">
                    <summary class="cursor-pointer text-indigo-600 dark:text-indigo-400">Schéma relationnel du chapitre</summary>
                    <div x-data="mermaidDiagram(@js($row['chapter']->schema_diagram))" class="mt-3 overflow-x-auto">
                        <div x-ref="target"></div>
                        <p x-show="error" x-text="error" class="text-rose-600"></p>
                    </div>
                </details>
            @endif
        </section>
    @endforeach
</div>
