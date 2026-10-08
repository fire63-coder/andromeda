<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devoirs : liste d'exercices assignée aux membres d'une organisation, avec une échéance.
     */
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('published_at')->nullable();             // NULL = brouillon, invisible des élèves
            $table->timestamps();

            $table->index(['organization_id', 'published_at']);
        });

        Schema::create('assignment_exercise', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['assignment_id', 'exercise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_exercise');
        Schema::dropIfExists('assignments');
    }
};
