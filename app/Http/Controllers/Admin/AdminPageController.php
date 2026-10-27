<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Actualite;
use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use App\Models\User;
use App\Services\MarkdownService;
use App\Services\StatsService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panneau d'administration : une page par section (tableau de bord,
 * contributions, journaux, actualités, exclusions, comptes, maintenance).
 * Chaque liste est paginée côté serveur — la page ne charge que la tranche
 * affichée, pas toute la table.
 *
 * Les sections de modération (contributions, journaux) sont ouvertes aux
 * modérateurs ; les autres sont réservées aux administrateurs et protégées
 * par les routes (middleware role:admin), pas seulement masquées.
 */
class AdminPageController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(
        private StatsService $stats,
        private MarkdownService $markdown,
    ) {}

    /** Props communes à toutes les pages du panneau. */
    private function baseProps(): array
    {
        return [
            'isAdmin' => auth()->user()->hasRole('admin'),
            'pending' => Contribution::where('statut', 'en_attente')->count(),
        ];
    }

    public function dashboard(): Response
    {
        return Inertia::render('Admin/Dashboard', $this->baseProps() + [
            'meta' => $this->stats->meta(),
        ]);
    }

    public function contributions(Request $request): Response
    {
        $statut = (string) $request->query('statut', 'en_attente');
        if (! in_array($statut, ['en_attente', 'validee', 'historique', 'toutes'], true)) {
            $statut = 'en_attente';
        }

        $query = Contribution::query()
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
            ->orderByDesc('contributions.created_at');

        if ($statut === 'en_attente') {
            $query->where('contributions.statut', 'en_attente');
        } elseif ($statut === 'validee') {
            $query->where('contributions.statut', 'validee');
        } elseif ($statut === 'historique') {
            $query->whereIn('contributions.statut', ['refusee', 'annulee', 'retiree']);
        }

        $contributions = $query->paginate(self::PER_PAGE)->withQueryString();

        // Compteurs par onglet, en une seule requête agrégée (toBase :
        // colonnes calculées, pas des attributs du modèle Contribution).
        $counts = Contribution::query()
            ->toBase()
            ->selectRaw(
                'COUNT(*) as toutes, '
                ."SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente, "
                ."SUM(CASE WHEN statut = 'validee' THEN 1 ELSE 0 END) as validee, "
                ."SUM(CASE WHEN statut IN ('refusee', 'annulee', 'retiree') THEN 1 ELSE 0 END) as historique"
            )
            ->first();

        return Inertia::render('Admin/Contributions', $this->baseProps() + [
            'contributions' => $contributions,
            'statut' => $statut,
            'counts' => [
                'toutes' => (int) $counts->toutes,
                'en_attente' => (int) $counts->en_attente,
                'validee' => (int) $counts->validee,
                'historique' => (int) $counts->historique,
            ],
        ]);
    }

    public function journaux(): Response
    {
        // Deux paginateurs indépendants sur la même page : noms de paramètres
        // distincts (?vpage=, ?fpage=) pour que chacun garde l'autre en
        // mémoire lors du changement de page.
        $verifications = AuditLog::query()
            ->where('action', 'verification')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'vpage')
            ->withQueryString()
            ->through(function (AuditLog $log) {
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
            });

        $fusions = AuditLog::query()
            ->where('action', 'fusion')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'fpage')
            ->withQueryString()
            ->through(function (AuditLog $log) {
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
            });

        return Inertia::render('Admin/Journaux', $this->baseProps() + [
            'journalVerifications' => $verifications,
            'journalFusions' => $fusions,
        ]);
    }

    public function actualites(): Response
    {
        $actualites = Actualite::query()
            ->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (Actualite $a) => [
                'id' => $a->id,
                'titre' => $a->titre,
                'slug' => $a->slug,
                // Contenu Markdown brut pour le formulaire d'édition…
                'contenu' => $a->contenu,
                // …et extrait nettoyé pour la liste.
                'extrait' => $this->markdown->toText($a->contenu, 80),
                'statut' => $a->statut,
                'date_publication' => $a->date_publication?->toISOString(),
            ]);

        return Inertia::render('Admin/Actualites', $this->baseProps() + [
            'actualites' => $actualites,
        ]);
    }

    public function exclusions(): Response
    {
        $exclusions = ProjectExclusion::query()
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ProjectExclusion $e) => [
                'projet_id' => $e->projet_id,
                'nom' => Projet::whereKey($e->projet_id)->value('nom'),
                'motif' => $e->motif,
                'par_admin' => $e->par_admin,
                'cree_le' => $e->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Admin/Exclusions', $this->baseProps() + [
            'exclusions' => $exclusions,
        ]);
    }

    public function comptes(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $query = User::query()
            ->withCount('contributions')
            ->orderByDesc('created_at');

        if ($q !== '') {
            $query->where(fn ($sub) => $sub
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"));
        }

        $comptes = $query
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $u->getRoleNames()->values()->all(),
                'email_verifie' => $u->hasVerifiedEmail(),
                'nb_contributions' => $u->contributions_count,
                'cree_le' => $u->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Admin/Comptes', $this->baseProps() + [
            'comptes' => $comptes,
            'q' => $q,
        ]);
    }

    public function maintenance(): Response
    {
        return Inertia::render('Admin/Maintenance', $this->baseProps() + [
            'meta' => $this->stats->meta(),
        ]);
    }
}
