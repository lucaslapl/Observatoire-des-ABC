<?php

namespace App\Console\Commands;

use App\Services\SqlDumpService;
use Illuminate\Console\Command;

/**
 * Export "portable" (sans PostGIS) des données vers SQL pur, pour un import
 * via Plesk sur un hébergement sans extension postgis. Colonne `geom` exclue.
 */
class ExportDeployCommand extends Command
{
    protected $signature = 'abc:export-deploy {--out= : fichier SQL de sortie}';

    protected $description = 'Génère un SQL data-only (sans geom) à importer dans Plesk';

    public function handle(SqlDumpService $dump): int
    {
        $out = $this->option('out') ?? storage_path('app/abc/deploy/abc-deploy.sql');

        $counts = $dump->dump($out);
        foreach ($counts as $table => $count) {
            $this->line(sprintf('%s : %d lignes', $table, $count));
        }

        $this->info("Fichier généré : {$out} (".number_format(filesize($out)).' o)');

        return self::SUCCESS;
    }
}
