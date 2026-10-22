<?php

namespace Tests\Unit;

use App\Services\SqlDumpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SqlDumpServiceTest extends TestCase
{
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
}
