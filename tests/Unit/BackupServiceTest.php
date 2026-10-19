<?php

namespace Tests\Unit;

use App\Services\BackupService;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    public function test_rotation_keeps_last_n_dumps(): void
    {
        $dir = sys_get_temp_dir().'/abc-rotate-test-'.uniqid();
        mkdir($dir);

        try {
            for ($i = 1; $i <= 20; $i++) {
                file_put_contents($dir.'/abc-2026-01-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'-04-00-00.dump', 'x');
            }

            $kept = app(BackupService::class)->rotate($dir);

            $files = glob($dir.'/*.dump');
            $this->assertSame(14, $kept);
            $this->assertCount(14, $files);
            $this->assertFileDoesNotExist($dir.'/abc-2026-01-01-04-00-00.dump');
            $this->assertFileExists($dir.'/abc-2026-01-20-04-00-00.dump');
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_upload_without_configured_command_is_a_no_op(): void
    {
        config(['abc.backup_upload_cmd' => null]);

        [$rc, $out] = app(BackupService::class)->upload('/tmp/abc.dump');

        $this->assertSame(0, $rc);
        $this->assertSame('', $out);
    }

    public function test_upload_substitutes_file_placeholder(): void
    {
        config(['abc.backup_upload_cmd' => 'echo envoi {file}']);

        [$rc, $out] = app(BackupService::class)->upload('/tmp/abc 2026.dump');

        $this->assertSame(0, $rc);
        // {file} échappé pour le shell : le chemin avec espaces
        // reste un seul argument pour la commande distante.
        $this->assertSame('envoi /tmp/abc 2026.dump', trim($out));
    }
}
