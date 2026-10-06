<?php

namespace Database\Seeders;

use App\Models\Level;
use Illuminate\Database\Seeder;

class LevelSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            [1, 'debutant', 'Débutant', 'SELECT, WHERE, ORDER BY, LIMIT, fonctions de base.', '#22c55e', 0],
            [2, 'intermediaire', 'Intermédiaire', 'Jointures, GROUP BY, HAVING, sous-requêtes simples.', '#3b82f6', 500],
            [3, 'avance', 'Avancé', 'Fonctions de fenêtrage, CTE, vues, indexation et performances.', '#a855f7', 2000],
            [4, 'expert', 'Expert & Certifié', 'Procédures stockées, fonctions, triggers, transactions ACID, optimisation.', '#f59e0b', 5000],
        ];

        foreach ($levels as [$position, $slug, $name, $description, $color, $minXp]) {
            Level::updateOrCreate(['slug' => $slug], [
                'position' => $position,
                'name' => $name,
                'description' => $description,
                'color' => $color,
                'min_xp_to_unlock' => $minXp,
            ]);
        }
    }
}
