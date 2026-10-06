<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chaque requête soumise (ou réponse de QCM) et le verdict du moteur d'évaluation.
     */
    public function up(): void
    {
        Schema::create('user_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sql_dialect_id')->nullable()->constrained()->nullOnDelete();
            // Contexte optionnel : ChallengeParticipation ou CertificationAttempt.
            $table->nullableMorphs('context');
            $table->longText('query_sql')->nullable();
            $table->json('selected_choice_ids')->nullable();          // QCM
            $table->string('status', 20);                             // App\Enums\SubmissionStatus
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('score')->default(0);         // 0..100
            $table->unsignedInteger('execution_ms')->nullable();
            $table->unsignedInteger('rows_returned')->nullable();
            $table->json('result_preview')->nullable();               // {columns, rows (N premières)}
            $table->json('feedback')->nullable();                     // diff colonnes / lignes manquantes / en trop
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('hints_used')->default(0);
            $table->unsignedSmallInteger('xp_awarded')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'exercise_id']);
            $table->index(['exercise_id', 'is_correct']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_submissions');
    }
};
