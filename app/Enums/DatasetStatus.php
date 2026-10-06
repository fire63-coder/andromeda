<?php

namespace App\Enums;

/**
 * État d'un jeu de données, d'un build ou d'un import.
 */
enum DatasetStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Processing => 'En traitement',
            self::Ready => 'Prêt',
            self::Failed => 'Échec',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
