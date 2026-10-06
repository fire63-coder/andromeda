<?php

namespace App\Http\Controllers;

use App\Enums\ProgressStatus;
use App\Models\Exercise;
use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExerciseIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $solved = $request->user()->progress()
            ->where('progressable_type', (new Exercise)->getMorphClass())
            ->where('status', ProgressStatus::Completed)
            ->pluck('progressable_id')
            ->flip();

        $levels = Level::query()
            ->orderBy('position')
            ->with(['exercises' => fn ($query) => $query->published()->with('skills')->orderBy('difficulty')->orderBy('position')])
            ->get()
            ->filter(fn (Level $level) => $level->exercises->isNotEmpty());

        return view('exercises.index', ['levels' => $levels, 'solved' => $solved]);
    }
}
