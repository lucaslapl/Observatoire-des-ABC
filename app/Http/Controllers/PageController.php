<?php

namespace App\Http\Controllers;

use App\Models\Actualite;
use App\Models\Contribution;
use App\Services\StatsService;
use App\Services\VerificationService;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function __construct(
        private StatsService $stats,
        private VerificationService $verifications,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Map', [
            'meta' => $this->stats->meta(),
        ]);
    }

    public function verify(): Response
    {
        return Inertia::render('Verify', $this->verifications->list());
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

        return Inertia::render('Admin', [
            'meta' => $this->stats->meta(),
            'contributions' => $contributions,
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
