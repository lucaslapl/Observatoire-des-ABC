<?php

namespace App\Console\Commands;

use App\Services\SqlDumpService;
use Illuminate\Console\Command;

/**
 * Export portable (SQL pur, sans PostGIS) des tables INPN : à générer en
 * local après abc:import-inpn, puis à importer côté hébergeur. Le dump
 * remplace les données existantes (DELETE avant chaque INSERT).
 */
class InpnExportCommand extends Command
{
    protected $signature = 'abc:export-inpn {--out= : fichier SQL de sortie}';

    protected $description = 'Génère un SQL portable des tables INPN (remplace les données existantes)';

    public function handle(SqlDumpService $dump): int
    {
        $out = $this->option('out') ?? storage_path('app/abc/deploy/inpn-deploy.sql');

        $counts = $dump->dumpInpn($out);
        foreach ($counts as $table => $count) {
            $this->line(sprintf('%s : %d lignes', $table, $count));
        }

        $this->info("Fichier généré : {$out} (".number_format(filesize($out)).' o)');

        return self::SUCCESS;
    }
}
