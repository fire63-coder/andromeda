<?php

namespace App\Enums;

/**
 * Types de défis gamifiés.
 */
enum ChallengeType: string
{
    case Daily = 'daily';
    case Arena = 'arena';
    case Timed = 'timed';
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Défi quotidien',
            self::Arena => 'Arène',
            self::Timed => 'Contre-la-montre',
            self::Event => 'Événement',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
