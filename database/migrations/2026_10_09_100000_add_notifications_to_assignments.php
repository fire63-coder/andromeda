<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * E-mails des devoirs : envoyés une seule fois (publication, rappel la veille),
     * et désactivables par chaque utilisateur.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('published_at');
            $table->timestamp('reminded_at')->nullable()->after('notified_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('assignment_emails')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('assignments', fn (Blueprint $table) => $table->dropColumn(['notified_at', 'reminded_at']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('assignment_emails'));
    }
};
