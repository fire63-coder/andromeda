<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bases / schémas éphémères provisionnés pour exécuter les requêtes des élèves.
     * Une tâche planifiée détruit les sandboxes expirées.
     */
    public function up(): void
    {
        Schema::create('sandbox_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('dataset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('sql_dialect_id')->constrained()->cascadeOnDelete();
            $table->string('resource_name');                          // fichier SQLite, schéma PG, base MySQL...
            $table->string('status', 20)->default('provisioning');   // App\Enums\SandboxStatus
            $table->unsignedInteger('queries_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'dataset_id', 'sql_dialect_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_sessions');
    }
};
