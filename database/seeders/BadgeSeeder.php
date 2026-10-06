<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            ['premiere-requete', '🚀', 'Première requête', 'Résoudre son premier exercice.', 'bronze', 'progression', ['type' => 'exercises_solved', 'count' => 1], 10],
            ['maitre-des-jointures', '🔗', 'Maître des Jointures', 'Résoudre 25 exercices de jointures.', 'gold', 'skill', ['type' => 'skill_exercises_solved', 'skill' => 'joins', 'count' => 25], 150],
            ['chasseur-de-bugs', '🐛', 'Chasseur de Bugs', 'Corriger 15 requêtes boguées.', 'silver', 'exercise_type', ['type' => 'exercise_type_solved', 'exercise_type' => 'bug_fix', 'count' => 15], 100],
            ['fenetre-sur-cour', '🪟', 'Fenêtre sur cour', 'Résoudre 10 exercices de fonctions de fenêtrage.', 'gold', 'skill', ['type' => 'skill_exercises_solved', 'skill' => 'window-functions', 'count' => 10], 150],
            ['sans-faute', '🎯', 'Sans faute', 'Résoudre 10 exercices d\'affilée du premier coup.', 'silver', 'performance', ['type' => 'first_try_streak', 'count' => 10], 100],
            ['regulier', '🔥', 'Régularité', 'Pratiquer 7 jours consécutifs.', 'bronze', 'streak', ['type' => 'daily_streak', 'days' => 7], 50],
            ['polyglotte', '🌍', 'Polyglotte SQL', 'Résoudre un exercice dans 4 dialectes différents.', 'gold', 'dialect', ['type' => 'distinct_dialects_solved', 'count' => 4], 200],
            ['certifie-expert', '🏆', 'Expert certifié', 'Obtenir la certification de niveau Expert.', 'platinum', 'certification', ['type' => 'certification_passed', 'level' => 4], 500],
        ];

        foreach ($badges as $position => [$slug, $icon, $name, $description, $tier, $category, $criteria, $xpBonus]) {
            Badge::updateOrCreate(['slug' => $slug], [
                'name' => $name,
                'icon' => $icon,
                'description' => $description,
                'tier' => $tier,
                'category' => $category,
                'criteria' => $criteria,
                'xp_bonus' => $xpBonus,
                'position' => $position,
            ]);
        }
    }
}
