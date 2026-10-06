<?php

namespace Tests\Unit\Datasets;

use App\Services\Datasets\IdentifierSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IdentifierSanitizerTest extends TestCase
{
    #[Test]
    public function names_become_portable_snake_case_identifiers(): void
    {
        $sanitizer = new IdentifierSanitizer;

        $this->assertSame(
            ['customer_id', 'date_de_commande', 'prix_eur', 'col4_2024', 'order_value', 'name', 'name_2'],
            $sanitizer->sanitizeAll(['CustomerID', 'Date de commande', 'Prix (€)', '2024', 'order', 'name', 'Name']),
        );
        $this->assertContains('« order » est un mot réservé SQL : renommé en « order_value ».', $sanitizer->warnings);
        $this->assertContains('Nom en double « Name » : renommé en « name_2 ».', $sanitizer->warnings);
    }

    #[Test]
    public function unchanged_lowercase_names_produce_no_warning(): void
    {
        $sanitizer = new IdentifierSanitizer;
        $sanitizer->sanitizeAll(['id', 'Email']);

        $this->assertSame([], $sanitizer->warnings);
    }
}
