<?php

namespace App\Services;

use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Statistiques d'ensemble (port de /api/meta et /api/stats).
 */
class StatsService
{
    public function __construct(private RegionService $regions) {}

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

    /**
     * Index du territoire : régions et départements présents dans les données,
     * avec nombre de projets distincts. Sert au maillage interne (SEO).
     *
     * @return array{regions: array<int, array{slug:string,label:string,n:int}>, departements: array<int, array{code:string,label:string,n:int}>}
     */
    public function index(): array
    {
        $regionRows = Commune::query()
            ->whereNotNull('region')
            ->selectRaw('region, COUNT(DISTINCT projet_id) as n')
            ->groupBy('region')
            ->get();

        $regions = [];
        foreach ($regionRows as $row) {
            $label = $this->regions->label($row->region);
            if (! $label) {
                continue;
            }
            $slug = Str::slug($label);
            $regions[$slug] ??= ['slug' => $slug, 'label' => $label, 'n' => 0];
            $regions[$slug]['n'] += (int) $row->n;
        }
        uasort($regions, fn ($a, $b) => strcmp($a['label'], $b['label']));

        $departements = Commune::query()
            ->whereNotNull('departement')
            ->selectRaw('departement, MAX(libelle_departement) as libelle, COUNT(DISTINCT projet_id) as n')
            ->groupBy('departement')
            ->orderBy('departement')
            ->get()
            ->map(fn ($d) => [
                'code' => mb_strtolower((string) $d->departement),
                'label' => $d->libelle ?: (string) $d->departement,
                'n' => (int) $d->n,
            ])
            ->values()
            ->all();

        return [
            'regions' => array_values($regions),
            'departements' => $departements,
        ];
    }
}
