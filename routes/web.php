<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ExerciseIndexController;
use App\Livewire\Admin\Datasets\DatasetImportWizard;
use App\Livewire\Admin\Datasets\DatasetIndex;
use App\Livewire\Admin\Datasets\DatasetShow;
use App\Livewire\Arena\ArenaIndex;
use App\Livewire\Arena\ChallengeRunner;
use App\Livewire\Certification\CertificationList;
use App\Livewire\Certification\CertificationRunner;
use App\Livewire\Exercises\ExercisePlayer;
use App\Livewire\Gamification\Leaderboard;
use App\Livewire\Learn\CourseCatalog;
use App\Livewire\Learn\CourseShow;
use App\Livewire\Learn\Dashboard;
use App\Livewire\Learn\LessonViewer;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Vérification publique d'un certificat (lien partageable).
Route::get('/certificats/{code}', CertificateController::class)->name('certificates.show');

Route::middleware([
    'auth:sanctum',
    config('jetstream.auth_session'),
    'verified',
])->group(function () {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');
    Route::get('/classement', Leaderboard::class)->name('leaderboard');

    Route::get('/cours', CourseCatalog::class)->name('courses.index');
    Route::get('/cours/{course:slug}', CourseShow::class)->name('courses.show');
    Route::get('/cours/{course:slug}/{lesson:slug}', LessonViewer::class)->name('lessons.show');

    Route::get('/exercices', ExerciseIndexController::class)->name('exercises.index');
    Route::get('/exercices/{exercise:slug}', ExercisePlayer::class)->name('exercises.show');

    Route::get('/arene', ArenaIndex::class)->name('arena.index');
    Route::get('/arene/{challenge:slug}', ChallengeRunner::class)->name('arena.show');

    Route::get('/certifications', CertificationList::class)->name('certifications.index');
    Route::get('/certifications/tentatives/{attempt}', CertificationRunner::class)->name('certifications.attempt');

    // Back-office : administrateurs et formateurs.
    Route::middleware('role:admin,trainer')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/datasets', DatasetIndex::class)->name('datasets.index');
        Route::get('/datasets/importer', DatasetImportWizard::class)->name('datasets.import');
        Route::get('/datasets/{dataset:slug}', DatasetShow::class)->name('datasets.show');
    });
});
