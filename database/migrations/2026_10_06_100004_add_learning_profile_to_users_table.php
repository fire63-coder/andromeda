<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rôle (RBAC light) et profil de progression gamifiée de l'utilisateur.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('student')->index()->after('password'); // App\Enums\UserRole
            $table->unsignedInteger('xp')->default(0)->index()->after('role');
            $table->foreignId('rank_id')->nullable()->after('xp')->constrained('ranks')->nullOnDelete();
            $table->foreignId('preferred_dialect_id')->nullable()->after('rank_id')->constrained('sql_dialects')->nullOnDelete();
            $table->unsignedSmallInteger('current_streak')->default(0)->after('preferred_dialect_id');
            $table->unsignedSmallInteger('longest_streak')->default(0)->after('current_streak');
            $table->date('last_activity_on')->nullable()->after('longest_streak');
            $table->boolean('leaderboard_visible')->default(true)->after('last_activity_on');
            $table->boolean('is_active')->default(true)->after('leaderboard_visible');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_dialect_id');
            $table->dropConstrainedForeignId('rank_id');
            $table->dropIndex(['role']);
            $table->dropIndex(['xp']);
            $table->dropColumn([
                'role', 'xp', 'current_streak', 'longest_streak',
                'last_activity_on', 'leaderboard_visible', 'is_active',
            ]);
        });
    }
};
