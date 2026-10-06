<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Référentiels (idempotents : relançables en production).
        $this->call([
            SqlDialectSeeder::class,
            LevelSeeder::class,
            RankSeeder::class,
            SkillSeeder::class,
            BadgeSeeder::class,
        ]);

        if (app()->environment('local', 'testing')) {
            $novice = Rank::where('slug', 'novice')->first();

            $admin = User::factory()->create([
                'name' => 'Admin',
                'email' => 'admin@example.com',
            ]);
            $admin->forceFill(['role' => UserRole::Admin, 'rank_id' => $novice?->id])->save();

            User::factory(10)->create()->each(
                fn (User $user) => $user->forceFill(['rank_id' => $novice?->id])->save()
            );

            $this->call(DemoContentSeeder::class);
        }
    }
}
