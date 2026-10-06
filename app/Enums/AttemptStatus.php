<?php

namespace App\Enums;

/**
 * État d'une tentative de certification.
 */
enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Passed = 'passed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => 'En cours',
            self::Passed => 'Réussie',
            self::Failed => 'Échouée',
            self::Expired => 'Expirée',
            self::Abandoned => 'Abandonnée',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
