<?php

namespace App\Enums;

/**
 * Rôle d'un jeu de données pour un exercice.
 */
enum DatasetRole: string
{
    case Primary = 'primary';
    case HiddenTest = 'hidden_test';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Jeu visible',
            self::HiddenTest => 'Jeu de test caché',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
