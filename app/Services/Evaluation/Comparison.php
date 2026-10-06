<?php

namespace App\Services\Evaluation;

/**
 * Verdict d'une comparaison résultat obtenu / résultat attendu.
 */
final readonly class Comparison
{
    /**
     * @param  array<string, mixed>  $details  diff affichable : expected_columns, missing_rows, extra_rows, first_difference...
     */
    public function __construct(
        public bool $matches,
        public ?string $message = null,
        public array $details = [],
    ) {}

    public static function match(): self
    {
        return new self(true);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function mismatch(string $message, array $details = []): self
    {
        return new self(false, $message, $details);
    }
}
