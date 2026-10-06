<?php

namespace Database\Seeders;

use App\Models\Skill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        $skills = [
            ['select', 'Projection & filtrage', 'dql'],
            ['sorting', 'Tri & pagination', 'dql'],
            ['functions', 'Fonctions scalaires', 'dql'],
            ['joins', 'Jointures', 'dql'],
            ['aggregation', 'Agrégation (GROUP BY / HAVING)', 'dql'],
            ['subqueries', 'Sous-requêtes', 'dql'],
            ['set-operations', 'Opérateurs ensemblistes', 'dql'],
            ['window-functions', 'Fonctions de fenêtrage', 'dql'],
            ['cte', 'CTE & requêtes récursives', 'dql'],
            ['dml', 'INSERT / UPDATE / DELETE / MERGE', 'dml'],
            ['ddl', 'Création de schéma & contraintes', 'ddl'],
            ['views', 'Vues', 'ddl'],
            ['indexing', 'Indexation', 'performance'],
            ['query-tuning', 'Optimisation & plans d\'exécution', 'performance'],
            ['transactions', 'Transactions ACID', 'tcl'],
            ['stored-procedures', 'Procédures & fonctions stockées', 'procedural'],
            ['triggers', 'Déclencheurs', 'procedural'],
        ];

        foreach ($skills as [$slug, $name, $category]) {
            Skill::updateOrCreate(['slug' => $slug], ['name' => $name, 'category' => $category]);
        }
    }
}
