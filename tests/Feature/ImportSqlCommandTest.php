<?php

namespace Tests\Feature;

use App\Models\InpnImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * abc:import-sql : exécution en flux d'un dump portable, pour les
 * hébergements sans phpPgAdmin. Chaînes littérales ('' échappé,
 * point-virgule dans le texte), SET ignorés si non supportés par le
 * pilote, atomicité du BEGIN/COMMIT (une erreur n'écrit rien).
 */
class ImportSqlCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sql = sys_get_temp_dir().'/import-sql-'.uniqid().'.sql';
    }

    protected function tearDown(): void
    {
        @unlink($this->sql);
        parent::tearDown();
    }

    public function test_importe_le_fichier_en_flux(): void
    {
        file_put_contents($this->sql, <<<'SQL'
            SET client_encoding = 'UTF8';
            BEGIN;
            DELETE FROM "inpn_imports";
            INSERT INTO "inpn_imports" ("source", "version_source", "nb_entites", "importe_le") VALUES
            ('znieff', 'extraction ZNIEFF INPN 2025 — texte avec ; point-virgule et l''apostrophe', 12, '2026-10-28 10:00:00');
            COMMIT;
            SQL);

        $this->artisan('abc:import-sql', ['file' => $this->sql, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(1, InpnImport::query()->count());
        $import = InpnImport::query()->first();
        $this->assertSame('znieff', $import->source);
        $this->assertSame("extraction ZNIEFF INPN 2025 — texte avec ; point-virgule et l'apostrophe", $import->version_source);
        $this->assertSame(12, $import->nb_entites);
    }

    public function test_idempotent_relance_replacement(): void
    {
        file_put_contents($this->sql, <<<'SQL'
            BEGIN;
            DELETE FROM "inpn_imports";
            INSERT INTO "inpn_imports" ("source", "version_source", "nb_entites", "importe_le") VALUES
            ('znieff', 'v1', 1, '2026-10-28 10:00:00');
            COMMIT;
            SQL);

        $this->artisan('abc:import-sql', ['file' => $this->sql, '--force' => true])->assertExitCode(0);
        $this->artisan('abc:import-sql', ['file' => $this->sql, '--force' => true])->assertExitCode(0);

        $this->assertSame(1, InpnImport::query()->count());
    }

    public function test_une_erreur_n_ecrit_rien_transaction_atomique(): void
    {
        InpnImport::updateOrCreate(
            ['source' => 'existant'],
            ['version_source' => 'present', 'nb_entites' => 1, 'importe_le' => now()],
        );

        file_put_contents($this->sql, <<<'SQL'
            BEGIN;
            DELETE FROM "inpn_imports";
            INSERT INTO "inpn_imports" ("source", "version_source", "nb_entites", "importe_le") VALUES
            ('znieff', 'v1', 1, '2026-10-28 10:00:00');
            INSERT INTO "table_inexistante" VALUES (1);
            COMMIT;
            SQL);

        $this->artisan('abc:import-sql', ['file' => $this->sql, '--force' => true])->assertExitCode(1);

        // Rien n'a été écrit : la ligne existante est toujours là
        $this->assertSame(1, InpnImport::query()->count());
        $this->assertSame('present', InpnImport::query()->first()->version_source);
    }

    public function test_fichier_absent(): void
    {
        $this->artisan('abc:import-sql', ['file' => '/tmp/inexistant-12345.sql', '--force' => true])
            ->assertExitCode(1);
    }

    public function test_decoupage_dump_volumineux(): void
    {
        // Un relevé de 250 lignes de valeurs : le flux doit découper sans
        // charger le fichier entier ni casser les chaînes.
        $lignes = [
            'BEGIN;',
            'DELETE FROM "inpn_imports";',
            'INSERT INTO "inpn_imports" ("source", "version_source", "nb_entites", "importe_le") VALUES',
        ];
        for ($i = 1; $i <= 250; $i++) {
            $lignes[] = "('src$i', 'v$i', $i, '2026-10-28 10:00:00')".($i === 250 ? ';' : ',');
        }
        $lignes[] = 'COMMIT;';
        file_put_contents($this->sql, implode("\n", $lignes));

        $this->artisan('abc:import-sql', ['file' => $this->sql, '--force' => true])->assertExitCode(0);
        $this->assertSame(250, DB::table('inpn_imports')->count());
    }
}
