<?php

namespace App\Enums;

/**
 * Rôles applicatifs (RBAC light).
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Trainer = 'trainer';
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrateur',
            self::Trainer => 'Formateur / Contributeur',
            self::Student => 'Étudiant',
        };
    }

    /** Peut créer / éditer exercices et jeux de données. */
    public function canAuthorContent(): bool
    {
        return $this !== self::Student;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
