<?php

namespace App\Enums;

/**
 * Cycle de vie éditorial des cours, leçons, exercices, certifications et défis.
 */
enum ContentStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Published = 'published';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Brouillon',
            self::InReview => 'En relecture',
            self::Published => 'Publié',
            self::Archived => 'Archivé',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
