<?php

namespace App\Console\Commands;

use App\Services\CollectService;
use App\Services\StatusService;
use Illuminate\Console\Command;

class CollectCommand extends Command
{
    protected $signature = 'abc:collect';

    protected $description = 'Collecte des 4 sources (registre, wayback, fonds vert) puis statuts, géocodage et anomalies';

    public function handle(CollectService $service): int
    {
        $this->line('Collect complet en cours…');
        $s = $service->collectAll();

        $this->line("data.gouv : {$s['sources']['datagouv']['projets']} projets / {$s['sources']['datagouv']['communes']} communes");
        $this->line("wayback : {$s['sources']['wayback']['projets']} projets uniquement issus des archives (instantané 2022-12-06) + snapshots historiques");
        foreach ($s['sources']['fondsvert']['by_year'] as $year => $n) {
            $this->line("fonds vert biodiversité {$year} : {$n} projets ABC");
        }
        $g = $s['geocoding'];
        $this->line("géocodage : {$g['distinct']} communes distinctes, {$g['fetched']} à récupérer, {$g['updated']} avec coordonnées");

        $this->line("\n=== {$s['total']} projets / {$s['communes']} lignes communes / {$s['geocoded']} géo ===");
        foreach ($s['statuses'] as $statut => $n) {
            $this->line('  '.str_pad((new StatusService)->statutLabel($statut), 16)." {$n}");
        }
        $this->line("anomalies détectées : {$s['anomalies']} commune(s)");

        return self::SUCCESS;
    }
}
