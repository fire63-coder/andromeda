<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Progression polymorphe : Course, Lesson, Exercise.
     */
    public function up(): void
    {
        Schema::create('user_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->morphs('progressable');
            $table->string('status', 20)->default('in_progress');    // App\Enums\ProgressStatus
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->unsignedInteger('attempts_count')->default(0);
            $table->unsignedTinyInteger('best_score')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'progressable_type', 'progressable_id'], 'user_progress_unique');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_progress');
    }
};
