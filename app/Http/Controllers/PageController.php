<?php

namespace App\Http\Controllers;

use App\Models\Actualite;
use App\Models\Commune;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\ChangelogService;
use App\Services\LandingService;
use App\Services\MarkdownService;
use App\Services\PatrimoineEcologiqueService;
use App\Services\StatsService;
use App\Services\VerificationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    public function __construct(
        private StatsService $stats,
        private VerificationService $verifications,
        private LandingService $landing,
        private ChangelogService $changelog,
        private MarkdownService $markdown,
        private PatrimoineEcologiqueService $patrimoine,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Map', [
            'meta' => $this->stats->meta(),
            'index' => $this->stats->index(),
            'journal' => $this->changelog->paginate(),
            'isAdmin' => auth()->check() && auth()->user()->hasRole('admin'),
        ]);
    }

    public function carte(): Response
    {
        return Inertia::render('Carte', [
            'meta' => $this->stats->meta(),
            'isAdmin' => auth()->check() && auth()->user()->hasRole('admin'),
        ]);
    }

    public function verify(): Response
    {
        return Inertia::render('Verify', $this->verifications->list(true) + [
            'candidats' => $this->verifications->candidatsFondsVert(),
        ]);
    }

    /**
     * Page projet, résolue par slug. Un slug absent de `projets` mais présent
     * dans `project_aliases` (projet fusionné) redirige en 301 vers la page
     * du projet canonique : les URLs historiques ne deviennent jamais des 404.
     */
    public function projet(string $slug): Response|RedirectResponse
    {
        $projet = Projet::where('slug', $slug)->first();

        if (! $projet) {
            $alias = ProjectAlias::where('alias_slug', $slug)->first();
            if ($alias && ($canonique = Projet::find($alias->project_id))) {
                return redirect()->to('/abc/'.$canonique->slug, 301);
            }
            abort(404);
        }

        return Inertia::render('Projet', [
            'projet' => $this->landing->projet($projet),
        ]);
    }

    public function commune(string $code): Response
    {
        return Inertia::render('Commune', [
            'commune' => $this->landing->commune($code),
            'patrimoine' => $this->patrimoine->pourCommune($code),
        ]);
    }

    public function departement(string $code): Response|RedirectResponse
    {
        // Une seule forme canonique (minuscules) : la casse demandée
        // (/departement/56A) redirige vers la forme indexée.
        $norm = mb_strtolower($code);
        if ($code !== $norm) {
            return redirect()->to(route('departement.show', ['code' => $norm]), 301);
        }

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

    public function aPropos(): Response
    {
        $meta = $this->stats->meta();

        return Inertia::render('APropos', [
            'chiffres' => [
                'projets' => $meta['countProjets'],
                'communes' => Commune::query()->where('anomalie', false)->distinct()->count('code_geographique'),
                'verifies' => $meta['countVerifies'],
            ],
        ]);
    }

    public function methodologie(): Response
    {
        $meta = $this->stats->meta();

        return Inertia::render('Methodologie', [
            'chiffres' => [
                'projets' => $meta['countProjets'],
                'communes' => Commune::query()->where('anomalie', false)->distinct()->count('code_geographique'),
                'verifies' => $meta['countVerifies'],
                'doublons_fusionnes' => ProjectAlias::count(),
                'en_verification' => Verification::where('etat', 'a_verifier')->count(),
            ],
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

    /**
     * Page publique « API & conditions d'utilisation » : endpoints v1,
     * licence LO 2.0, crédit attendu, limites d'usage et versionnement.
     */
    public function api(): Response
    {
        return Inertia::render('Api');
    }

    public function actualites(): Response
    {
        $actualites = Actualite::query()
            ->where('statut', 'publie')
            ->where(fn ($q) => $q->whereNull('date_publication')->orWhere('date_publication', '<=', now()))
            ->orderByDesc('date_publication')
            ->get(['id', 'titre', 'slug', 'contenu', 'date_publication'])
            // La liste publique n'affiche qu'un extrait en texte brut : le
            // Markdown brut ne doit jamais fuiter dans les pages index.
            ->map(fn (Actualite $a) => [
                'id' => $a->id,
                'titre' => $a->titre,
                'slug' => $a->slug,
                'extrait' => $this->markdown->toText($a->contenu, 300),
                'date_publication' => $a->date_publication?->toDateString(),
            ])
            ->values();

        return Inertia::render('Actualites', ['actualites' => $actualites]);
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
