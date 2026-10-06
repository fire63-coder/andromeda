<?php

namespace App\Enums;

/**
 * Cycle de vie d'une base sandbox.
 */
enum SandboxStatus: string
{
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Expired = 'expired';
    case Destroyed = 'destroyed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Provisionnement',
            self::Ready => 'Prête',
            self::Expired => 'Expirée',
            self::Destroyed => 'Détruite',
            self::Failed => 'Échec',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
