<?php

namespace App\Enums;

/**
 * Stratégies d'évaluation d'une requête élève.
 */
enum ValidationStrategy: string
{
    case ResultSet = 'result_set';
    case OrderedResultSet = 'ordered_result_set';
    case StateCheck = 'state_check';
    case Choices = 'choices';
    case QueryPlan = 'query_plan';

    public function label(): string
    {
        return match ($this) {
            self::ResultSet => 'Résultat identique (ordre ignoré)',
            self::OrderedResultSet => 'Résultat identique (ordre compris)',
            self::StateCheck => 'État des tables après exécution (DML/DDL)',
            self::Choices => 'Réponses de QCM',
            self::QueryPlan => 'Plan d\'exécution (recherche par index)',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
