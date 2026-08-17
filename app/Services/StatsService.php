<?php

namespace App\Services;

use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'ensemble (port de /api/meta et /api/stats).
 */
class StatsService
{
    public function meta(): array
    {
        $countPt = Projet::where('potentiellement_termine', true)->count();
        $countPec = Projet::where('potentiellement_en_cours', true)->count();
        $countEstimes = Projet::where('estime_termine', true)->count();
        $countStale = Projet::where('source', 'wayback')->count();
        $countVerifies = Verification::where('etat', '!=', 'a_verifier')->count();
        $countAnomalies = Commune::where('anomalie', true)->count();
        $countContributionsEnAttente = Contribution::where('statut', 'en_attente')->count();

        return [
            'sources' => config('abc.source_dates'),
            'stats' => Projet::query()
                ->select('statut', DB::raw('COUNT(*) as n'))
                ->groupBy('statut')
                ->orderBy('statut')
                ->get()
                ->map(fn ($r) => ['statut' => $r->statut, 'n' => (int) $r->n])
                ->values()
                ->all(),
            'countProjets' => Projet::count(),
            'countPotentiellementTermines' => $countPt,
            'countPotentiellementEnCours' => $countPec,
            'countDonnees2022' => $countStale,
            'countEstimes' => $countEstimes,
            'countVerifies' => $countVerifies,
            'countAnomalies' => $countAnomalies,
            'countContributionsEnAttente' => $countContributionsEnAttente,
        ];
    }

    public function stats(): array
    {
        return Projet::query()
            ->select('statut', DB::raw('COUNT(*) as n'))
            ->groupBy('statut')
            ->orderBy('statut')
            ->get()
            ->map(fn ($r) => ['statut' => $r->statut, 'n' => (int) $r->n])
            ->values()
            ->all();
    }
}
