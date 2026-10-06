<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            // Jeu de données utilisé par les blocs ```sql runnable de la leçon.
            $table->foreignId('dataset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->longText('content_markdown');
            $table->longText('content_html')->nullable();            // rendu mis en cache
            $table->longText('schema_diagram')->nullable();
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->unsignedSmallInteger('xp_reward')->default(10);
            $table->unsignedInteger('position')->default(0);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['chapter_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
