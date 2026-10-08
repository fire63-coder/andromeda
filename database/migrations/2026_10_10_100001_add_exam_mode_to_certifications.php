<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certifications', function (Blueprint $table) {
            // Mode examen : plein écran exigé, copier-coller bloqué, incidents journalisés.
            $table->boolean('exam_mode')->default(false)->after('xp_reward');
            // Au-delà de ce nombre d'incidents (sortie du plein écran, changement d'onglet), l'épreuve est close.
            $table->unsignedTinyInteger('max_incidents')->nullable()->after('exam_mode');
        });

        Schema::table('certification_attempts', function (Blueprint $table) {
            $table->json('incidents')->nullable()->after('exercise_ids');
            $table->unsignedSmallInteger('incidents_count')->default(0)->after('incidents');
            $table->string('closed_reason', 20)->nullable()->after('completed_at'); // submitted | timeout | incidents
        });
    }

    public function down(): void
    {
        Schema::table('certification_attempts', function (Blueprint $table) {
            $table->dropColumn(['incidents', 'incidents_count', 'closed_reason']);
        });

        Schema::table('certifications', function (Blueprint $table) {
            $table->dropColumn(['exam_mode', 'max_incidents']);
        });
    }
};
