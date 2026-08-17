<?php

namespace Tests\Unit;

use App\Services\CsvReaderService;
use PHPUnit\Framework\TestCase;

class CsvReaderServiceTest extends TestCase
{
    private CsvReaderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CsvReaderService;
    }

    public function test_reads_strict_csv(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($f, "nom;annee\nABC Ville;2020\nABC Mer;2021\n");
        $rows = $this->service->read($f);
        $this->assertCount(2, $rows);
        $this->assertSame(['nom' => 'ABC Ville', 'annee' => '2020'], $rows[0]);
        unlink($f);
    }

    public function test_reads_bom_csv(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($f, "\xEF\xBB\xBFnom;annee\nABC;2020\n");
        $rows = $this->service->read($f);
        $this->assertSame('ABC', $rows[0]['nom']);
        unlink($f);
    }

    public function test_tolerant_fallback_on_malformed_quotes(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        // Ligne avec guillemet imbriqué qui fait échouer league/csv.
        file_put_contents($f, "nom;structure\nABC;\"PETR '\"Pays\"'\"\nABC2;Mairie\n");
        $rows = $this->service->read($f);
        $this->assertCount(2, $rows);
        $this->assertSame('ABC', $rows[0]['nom']);
        unlink($f);
    }

    public function test_tolerant_skips_bad_column_count(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($f, "a;b\n1;2\n1;2;3\n4;5\n");
        // forceTolerant = true exerce directement le parseur de repli.
        $rows = $this->service->read($f, ';', true);
        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0]['a']);
        $this->assertSame('4', $rows[1]['a']);
        unlink($f);
    }

    public function test_skips_blank_lines(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($f, "a;b\n\n1;2\n\n\n");
        $rows = $this->service->read($f);
        $this->assertCount(1, $rows);
        unlink($f);
    }
}
