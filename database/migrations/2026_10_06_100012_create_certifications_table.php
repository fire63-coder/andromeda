<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->foreignId('sql_dialect_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('passing_score')->default(70);  // en %
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('exercises_count')->default(20); // tirés au sort dans le pool
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->unsignedSmallInteger('cooldown_hours')->default(24);
            $table->unsignedSmallInteger('xp_reward')->default(500);
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });

        // Pool d'exercices dans lequel chaque tentative est tirée.
        Schema::create('certification_exercise', function (Blueprint $table) {
            $table->foreignId('certification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->primary(['certification_id', 'exercise_id']);
        });

        Schema::create('certification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress');    // App\Enums\AttemptStatus
            $table->unsignedTinyInteger('score')->nullable();
            $table->json('exercise_ids');                            // sujet figé au démarrage
            $table->timestamp('started_at');
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('certificate_code', 40)->nullable()->unique(); // vérifiable publiquement
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'certification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certification_attempts');
        Schema::dropIfExists('certification_exercise');
        Schema::dropIfExists('certifications');
    }
};
