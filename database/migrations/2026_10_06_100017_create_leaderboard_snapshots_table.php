<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Classements figés (calculés par une tâche planifiée) pour éviter
     * des agrégats coûteux sur xp_transactions à chaque affichage.
     */
    public function up(): void
    {
        Schema::create('leaderboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('period', 20);                             // weekly | monthly | all_time
            $table->date('period_start');
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete(); // NULL = global
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('xp');
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['period', 'period_start', 'organization_id', 'position'], 'leaderboard_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_snapshots');
    }
};
