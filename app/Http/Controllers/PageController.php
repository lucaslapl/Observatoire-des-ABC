<?php

namespace App\Http\Controllers;

use App\Models\Actualite;
use App\Models\Contribution;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use App\Services\LandingService;
use App\Services\StatsService;
use App\Services\VerificationService;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function __construct(
        private StatsService $stats,
        private VerificationService $verifications,
        private LandingService $landing,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Map', [
            'meta' => $this->stats->meta(),
            'index' => $this->stats->index(),
            'isAdmin' => auth()->check() && auth()->user()->hasRole('admin'),
        ]);
    }

    public function verify(): Response
    {
        return Inertia::render('Verify', $this->verifications->list(true));
    }

    public function projet(Projet $projet): Response
    {
        return Inertia::render('Projet', [
            'projet' => $this->landing->projet($projet),
        ]);
    }

    public function commune(string $code): Response
    {
        return Inertia::render('Commune', [
            'commune' => $this->landing->commune($code),
        ]);
    }

    public function departement(string $code): Response
    {
        return Inertia::render('Departement', [
            'departement' => $this->landing->departement($code),
        ]);
    }

    public function region(string $slug): Response
    {
        return Inertia::render('Region', [
            'region' => $this->landing->region($slug),
        ]);
    }

    public function actualite(Actualite $actualite): Response
    {
        return Inertia::render('Actualite', [
            'actualite' => $this->landing->actualite($actualite),
        ]);
    }

    public function mentionsLegales(): Response
    {
        return Inertia::render('MentionsLegales');
    }

    public function confidentialite(): Response
    {
        return Inertia::render('Confidentialite');
    }

    public function actualites(): Response
    {
        $actualites = Actualite::query()
            ->where('statut', 'publie')
            ->where(fn ($q) => $q->whereNull('date_publication')->orWhere('date_publication', '<=', now()))
            ->orderByDesc('date_publication')
            ->get(['id', 'titre', 'slug', 'contenu', 'date_publication']);

        return Inertia::render('Actualites', ['actualites' => $actualites]);
    }

    public function admin(): Response
    {
        $contributions = Contribution::query()
            ->leftJoin('projets', 'projets.id', '=', 'contributions.projet_id')
            ->leftJoin('verifications', 'verifications.projet_id', '=', 'contributions.projet_id')
            ->select([
                'contributions.*',
                'projets.nom as projet_nom',
                'projets.structure_porteuse',
                'verifications.etat as verif_etat',
                'verifications.note as verif_note',
                'verifications.lien as verif_lien',
            ])
            ->orderByRaw("CASE contributions.statut WHEN 'en_attente' THEN 0 WHEN 'validee' THEN 1 ELSE 2 END")
            ->orderByDesc('contributions.created_at')
            ->get();

        $exclusions = ProjectExclusion::query()
            ->orderByDesc('created_at')
            ->get()
            ->map(function (ProjectExclusion $e) {
                return [
                    'projet_id' => $e->projet_id,
                    'nom' => Projet::whereKey($e->projet_id)->value('nom'),
                    'motif' => $e->motif,
                    'par_admin' => $e->par_admin,
                    'cree_le' => $e->created_at?->toDateTimeString(),
                ];
            });

        return Inertia::render('Admin', [
            'meta' => $this->stats->meta(),
            'contributions' => $contributions,
            'actualites' => Actualite::query()
                ->orderByDesc('created_at')
                ->get(['id', 'titre', 'slug', 'contenu', 'statut', 'date_publication']),
            'exclusions' => $exclusions,
        ]);
    }

    public function diag(): array
    {
        return [
            'adminState' => config('abc.admin.password') ? 'hash' : 'absent',
            'envFilePresent' => file_exists(base_path('.env')),
            'root' => base_path(),
            'dbDefault' => config('database.default'),
        ];
    }
}
