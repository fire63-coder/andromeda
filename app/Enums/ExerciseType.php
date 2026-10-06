<?php

namespace App\Enums;

/**
 * Types d'exercices.
 */
enum ExerciseType: string
{
    case QueryWrite = 'query_write';
    case BugFix = 'bug_fix';
    case MultipleChoice = 'mcq';
    case TimedChallenge = 'timed';

    public function label(): string
    {
        return match ($this) {
            self::QueryWrite => 'Requête à rédiger',
            self::BugFix => 'Correction de bug',
            self::MultipleChoice => 'QCM',
            self::TimedChallenge => 'Défi chronométré',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
