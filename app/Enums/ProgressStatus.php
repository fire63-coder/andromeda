<?php

namespace App\Enums;

/**
 * Avancement d'un utilisateur sur un cours, une leçon ou un exercice.
 */
enum ProgressStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Non commencé',
            self::InProgress => 'En cours',
            self::Completed => 'Terminé',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
