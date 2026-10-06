<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rangs de gamification atteints par paliers d'XP (Novice, Apprenti, ..., Grand Maître).
     */
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name');
            $table->unsignedInteger('min_xp')->unique();
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
    }
};
