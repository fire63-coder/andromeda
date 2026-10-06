<?php

use App\Http\Controllers\ExerciseIndexController;
use App\Livewire\Admin\Datasets\DatasetImportWizard;
use App\Livewire\Admin\Datasets\DatasetIndex;
use App\Livewire\Admin\Datasets\DatasetShow;
use App\Livewire\Exercises\ExercisePlayer;
use App\Livewire\Gamification\Leaderboard;
use App\Livewire\Learn\Dashboard;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/classement', Leaderboard::class)->name('leaderboard');

    Route::get('/exercices', ExerciseIndexController::class)->name('exercises.index');
    Route::get('/exercices/{exercise:slug}', ExercisePlayer::class)->name('exercises.show');

    // Back-office : administrateurs et formateurs.
    Route::middleware('role:admin,trainer')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/datasets', DatasetIndex::class)->name('datasets.index');
        Route::get('/datasets/importer', DatasetImportWizard::class)->name('datasets.import');
        Route::get('/datasets/{dataset:slug}', DatasetShow::class)->name('datasets.show');
    });
});
