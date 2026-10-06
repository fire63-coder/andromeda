<?php

namespace App\Enums;

/**
 * Verdict d'une soumission.
 */
enum SubmissionStatus: string
{
    case Pending = 'pending';
    case Correct = 'correct';
    case Wrong = 'wrong';
    case Error = 'error';
    case Timeout = 'timeout';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cours',
            self::Correct => 'Correct',
            self::Wrong => 'Résultat incorrect',
            self::Error => 'Erreur SQL',
            self::Timeout => 'Délai dépassé',
            self::Rejected => 'Requête refusée',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
