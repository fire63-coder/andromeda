<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Défis quotidiens, arène, défis chronométrés et événements.
     */
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                               // App\Enums\ChallengeType
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete(); // défi privé
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable(); // chrono individuel une fois commencé
            $table->decimal('xp_multiplier', 4, 2)->default(1.00);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'starts_at']);
        });

        Schema::create('challenge_exercise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('points')->default(100);
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['challenge_id', 'exercise_id']);
        });

        Schema::create('challenge_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('score')->default(0);
            $table->unsignedSmallInteger('solved_count')->default(0);
            $table->unsignedBigInteger('total_time_ms')->default(0);
            $table->unsignedInteger('final_rank')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['challenge_id', 'user_id']);
            $table->index(['challenge_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('challenge_participations');
        Schema::dropIfExists('challenge_exercise');
        Schema::dropIfExists('challenges');
    }
};
