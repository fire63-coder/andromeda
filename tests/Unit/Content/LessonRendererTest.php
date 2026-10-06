<?php

namespace Tests\Unit\Content;

use App\Services\Content\LessonRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LessonRendererTest extends TestCase
{
    #[Test]
    public function it_splits_markdown_runnable_sql_and_mermaid_blocks(): void
    {
        $segments = (new LessonRenderer)->segments(<<<'MD'
            Intro **gras**

            ```sql runnable
            SELECT 1;
            ```

            ```sql
            SELECT 'statique';
            ```

            ```mermaid
            erDiagram
              a ||--o{ b : x
            ```

            ```sql runnable
            SELECT 2;
            ```
            MD);

        $this->assertSame(['html', 'sql', 'html', 'mermaid', 'sql'], array_column($segments, 'type'));
        $this->assertSame('SELECT 1;', $segments[1]['sql']);
        $this->assertSame([0, 1], [$segments[1]['index'], $segments[4]['index']]);
        $this->assertStringContainsString('<code class="language-sql">', $segments[2]['html']);
    }

    #[Test]
    public function raw_html_and_unsafe_links_are_neutralised(): void
    {
        $html = (new LessonRenderer)->html("<script>alert(1)</script>\n\n[clic](javascript:alert(1))");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function a_duplicate_leading_title_is_removed(): void
    {
        $renderer = new LessonRenderer;

        $this->assertSame('Texte', trim($renderer->withoutLeadingTitle("# Filtrer et trier\n\nTexte", 'Filtrer et trier')));
        $this->assertStringStartsWith('# Autre', $renderer->withoutLeadingTitle("# Autre\n\nTexte", 'Filtrer et trier'));
    }
}
