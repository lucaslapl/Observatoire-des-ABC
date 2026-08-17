<?php

namespace App\Console\Commands;

use App\Services\AnomalyService;
use App\Services\GeocodeService;
use Illuminate\Console\Command;

class GeocodeCommand extends Command
{
    protected $signature = 'abc:geocode';

    protected $description = 'Géocode les communes manquantes (geo.api.gouv.fr) puis recalcule les anomalies';

    public function handle(GeocodeService $geocode, AnomalyService $anomalies): int
    {
        $g = $geocode->enrichGeocoding();
        $this->line("géocodage : {$g['distinct']} communes distinctes, {$g['fetched']} à récupérer, {$g['updated']} avec coordonnées");
        $anom = $anomalies->computeAnomalies();
        $this->line("Anomalies détectées : {$anom} commune(s)");

        return self::SUCCESS;
    }
}
