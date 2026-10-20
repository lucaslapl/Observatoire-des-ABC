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
        // Une seule requête groupée remplace cinq COUNT(*) sur `projets`
        // (la sortie doit rester strictement identique : parité API).
        $rows = Projet::query()
            ->selectRaw(
                'statut, COUNT(*) as n, '
                .'SUM(CASE WHEN potentiellement_termine THEN 1 ELSE 0 END) as pt, '
                .'SUM(CASE WHEN potentiellement_en_cours THEN 1 ELSE 0 END) as pec, '
                .'SUM(CASE WHEN estime_termine THEN 1 ELSE 0 END) as et, '
                ."SUM(CASE WHEN source = 'wayback' THEN 1 ELSE 0 END) as wayback"
            )
            ->groupBy('statut')
            ->orderBy('statut')
            ->get();

        return [
            'sources' => config('abc.source_dates'),
            'stats' => $rows
                ->map(fn ($r) => ['statut' => $r->statut, 'n' => (int) $r->n])
                ->values()
                ->all(),
            'countProjets' => (int) $rows->sum('n'),
            'countPotentiellementTermines' => (int) $rows->sum('pt'),
            'countPotentiellementEnCours' => (int) $rows->sum('pec'),
            'countDonnees2022' => (int) $rows->sum('wayback'),
            'countEstimes' => (int) $rows->sum('et'),
            'countVerifies' => Verification::where('etat', '!=', 'a_verifier')->count(),
            'countAnomalies' => Commune::where('anomalie', true)->count(),
            'countNonLocalises' => Projet::query()
                ->whereDoesntHave('communes', fn ($q) => $q
                    ->whereNotNull('lon')
                    ->whereNotNull('lat')
                    ->where(fn ($q2) => $q2->where('lon', '!=', 0)->orWhere('lat', '!=', 0)))
                ->count(),
            'countContributionsEnAttente' => Contribution::where('statut', 'en_attente')->count(),
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
