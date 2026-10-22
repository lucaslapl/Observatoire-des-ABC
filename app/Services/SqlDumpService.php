<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dump SQL data-only en pur PHP (sans pg_dump, donc sans proc_open) :
 * utilisé par abc:export-deploy (import Plesk sans PostGIS) et comme
 * fallback de la sauvegarde quand l'hébergeur désactive proc_open.
 * Colonne `geom` exclue.
 */
class SqlDumpService
{
    /** Ordre d'insertion respecté (clés étrangères) + tables runtime exclues. */
    private array $tables = [
        'users',
        'roles',
        'permissions',
        'model_has_permissions',
        'role_has_permissions',
        'model_has_roles',
        'projets',
        'communes',
        'snapshots',
        'enrichissements',
        'verifications',
        'contributions',
        'audit_log',
        'actualites',
        'project_exclusions',
        'geo_cache',
    ];

    /**
     * @param  array<string>|null  $tables  tables à dumper (défaut : toutes)
     * @return array<string, int> lignes écrites par table
     */
    public function dump(string $out, ?array $tables = null): array
    {
        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0777, true);
        }

        $handle = fopen($out, 'w');
        fwrite($handle, '-- Export portable (sans PostGIS) — '.now()->toDateTimeString()."\n");
        fwrite($handle, "SET client_encoding = 'UTF8';\nSET statement_timeout = 0;\nBEGIN;\n\n");

        $counts = [];
        foreach ($tables ?? $this->tables as $table) {
            $counts[$table] = $this->dumpTable($handle, $table);
            fwrite($handle, "\n");
        }

        fwrite($handle, "COMMIT;\n");
        fclose($handle);

        return $counts;
    }

    private function dumpTable($handle, string $table): int
    {
        $columns = Schema::getColumnListing($table);
        if ($table === 'communes') {
            $columns = array_values(array_diff($columns, ['geom']));
        }

        $rows = DB::table($table)->select($columns)->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $cols = implode(', ', array_map(fn ($c) => "\"{$c}\"", $columns));
        $batch = [];

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $c) {
                $values[] = $this->sqlValue($row->{$c});
            }
            $batch[] = '('.implode(', ', $values).')';

            if (count($batch) >= 500) {
                $this->writeBatch($handle, $table, $cols, $batch);
                $batch = [];
            }
        }
        if ($batch) {
            $this->writeBatch($handle, $table, $cols, $batch);
        }

        return $rows->count();
    }

    private function writeBatch($handle, string $table, string $cols, array $batch): void
    {
        fwrite($handle, "INSERT INTO \"{$table}\" ({$cols}) VALUES\n".implode(",\n", $batch).";\n");
    }

    private function sqlValue(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        // booléens PostgreSQL (PDO renvoie 't'/'f')
        if ($v === true || $v === 't') {
            return 'true';
        }
        if ($v === false || $v === 'f') {
            return 'false';
        }

        return "'".str_replace("'", "''", (string) $v)."'";
    }
}
