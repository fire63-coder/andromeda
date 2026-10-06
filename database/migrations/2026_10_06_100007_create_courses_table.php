<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cours -> Chapitres -> Leçons.
     */
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained()->restrictOnDelete();
            // NULL = cours ANSI / multi-dialectes ; sinon cours spécifique (ex : "PL/SQL avancé").
            $table->foreignId('sql_dialect_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('description')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('status', 20)->default('draft'); // App\Enums\ContentStatus
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'level_id', 'position']);
        });

        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();
            $table->longText('schema_diagram')->nullable(); // diagramme relationnel (Mermaid erDiagram)
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['course_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
        Schema::dropIfExists('courses');
    }
};
