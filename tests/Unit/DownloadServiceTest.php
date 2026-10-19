<?php

namespace Tests\Unit;

use App\Services\DownloadService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class DownloadServiceTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDir = sys_get_temp_dir().'/abc-cache-test-'.uniqid();
        mkdir($this->cacheDir);
        config(['abc.cache_dir' => $this->cacheDir]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir.'/*') ?: []);
        @rmdir($this->cacheDir);
        parent::tearDown();
    }

    public function test_downloads_and_caches_csv(): void
    {
        Http::fake([
            'ex.fr/source.csv' => Http::response(
                "col1;col2\na;b\nc;d\ne;f\ng;h\ni;j\nk;l\nm;n\no;p\nq;r\ns;t\nu;v\nw;x\ny;z\na1;b1\nc1;d1\ne1;f1\ng1;h1\ni1;j1\nk1;l1\nm1;n1\no1;p1\nq1;r1\ns1;t1\nu1;v1\n",
                200,
                ['Content-Type' => 'text/csv'],
            ),
        ]);

        $path = app(DownloadService::class)->downloadToCache('https://ex.fr/source.csv', 'Test');

        $this->assertSame($this->cacheDir.'/source.csv', $path);
        $this->assertFileExists($path);
    }

    public function test_html_response_is_rejected_and_never_cached(): void
    {
        // Régression 2026-10 : archive.org répond 200 avec une page HTML ;
        // une version antérieure la mettait en cache pour toujours.
        Http::fake([
            'archive.org/abcexport*' => Http::response(
                '<!DOCTYPE html><html><head><title>Wayback Machine</title></head><body></body></html>',
                200,
                ['Content-Type' => 'text/html'],
            ),
        ]);

        try {
            app(DownloadService::class)->downloadToCache('https://archive.org/abcexport?page&_format=csv', 'Wayback');
            $this->fail('Une RuntimeException était attendue.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('page HTML', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->cacheDir.'/abcexport');
    }

    public function test_poisoned_cached_file_is_rejected(): void
    {
        file_put_contents($this->cacheDir.'/abcexport', str_repeat('<html><head><title>Erreur</title></head></html>', 3));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('page HTML');
        app(DownloadService::class)->downloadToCache('https://archive.org/abcexport?page&_format=csv', 'Wayback');
    }

    public function test_too_short_response_is_rejected(): void
    {
        Http::fake([
            'ex.fr/source.csv' => Http::response('abc', 200),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('trop courte');
        app(DownloadService::class)->downloadToCache('https://ex.fr/source.csv', 'Test');
    }
}
