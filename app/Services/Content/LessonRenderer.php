<?php

namespace App\Services\Content;

use Illuminate\Support\Str;

/**
 * Découpe le Markdown d'une leçon en segments affichables :
 * - html    : Markdown rendu (HTML brut retiré, liens dangereux refusés) ;
 * - sql     : bloc ```sql runnable … ``` → éditeur exécutable contre le jeu de la leçon ;
 * - mermaid : bloc ```mermaid … ``` → diagramme (schéma relationnel, etc.).
 * Les autres blocs de code (y compris ```sql sans « runnable ») restent dans le Markdown.
 */
class LessonRenderer
{
    /**
     * Garde-fous des exemples exécutables : modifications, structure et code stocké permis
     * (tout est annulé après exécution), pour illustrer les chapitres avancés.
     */
    public const SNIPPET_GUARD = ['allowed_statements' => ['select', 'dml', 'ddl', 'routine'], 'max_statements' => 10];

    private const FENCE = '/^```[ \t]*(sql[ \t]+runnable|mermaid)[^\n]*\n(.*?)^```[ \t]*$/ms';

    /**
     * @return list<array{type: string, html?: string, sql?: string, source?: string, index?: int}>
     */
    public function segments(string $markdown): array
    {
        $segments = [];
        $offset = 0;
        $snippet = 0;

        preg_match_all(self::FENCE, $markdown, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            [$whole, $start] = $match[0];
            $this->pushHtml($segments, substr($markdown, $offset, $start - $offset));

            $body = rtrim($match[2][0], "\n");
            $segments[] = str_starts_with($match[1][0], 'sql')
                ? ['type' => 'sql', 'sql' => $body, 'index' => $snippet++]
                : ['type' => 'mermaid', 'source' => $body];

            $offset = $start + strlen($whole);
        }

        $this->pushHtml($segments, substr($markdown, $offset));

        return $segments;
    }

    /**
     * Retire un « # Titre » initial identique au titre déjà affiché par la page.
     */
    public function withoutLeadingTitle(string $markdown, string $title): string
    {
        return preg_replace('/\A\s*#[ \t]+'.preg_quote(trim($title), '/').'[ \t]*\n/u', '', $markdown, 1) ?? $markdown;
    }

    /**
     * Requêtes des blocs exécutables, indexées comme dans segments().
     *
     * @return list<string>
     */
    public function snippets(string $markdown): array
    {
        return array_values(array_map(
            fn (array $segment) => $segment['sql'],
            array_filter($this->segments($markdown), fn (array $segment) => $segment['type'] === 'sql'),
        ));
    }

    public function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @param  list<array<string, mixed>>  $segments
     */
    private function pushHtml(array &$segments, string $markdown): void
    {
        if (trim($markdown) !== '') {
            $segments[] = ['type' => 'html', 'html' => $this->html($markdown)];
        }
    }
}
