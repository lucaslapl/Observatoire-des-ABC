<?php

namespace App\Console\Commands;

use App\Models\Commune;
use App\Models\Projet;
use App\Services\AnomalyService;
use App\Services\StatusRecomputeService;
use App\Services\StatusService;
use Illuminate\Console\Command;

class StatusCommand extends Command
{
    protected $signature = 'abc:status';

    protected $description = 'Recalcule les statuts et les anomalies';

    public function handle(StatusRecomputeService $recompute, AnomalyService $anomalies): int
    {
        $r = $recompute->recomputeStatuses();
        $this->line("Statuts recalculés : {$r['changed']} projet(s) modifié(s)");

        $anom = $anomalies->computeAnomalies();
        $this->line("Anomalies détectées : {$anom} commune(s)");

        $this->printStats();

        return self::SUCCESS;
    }

    private function printStats(): void
    {
        $total = Projet::count();
        $rows = Projet::selectRaw('statut, COUNT(*) AS n')->groupBy('statut')->orderByDesc('n')->get();
        $communes = Commune::count();
        $geocoded = Commune::whereNotNull('lon')->whereNotNull('lat')
            ->where(fn ($q) => $q->where('lon', '!=', 0)->orWhere('lat', '!=', 0))
            ->count();

        $this->line("\n=== {$total} projets / {$communes} lignes communes / {$geocoded} géo ===");
        $status = new StatusService;
        foreach ($rows as $r) {
            $this->line('  '.str_pad($status->statutLabel($r->statut), 16)." {$r->n}");
        }
    }
}
