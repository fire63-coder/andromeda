<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grand livre de l'XP : users.xp est un cache de SUM(amount).
     * Permet les classements hebdo/mensuels et l'audit anti-triche.
     */
    public function up(): void
    {
        Schema::create('xp_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');                                // négatif possible (correction, indice)
            $table->string('reason', 50);                             // exercise_solved, lesson_completed, badge, streak...
            $table->nullableMorphs('source');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xp_transactions');
    }
};
