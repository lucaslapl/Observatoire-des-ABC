<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Journal public des modifications visibles sur la carte, alimenté par
 * `audit_log`. Seules les actions qui changent ce que le site montre sont
 * publiées : les actions internes (alertes de collecte, worklist, data_fix,
 * contributions refusées ou annulées…) restent invisibles.
 */
class ChangelogService
{
    /**
     * Actions de l'audit qui modifient les données publiées, affichées dans
     * l'historique complet d'une fiche projet.
     */
    private const ACTIONS_FICHE = ['verification', 'validee', 'retiree', 'fusion', 'sync_dates', 'projet_supprime'];

    /**
     * Actions affichées sur l'accueil : les corrections automatiques (audit
     * data_fix, synchronisation des dates) n'y figurent pas — elles restent
     * visibles dans l'historique des fiches concernées.
     */
    private const ACTIONS_ACCUEIL = ['verification', 'validee', 'retiree', 'fusion', 'projet_supprime'];

    /** Libellés des acteurs non humains (commandes artisan journalisées). */
    private const COMMANDES = [
        'abc:sync-confirmed-dates' => 'Synchronisation automatique',
        'abc:data-fix' => 'Audit automatique',
        'abc:merge-projects' => 'Fusion automatique',
    ];

    /** Libellés des verdicts de vérification manuelle. */
    private const VERDICTS = [
        'confirme_termine' => 'Statut vérifié : terminé',
        'confirme_en_cours' => 'Statut vérifié : en cours',
        'toujours_a_venir' => 'Statut vérifié : va débuter',
        'confirme_date' => 'Dates confirmées',
        'introuvable' => 'Projet introuvable à la vérification',
        'douteux' => 'Données jugées incertaines',
        'a_verifier' => 'Remis en attente de vérification',
    ];

    /** Libellés des contributions publiques validées, par type de correction. */
    private const CONTRIBUTIONS = [
        'statut' => 'Statut corrigé (contribution validée)',
        'date_debut' => 'Dates corrigées (contribution validée)',
        'note' => 'Note de vérification ajoutée (contribution validée)',
        'lien' => 'Lien source ajouté (contribution validée)',
        'autre' => 'Contribution validée',
    ];

    public function paginate(int $perPage = 5): array
    {
        /** @var LengthAwarePaginator<AuditLog> $paginator */
        $paginator = AuditLog::query()
            ->whereIn('action', self::ACTIONS_ACCUEIL)
            // Seules les modifications effectuées par une personne figurent
            // dans la liste : tout ce qui vient d'une commande artisan (ex.
            // fusions « abc:merge-projects ») reste dans l'historique des
            // fiches projet.
            ->where(fn ($q) => $q->whereNull('par_admin')->orWhere('par_admin', 'not like', 'abc:%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'journal_page');

        $page = $paginator->currentPage();
        $lastPage = max(1, $paginator->lastPage());

        return [
            'items' => $this->mapRows($paginator->getCollection(), avecCommunes: true),
            'page' => $page,
            'last_page' => $lastPage,
            'total' => $paginator->total(),
            'has_prev' => $page > 1,
            'has_next' => $page < $lastPage,
        ];
    }

    /**
     * Historique complet d'un projet, le plus récent en premier : ce que
     * un visiteur peut reconstituer de l'évolution de la fiche.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pourProjet(string $projetId): array
    {
        $rows = AuditLog::query()
            ->where(function ($q) {
                $q->whereIn('action', self::ACTIONS_FICHE)
                    ->orWhere('action', 'like', 'data_fix:%');
            })
            ->where(function ($q) use ($projetId) {
                $q->where('apres', 'like', '%"projet_id":"'.$projetId.'"%')
                    ->orWhere('avant', 'like', '%"projet_id":"'.$projetId.'"%')
                    ->orWhere('avant', 'like', '%"projet":"'.$projetId.'"%')
                    ->orWhere('apres', 'like', '%"canonique":{"id":"'.$projetId.'"%')
                    ->orWhereIn('contribution_id', Contribution::query()->where('projet_id', $projetId)->select('id'));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        // Le LIKE attrape des faux positifs (doublon fusionné : le doublon
        // contient l'id, pas le canonique) : on affine via projetIdOf.
        $contributions = $this->contributionsOf($rows);

        return $rows
            ->filter(fn (AuditLog $row) => $this->projetIdOf($row, $contributions) === $projetId)
            ->map(fn (AuditLog $row) => $this->entry($row, $contributions, collect()))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(Collection $rows, bool $avecCommunes = false): array
    {
        $contributions = $this->contributionsOf($rows);

        $projetIds = $rows
            ->map(fn (AuditLog $row) => $this->projetIdOf($row, $contributions))
            ->filter()
            ->unique()
            ->values();
        $projets = Projet::query()
            ->whereIn('id', $projetIds)
            ->get(['id', 'nom', 'slug'])
            ->keyBy('id');

        // Commune principale de chaque projet (+ le nombre d'autres communes).
        $communes = $avecCommunes ? $this->communesOf($projetIds->all()) : null;

        return $rows
            ->map(fn (AuditLog $row) => $this->entry($row, $contributions, $projets, $communes))
            ->values()
            ->all();
    }

    /**
     * Libellé de la commune principale par projet, ex. « Vannes + 2 autres ».
     *
     * @param  array<int, string>  $projetIds
     * @return array<string, ?string>
     */
    private function communesOf(array $projetIds): array
    {
        return Commune::query()
            ->whereIn('projet_id', $projetIds)
            ->orderBy('libelle_geographique')
            ->get(['projet_id', 'libelle_geographique'])
            ->groupBy('projet_id')
            ->map(function (Collection $groupe) {
                $libelle = $groupe->first()?->libelle_geographique;

                return $groupe->count() > 1 ? "{$libelle} + ".($groupe->count() - 1).' autres' : $libelle;
            })
            ->all();
    }

    /**
     * @param  Collection<int, AuditLog>  $rows
     * @return Collection<int, Contribution>
     */
    private function contributionsOf(Collection $rows): Collection
    {
        return Contribution::query()
            ->whereIn('id', $rows->pluck('contribution_id')->filter()->unique()->values())
            ->with('user:id,name,contributions_anonymes')
            ->get(['id', 'projet_id', 'type', 'user_id'])
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, Contribution>  $contributions
     * @param  Collection<string, Projet>|Collection<int, Projet>  $projets
     * @param  array<string, ?string>|null  $communes
     * @return array<string, mixed>
     */
    private function entry(AuditLog $row, Collection $contributions, Collection $projets, ?array $communes = null): array
    {
        $avant = json_decode($row->avant ?? 'null', true) ?: [];
        $apres = json_decode($row->apres ?? 'null', true) ?: [];
        $contribution = $row->contribution_id ? $contributions->get($row->contribution_id) : null;

        $projetId = $this->projetIdOf($row, $contributions);
        $projet = $projetId ? $projets->get($projetId) : null;

        // Nom du projet : la fiche si elle existe encore, sinon le nom
        // figé dans le journal (fiche supprimée, ou fusion vers un canonique).
        $nom = $projet !== null ? $projet->nom : null;
        $nom ??= $apres['nom'] ?? $avant['nom'] ?? $apres['canonique']['nom'] ?? null;

        return [
            'date' => $row->created_at?->toDateTimeString(),
            'label' => $this->label($row->action, $apres, $contribution?->type),
            'detail' => $this->detail($row->action, $apres),
            'auteur' => $this->auteur($row, $contribution),
            'commune' => $communes[$projetId] ?? null,
            'projet_nom' => $nom,
            'projet_slug' => $projet?->slug,
        ];
    }

    /**
     * Auteur de la modification : le contributeur pour une suggestion
     * publique, l'opérateur sinon. Les emails et commandes artisan ne sont
     * jamais exposés publiquement.
     */
    private function auteur(AuditLog $row, ?Contribution $contribution): ?string
    {
        if ($row->action === 'validee') {
            // Les contributions antérieures à l'espace membre n'ont pas de
            // compte lié : le nom du contributeur n'existe pas dans les
            // données, on n'affiche jamais celui du valideur à la place.
            // Un contributeur ayant demandé l'anonymat est masqué de même.
            $user = $contribution?->user;

            return ! $user || $user->contributions_anonymes ? 'un contributeur anonyme' : $user->name;
        }

        $par = $row->par_admin;

        if (! $par) {
            return null;
        }

        // Les emails ne sont jamais exposés publiquement.
        if (str_contains($par, '@')) {
            return 'un administrateur';
        }

        return self::COMMANDES[$par] ?? (str_starts_with($par, 'abc:') ? 'Traitement automatique' : $par);
    }

    /**
     * Identifiant du projet concerné, selon la forme de l'entrée :
     * verdict de vérification (projet_id dans apres/avant), contribution
     * (via contributions.projet_id), fusion (canonique) ou suppression
     * (avant.projet_id).
     *
     * @param  Collection<int, Contribution>  $contributions
     */
    private function projetIdOf(AuditLog $row, Collection $contributions): ?string
    {
        $apres = json_decode($row->apres ?? 'null', true) ?: [];
        $avant = json_decode($row->avant ?? 'null', true) ?: [];

        return $apres['projet_id']
            ?? $apres['canonique']['id']
            ?? $avant['projet_id']
            ?? $avant['projet']
            ?? ($row->contribution_id ? $contributions->get($row->contribution_id)?->projet_id : null);
    }

    private function label(string $action, array $apres, ?string $typeContribution): string
    {
        if ($action === 'verification') {
            return self::VERDICTS[$apres['etat'] ?? ''] ?? 'Vérification manuelle';
        }

        if ($action === 'validee') {
            return self::CONTRIBUTIONS[$typeContribution ?? 'autre'] ?? self::CONTRIBUTIONS['autre'];
        }

        if (str_starts_with($action, 'data_fix:')) {
            return 'Correction automatique (audit des données)';
        }

        return [
            'retiree' => 'Correction retirée',
            'fusion' => 'Doublon fusionné',
            'sync_dates' => 'Dates resynchronisées',
            'projet_supprime' => 'Projet retiré de la carte',
        ][$action] ?? 'Mise à jour';
    }

    /**
     * Précision chiffrée pour les corrections de période, ex. « 2019-2023 ».
     */
    private function detail(string $action, array $apres): ?string
    {
        if (! in_array($action, ['verification', 'sync_dates'], true)) {
            return null;
        }

        $debut = $apres['annee_debut'] ?? null;
        $fin = $apres['annee_fin'] ?? null;

        if ($debut === null && $fin === null) {
            return null;
        }

        return $debut === null
            ? "jusqu'à {$fin}"
            : ($fin === null ? "depuis {$debut}" : "{$debut}-{$fin}");
    }
}
