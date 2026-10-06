<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compétences SQL ciblées (jointures, agrégation, fenêtrage, triggers...).
     * Sert à la cartographie des acquis et aux critères des badges ("Maître des Jointures").
     */
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name');
            $table->string('category', 50)->nullable()->index(); // dql, dml, ddl, tcl, procedural, performance
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
