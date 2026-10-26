<?php

namespace App\Services;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Rendu Markdown des contenus rédigés dans l'admin (actualités).
 *
 * Le HTML brut saisi dans le Markdown est échappé (`html_input: escape`)
 * et les liens dangereux (`javascript:`…) sont supprimés
 * (`allow_unsafe_links: false`) : le HTML produit peut être affiché tel quel.
 */
class MarkdownService
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TableExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(?string $markdown): string
    {
        return trim($this->converter->convert((string) $markdown)->getContent());
    }

    /**
     * Texte brut dépouillé de la syntaxe Markdown, pour les extraits
     * (listes, flux Atom, balises meta). Coupé à la frontière de mot si
     * $limit > 0.
     */
    public function toText(?string $markdown, int $limit = 0): string
    {
        $text = (string) $markdown;

        // Images et liens : on garde le texte visible (alt / libellé).
        $text = preg_replace('/!\[([^\]]*)\]\([^)]*\)/u', '$1', $text) ?? '';
        $text = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $text) ?? '';
        // Commentaires HTML, blocs de code (délimites), accolades de variables.
        $text = preg_replace('/<!--.*?-->/us', '', $text) ?? '';
        $text = preg_replace('/^```.*?$/um', '', $text) ?? '';
        // Marqueurs de ligne : titres, listes, citations, règles horizontales.
        $text = preg_replace('/^\s{0,3}(#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+|-{3,}|\*{3,})/um', '', $text) ?? '';
        // Emphase, code inline.
        $text = preg_replace('/(\*\*|__|\*|_|~~|`)/u', '', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($limit > 0 && mb_strlen($text) > $limit) {
            $coupe = mb_substr($text, 0, $limit);
            $dernierEspace = mb_strrpos($coupe, ' ');
            $text = ($dernierEspace !== false && $dernierEspace > (int) ($limit * 0.6)
                ? mb_substr($coupe, 0, $dernierEspace)
                : $coupe).'…';
        }

        return $text;
    }
}
