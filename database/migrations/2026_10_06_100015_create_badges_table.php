<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('icon', 80)->nullable();
            $table->string('tier', 20)->default('bronze');           // App\Enums\BadgeTier
            $table->string('category', 50)->nullable();
            // Règle évaluée par App\Services\Gamification\BadgeEvaluator, ex :
            // {"type":"skill_exercises_solved","skill":"joins","count":25}
            $table->json('criteria');
            $table->unsignedSmallInteger('xp_bonus')->default(0);
            $table->boolean('is_secret')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('badge_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('badge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('awarded_at')->useCurrent();
            $table->json('context')->nullable();

            $table->unique(['badge_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('badge_user');
        Schema::dropIfExists('badges');
    }
};
