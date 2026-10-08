<?php

use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ExerciseIndexController;
use App\Http\Controllers\OrganizationProgressExportController;
use App\Livewire\Admin\Certifications\CertificationEditor;
use App\Livewire\Admin\Certifications\CertificationIndex;
use App\Livewire\Admin\Challenges\ChallengeEditor;
use App\Livewire\Admin\Challenges\ChallengeIndex;
use App\Livewire\Admin\Courses\CourseEditor;
use App\Livewire\Admin\Courses\CourseIndex;
use App\Livewire\Admin\Courses\LessonEditor;
use App\Livewire\Admin\Datasets\DatasetImportWizard;
use App\Livewire\Admin\Datasets\DatasetIndex;
use App\Livewire\Admin\Datasets\DatasetShow;
use App\Livewire\Admin\Exercises\ExerciseEditor;
use App\Livewire\Admin\Exercises\ExerciseIndex;
use App\Livewire\Admin\Organizations\MemberProgress;
use App\Livewire\Admin\Organizations\OrganizationIndex;
use App\Livewire\Admin\Organizations\OrganizationProgress;
use App\Livewire\Admin\Organizations\OrganizationShow;
use App\Livewire\Admin\Users\UserIndex;
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
    // Liaison limitée au cours : deux cours peuvent avoir une leçon de même identifiant.
    Route::get('/cours/{course:slug}/{lesson:slug}', LessonViewer::class)->name('lessons.show')->scopeBindings();

    Route::get('/exercices', ExerciseIndexController::class)->name('exercises.index');
    Route::get('/exercices/{exercise:slug}', ExercisePlayer::class)->name('exercises.show');

    Route::get('/arene', ArenaIndex::class)->name('arena.index');
    Route::get('/arene/{challenge:slug}', ChallengeRunner::class)->name('arena.show');

    Route::get('/certifications', CertificationList::class)->name('certifications.index');
    Route::get('/certifications/tentatives/{attempt}', CertificationRunner::class)->name('certifications.attempt');

    // Back-office : administrateurs et formateurs.
    Route::middleware('role:admin,trainer')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/cours', CourseIndex::class)->name('courses.index');
        Route::get('/cours/creer', CourseEditor::class)->name('courses.create');
        Route::get('/cours/{course:slug}', CourseEditor::class)->name('courses.edit');
        Route::get('/lecons/{lesson}', LessonEditor::class)->name('lessons.edit');

        Route::get('/exercices', ExerciseIndex::class)->name('exercises.index');
        Route::get('/exercices/creer', ExerciseEditor::class)->name('exercises.create');
        Route::get('/exercices/{exercise}', ExerciseEditor::class)->name('exercises.edit');

        Route::get('/datasets', DatasetIndex::class)->name('datasets.index');
        Route::get('/datasets/importer', DatasetImportWizard::class)->name('datasets.import');
        Route::get('/datasets/{dataset:slug}', DatasetShow::class)->name('datasets.show');

        // Réservé aux administrateurs (contrôlé par les policies).
        Route::get('/certifications', CertificationIndex::class)->name('certifications.index');
        Route::get('/certifications/creer', CertificationEditor::class)->name('certifications.create');
        Route::get('/certifications/{certification:slug}', CertificationEditor::class)->name('certifications.edit');

        Route::get('/defis', ChallengeIndex::class)->name('challenges.index');
        Route::get('/defis/creer', ChallengeEditor::class)->name('challenges.create');
        Route::get('/defis/{challenge:slug}', ChallengeEditor::class)->name('challenges.edit');

        Route::get('/utilisateurs', UserIndex::class)->name('users.index');
    });

    // Organisations : administrateurs et responsables d'organisation (quel que soit leur rôle).
    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/organisations', OrganizationIndex::class)->name('organizations.index');
        Route::get('/organisations/{organization:slug}', OrganizationShow::class)->name('organizations.show');
        Route::get('/organisations/{organization:slug}/suivi', OrganizationProgress::class)->name('organizations.progress');
        Route::get('/organisations/{organization:slug}/suivi/export', OrganizationProgressExportController::class)->name('organizations.progress.export');
        Route::get('/organisations/{organization:slug}/suivi/{member}', MemberProgress::class)->name('organizations.member')->whereNumber('member');
    });
});
