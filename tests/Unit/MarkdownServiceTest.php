<?php

namespace Tests\Unit;

use App\Services\MarkdownService;
use Tests\TestCase;

class MarkdownServiceTest extends TestCase
{
    private MarkdownService $markdown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->markdown = new MarkdownService;
    }

    public function test_to_html_rend_la_mise_en_forme(): void
    {
        $html = $this->markdown->toHtml("## Un titre\n\nUn **gras**, un *italique* et un [lien](https://exemple.fr).");

        $this->assertStringContainsString('<h2>Un titre</h2>', $html);
        $this->assertStringContainsString('<strong>gras</strong>', $html);
        $this->assertStringContainsString('<em>italique</em>', $html);
        $this->assertStringContainsString('href="https://exemple.fr"', $html);
    }

    public function test_to_html_rend_les_images(): void
    {
        $html = $this->markdown->toHtml('![Vue de la carte](https://exemple.fr/carte.png)');

        $this->assertStringContainsString('<img src="https://exemple.fr/carte.png" alt="Vue de la carte"', $html);
    }

    public function test_to_html_echappe_le_html_brut(): void
    {
        $html = $this->markdown->toHtml('Texte <script>alert(1)</script> et <img src=x onerror=alert(1)>.');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img ', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        // Le HTML brut est échappé : visible comme du texte, jamais interprété.
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_to_html_bloque_les_liens_dangereux(): void
    {
        $html = $this->markdown->toHtml('[Cliquez ici](javascript:alert(1))');

        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_to_text_depouille_la_syntaxe_markdown(): void
    {
        $texte = "## Un titre\n\nUn **gras** et un [lien](https://exemple.fr).\n\n- premier point\n- second point\n\n> Une citation.";
        $attendu = 'Un titre Un gras et un lien. premier point second point Une citation.';

        $this->assertSame($attendu, $this->markdown->toText($texte));
    }

    public function test_to_text_tronque_a_la_frontiere_de_mot(): void
    {
        $texte = str_repeat('mot ', 100);

        $extrait = $this->markdown->toText($texte, 30);

        $this->assertLessThanOrEqual(31, mb_strlen($extrait));
        $this->assertStringEndsWith('…', $extrait);
        $this->assertStringEndsNotWith(' …', $extrait);
    }

    public function test_to_text_gere_les_images_et_le_texte_vide(): void
    {
        $this->assertSame('Logo', $this->markdown->toText('![Logo](https://exemple.fr/logo.png)'));
        $this->assertSame('', $this->markdown->toText(null));
        $this->assertSame('', $this->markdown->toText('   '));
    }
}
