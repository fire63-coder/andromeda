<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jeux de données importés (dump SQL, CSV, JSON) et leurs "builds" par dialecte.
     */
    public function up(): void
    {
        Schema::create('datasets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('domain', 50)->nullable();                 // e-commerce, RH, banque, logistique...
            $table->string('source_format', 20);                      // App\Enums\DatasetFormat
            $table->foreignId('source_dialect_id')->nullable()->constrained('sql_dialects')->nullOnDelete();
            $table->longText('schema_diagram')->nullable();          // Mermaid erDiagram généré à l'import
            $table->json('tables_meta')->nullable();                 // [{name, columns:[{name,type,pk,fk}], rows}]
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->string('status', 20)->default('pending');        // App\Enums\DatasetStatus
            $table->boolean('is_public')->default(true);             // visible dans le bac à sable libre
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // DDL + données traduits pour chaque moteur cible : c'est ce qui est rejoué dans la sandbox.
        Schema::create('dataset_builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sql_dialect_id')->constrained()->cascadeOnDelete();
            $table->longText('schema_sql');
            $table->longText('seed_sql')->nullable();
            $table->string('seed_path')->nullable();                 // gros volumes : fichier sur disque
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'sql_dialect_id']);
        });

        // Historique / suivi des imports (traités en file d'attente).
        Schema::create('dataset_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dataset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('format', 20);
            $table->string('original_filename');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->json('options')->nullable();                     // délimiteur CSV, en-têtes, mapping table/colonnes
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('rows_imported')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_imports');
        Schema::dropIfExists('dataset_builds');
        Schema::dropIfExists('datasets');
    }
};
