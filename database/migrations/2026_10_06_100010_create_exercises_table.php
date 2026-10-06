<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete(); // NULL = exercice autonome (arène, certif)
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            $table->foreignId('sql_dialect_id')->nullable()->constrained()->nullOnDelete(); // NULL = ANSI
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 30);                               // App\Enums\ExerciseType
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('statement');                            // énoncé Markdown
            $table->text('starter_sql')->nullable();                  // squelette, ou requête boguée (bug_fix)
            $table->longText('solution_sql')->nullable();             // requête de référence (jamais exposée au client)
            $table->json('expected_result')->nullable();              // snapshot {columns, rows, hash} pré-calculé
            $table->string('validation_strategy', 30)->default('result_set'); // App\Enums\ValidationStrategy
            $table->json('validation_options')->nullable();           // ordered, ignore_column_names, float_tolerance, allowed_statements...
            $table->json('hints')->nullable();                        // [{text, xp_penalty}]
            $table->unsignedTinyInteger('difficulty')->default(1);    // 1..5 au sein du niveau
            $table->unsignedSmallInteger('time_limit_seconds')->nullable(); // défis chronométrés
            $table->unsignedInteger('max_execution_ms')->default(3000);
            $table->unsignedSmallInteger('xp_reward')->default(20);
            $table->unsignedInteger('position')->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'type']);
            $table->index(['level_id', 'status']);
        });

        // Choix des QCM.
        Schema::create('exercise_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_correct')->default(false);
            $table->text('explanation')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Un exercice peut être validé sur plusieurs jeux : le jeu "primary" visible par l'élève
        // et des jeux "hidden_test" qui empêchent de coder le résultat en dur.
        Schema::create('dataset_exercise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->default('primary');          // App\Enums\DatasetRole
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['dataset_id', 'exercise_id']);
        });

        Schema::create('exercise_skill', function (Blueprint $table) {
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->primary(['exercise_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_skill');
        Schema::dropIfExists('dataset_exercise');
        Schema::dropIfExists('exercise_choices');
        Schema::dropIfExists('exercises');
    }
};
