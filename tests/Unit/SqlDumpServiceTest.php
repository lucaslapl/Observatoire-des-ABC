<?php

namespace Tests\Unit;

use App\Models\Projet;
use App\Models\ProjectAlias;
use App\Services\SqlDumpService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SqlDumpServiceTest extends TestCase
{
    use DatabaseMigrations;
    public function test_dump_writes_data_only_sql(): void
    {
        Schema::create('sql_dump_test', function ($table) {
            $table->id();
            $table->string('nom');
            $table->string('ville')->nullable();
            $table->boolean('actif')->default(false);
        });
        DB::table('sql_dump_test')->insert([
            ['id' => 1, 'nom' => "L'Allier", 'ville' => 'Moulins', 'actif' => true],
            ['id' => 2, 'nom' => 'Isère', 'ville' => null, 'actif' => false],
        ]);

        $out = sys_get_temp_dir().'/abc-sqldump-test-'.uniqid().'.sql';

        try {
            $counts = app(SqlDumpService::class)->dump($out, ['sql_dump_test']);

            $sql = file_get_contents($out);
            $this->assertSame(['sql_dump_test' => 2], $counts);
            $this->assertStringContainsString('SET client_encoding', $sql);
            $this->assertStringContainsString('BEGIN;', $sql);
            $this->assertStringContainsString('COMMIT;', $sql);
            $this->assertStringContainsString('INSERT INTO "sql_dump_test" ("id"', $sql);
            // apostrophe échappée (convention SQL), NULL préservé
            $this->assertStringContainsString("'L''Allier'", $sql);
            $this->assertStringContainsString('NULL', $sql);
        } finally {
            @unlink($out);
        }
    }

    /**
     * Régression phase 1 : le dump data-only doit embarquer les alias de
     * projets, sinon une restauration perd les redirections 301 et
     * l'anti-réimport du collect.
     */
    public function test_dump_defaut_inclut_les_project_aliases(): void
    {
        Projet::create([
            'id' => 'abc-dump-alias-test-2024',
            'nom' => 'ABC de Dump-Test',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        ProjectAlias::create([
            'project_id' => 'abc-dump-alias-test-2024',
            'alias_project_id' => 'abc-dump-doublon-test-2024',
            'alias_slug' => 'abc-de-dump-doublon',
            'source' => 'wayback',
            'created_by' => 'test',
        ]);

        $out = sys_get_temp_dir().'/abc-sqldump-alias-'.uniqid().'.sql';

        try {
            app(SqlDumpService::class)->dump($out);

            $sql = file_get_contents($out);
            $this->assertStringContainsString('INSERT INTO "project_aliases"', $sql);
            $this->assertStringContainsString('abc-dump-doublon-test-2024', $sql);
        } finally {
            @unlink($out);
        }
    }
}
