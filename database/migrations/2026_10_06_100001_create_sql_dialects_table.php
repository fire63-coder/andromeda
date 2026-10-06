<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moteurs / dialectes SQL enseignés (SQLite, MySQL, PostgreSQL, T-SQL, PL/SQL...).
     */
    public function up(): void
    {
        Schema::create('sql_dialects', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();              // sqlite, mysql, mariadb, pgsql, sqlsrv, oracle
            $table->string('name');                            // "PostgreSQL"
            $table->string('version', 20)->nullable();         // "17", "8.4", "2022", "23ai"
            $table->string('driver', 20)->nullable();          // driver PDO / Laravel utilisé par la sandbox
            $table->string('editor_mode', 30)->default('sql'); // mode de coloration CodeMirror
            $table->string('color', 20)->nullable();
            $table->boolean('is_sandbox_enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sql_dialects');
    }
};
