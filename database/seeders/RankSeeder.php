<?php

namespace Database\Seeders;

use App\Models\Rank;
use Illuminate\Database\Seeder;

class RankSeeder extends Seeder
{
    public function run(): void
    {
        $ranks = [
            ['novice', 'Novice', 0, '#9ca3af'],
            ['apprenti', 'Apprenti requêteur', 250, '#22c55e'],
            ['analyste', 'Analyste', 1000, '#3b82f6'],
            ['architecte', 'Architecte de données', 3000, '#a855f7'],
            ['dba', 'DBA', 7000, '#f97316'],
            ['grand-maitre', 'Grand Maître SQL', 15000, '#eab308'],
        ];

        foreach ($ranks as [$slug, $name, $minXp, $color]) {
            Rank::updateOrCreate(['slug' => $slug], ['name' => $name, 'min_xp' => $minXp, 'color' => $color]);
        }
    }
}
