<?php

namespace Tests\Unit;

use App\Services\ProjectIdService;
use PHPUnit\Framework\TestCase;

class ProjectIdServiceTest extends TestCase
{
    private ProjectIdService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProjectIdService;
    }

    public function test_slug_transliterates_and_dashes(): void
    {
        $this->assertSame('abc-bretagne', $this->service->slug('ABC Bretagne'));
        $this->assertSame('abc-ain-cerdon', $this->service->slug('ABC Ain-Cerdon'));
    }

    public function test_slug_handles_diacritics_via_nfd(): void
    {
        $this->assertSame('loire-atlantique', $this->service->slug('Loire-Atlantique'));
        $this->assertSame('cote-d-or', $this->service->slug('Côte d\'Or'));
        $this->assertSame('pyrenees-orientales', $this->service->slug('Pyrénées-Orientales'));
        $this->assertSame('drome', $this->service->slug('Drôme'));
    }

    public function test_slug_collapses_non_alnum_runs(): void
    {
        $this->assertSame('a-b-c', $this->service->slug('a  b   c'));
        $this->assertSame('a-b', $this->service->slug("a\tb"));
        $this->assertSame('a-b', $this->service->slug('a...b'));
    }

    public function test_slug_trims_edges(): void
    {
        $this->assertSame('abc', $this->service->slug('---ABC---'));
        $this->assertSame('x', $this->service->slug('..x..'));
    }

    public function test_slug_lowercases(): void
    {
        $this->assertSame('observatoire-des-abc', $this->service->slug('Observatoire des ABC'));
    }

    public function test_slug_handles_non_breaking_chars_and_digits(): void
    {
        $this->assertSame('communaute-de-communes-rives-de-l-ain-pays-du-cerdon-2025', $this->service->slug("COMMUNAUTE DE COMMUNES RIVES DE L'AIN PAYS DU CERDON 2025"));
    }

    public function test_projet_id_is_slug_of_pipe_joined_fields(): void
    {
        $id = $this->service->projetId('ABC Ain-Cerdon', 'COMMUNAUTE DE COMMUNES RIVES DE L\'AIN PAYS DU CERDON', 2025);
        $this->assertSame('abc-ain-cerdon-communaute-de-communes-rives-de-l-ain-pays-du-cerdon-2025', $id);
    }

    public function test_projet_id_handles_missing_parts(): void
    {
        $this->assertSame('abc-ville', $this->service->projetId('ABC Ville'));
        $this->assertSame('abc-ville-2020', $this->service->projetId('ABC Ville', null, 2020));
    }
}
