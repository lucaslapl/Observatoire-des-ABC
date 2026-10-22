<?php

namespace App\Http\Controllers;

use App\Models\Actualite;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\ProjectAlias;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use App\Models\User;
use App\Models\Verification;
use App\Services\LandingService;
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
    ) {}

    public function index(): Response
    {
        return Inertia::render('Map', [
            'meta' => $this->stats->meta(),
            'index' => $this->stats->index(),
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
        $isAdmin = auth()->user()->hasRole('admin');

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

        $props = [
            'isAdmin' => $isAdmin,
            'meta' => $this->stats->meta(),
            'contributions' => $contributions,
            // Journal des vérifications manuelles (verdicts directs enregistrés
            // depuis /verify) : visible des admins et des modérateurs pour
            // savoir qui a tranché quoi, et quand.
            'journalVerifications' => AuditLog::query()
                ->where('action', 'verification')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(function (AuditLog $log) {
                    $avant = json_decode($log->avant ?? 'null', true) ?: [];
                    $apres = json_decode($log->apres ?? 'null', true) ?: [];

                    return [
                        'date' => $log->created_at?->toDateTimeString(),
                        'projet_id' => $apres['projet_id'] ?? $avant['projet_id'] ?? '',
                        'nom' => $apres['nom'] ?? '',
                        'etat_avant' => $avant['etat'] ?? null,
                        'etat' => $apres['etat'] ?? '',
                        'note' => $apres['note'] ?? null,
                        'lien' => $apres['lien'] ?? null,
                        'par' => $log->par_admin,
                    ];
                }),
            // Journal des fusions de doublons (bouton « Fusionner ce
            // doublon » de /verify et abc:merge-projects) : qui a fusionné
            // quoi, dans quoi, pourquoi, et quand — visible des admins et
            // des modérateurs.
            'journalFusions' => AuditLog::query()
                ->where('action', 'fusion')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(200)
                ->get()
                ->map(function (AuditLog $log) {
                    $avant = json_decode($log->avant ?? 'null', true) ?: [];
                    $apres = json_decode($log->apres ?? 'null', true) ?: [];

                    return [
                        'date' => $log->created_at?->toDateTimeString(),
                        'doublon_id' => $avant['doublon']['id'] ?? '',
                        'doublon' => $avant['doublon']['nom'] ?? '',
                        'canonique_id' => $apres['canonique']['id'] ?? '',
                        'canonique' => $apres['canonique']['nom'] ?? '',
                        'motif' => $apres['motif'] ?? '',
                        'par' => $log->par_admin,
                    ];
                }),
        ];

        // Sections de maintenance : administrateurs uniquement. Les
        // modérateurs (experts de confiance) n'accèdent qu'à la modération
        // des contributions — pas aux comptes (RGPD) ni au paramétrage.
        if ($isAdmin) {
            $props['exclusions'] = ProjectExclusion::query()
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

            $props['comptes'] = User::query()
                ->withCount('contributions')
                ->orderByDesc('created_at')
                ->get()
                ->map(function (User $u) {
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'email' => $u->email,
                        'roles' => $u->getRoleNames()->values()->all(),
                        'email_verifie' => $u->hasVerifiedEmail(),
                        'nb_contributions' => $u->contributions_count,
                        'cree_le' => $u->created_at?->toDateTimeString(),
                    ];
                });

            $props['actualites'] = Actualite::query()
                ->orderByDesc('created_at')
                ->get(['id', 'titre', 'slug', 'contenu', 'statut', 'date_publication']);
        }

        return Inertia::render('Admin', $props);
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
