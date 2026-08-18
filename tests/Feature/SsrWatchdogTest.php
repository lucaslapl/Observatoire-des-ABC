<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsrWatchdogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        @unlink(storage_path('app/ssr.pid'));

        config(['inertia.ssr.node_bin' => '/chemin/inexistant/node']);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/ssr.pid'));
        @unlink(storage_path('logs/ssr.log'));

        parent::tearDown();
    }

    public function test_no_relance_quand_le_serveur_repond(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'OK', 'timestamp' => now()->timestamp], 200),
        ]);

        $this->artisan('ssr:watchdog')
            ->expectsOutput('SSR : serveur opérationnel.')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist(storage_path('app/ssr.pid'));
    }

    public function test_relance_quand_le_serveur_ne_repond_pas(): void
    {
        Http::fake([
            '*/health' => Http::response(status: 503),
        ]);

        $this->artisan('ssr:watchdog')
            ->expectsOutput('SSR : serveur injoignable, relance en cours…')
            ->assertExitCode(0);
    }

    public function test_restart_sans_pid_ne_fait_pas_defaut(): void
    {
        Http::fake([
            '*/health' => Http::response(status: 503),
        ]);

        $this->artisan('ssr:watchdog --restart')
            ->expectsOutput('SSR : aucun fichier PID, rien à arrêter.')
            ->assertExitCode(0);
    }
}
