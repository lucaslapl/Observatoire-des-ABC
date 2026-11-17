<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ProjectAlias;
use App\Models\Projet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Santé des slugs de fiches (/abc/{slug}) — diagnostic et réparation.
 *
 * Audit SEO du 2026-11-17 (C2) : des fiches référencées renvoyaient 404
 * alors que le projet existe — slug vide (import SQL sans Eloquent,
 * colonne non rétro-remplie) ou slug divergeant de Str::slug(nom), celui
 * que les moteurs dérivent spontanément du nom (origines : slugs
 * séquentiels hérités de l'import SQLite « abc-454 », noms nettoyés sans
 * re-slug, fusions sans alias…).
 *
 * Règles absolues :
 * - un slug existant n'est JAMAIS modifié en place : l'URL reste stable ;
 * - une divergence se répare par un project_alias (301) du slug calculé
 *   vers la fiche — sauf si ce slug appartient à un autre projet, auquel
 *   cas le conflit est listé pour arbitrage manuel ;
 * - chaque action est journalisée dans audit_log (action slugs_fix:*,
 *   opérateur abc:fix:slugs) : aucune donnée humaine (vérifications,
 *   contributions), aucun alias existant n'est supprimé ou écrasé.
 */
class SlugHealthService
{
    /**
     * Projets sans slug alors qu'ils ont au moins une commune : leur fiche
     * est inaccessible (/abc/null) alors qu'ils sont visibles dans les
     * listes — le constat le plus grave de l'audit.
     *
     * @return Collection<int, Projet>
     */
    public function sansSlugAvecCommunes(): Collection
    {
        return Projet::query()
            ->whereIn('id', fn ($q) => $q->select('projet_id')->from('communes'))
            ->where(fn ($q) => $q->whereNull('slug')->orWhere('slug', ''))
            ->orderBy('nom')
            ->get();
    }

    /**
     * Tous les projets sans slug (y compris sans commune) : à réparer par
     * abc:fix:slugs.
     *
     * @return Collection<int, Projet>
     */
    public function sansSlug(): Collection
    {
        return Projet::query()
            ->where(fn ($q) => $q->whereNull('slug')->orWhere('slug', ''))
            ->orderBy('nom')
            ->get();
    }

    /**
     * Projets dont le slug stocké diffère du slug calculé du nom :
     * l'URL /abc/{slug calculé} est celle que les moteurs dérivent du nom —
     * aujourd'hui 404. Les collisions suffixées ({slug}-2) d'homonymes
     * sont un cas sain (deux projets, deux éditions distinctes) : marquées
     * suffixe et listées pour information.
     *
     * @return Collection<int, array{projet: Projet, calcule: string, suffixe: bool}>
     */
    public function divergents(): Collection
    {
        $rows = [];
        foreach (Projet::query()->whereNotNull('slug')->where('slug', '!=', '')->orderBy('nom')->get() as $projet) {
            $calcule = Str::slug((string) $projet->nom);
            if ($calcule === '' || $calcule === $projet->slug) {
                continue;
            }
            $suffixe = preg_match('/^'.preg_quote($calcule, '/').'-\d+$/', (string) $projet->slug) === 1;
            $rows[] = ['projet' => $projet, 'calcule' => $calcule, 'suffixe' => $suffixe];
        }

        return collect($rows);
    }

    /**
     * Aliases dont le projet cible a disparu ou n'a plus de slug : la
     * redirection 301 échoue (404) ou pointe vers /abc/null.
     *
     * @return Collection<int, array{alias: ProjectAlias, motif: string}>
     */
    public function aliasesCassees(): Collection
    {
        $projets = Projet::query()->get(['id', 'slug'])->keyBy('id');

        /** @var array<int, array{alias: ProjectAlias, motif: string}> $rows */
        $rows = [];
        foreach (ProjectAlias::query()->orderBy('alias_slug')->get() as $alias) {
            $cible = $projets->get($alias->project_id);
            if ($cible === null) {
                $rows[] = ['alias' => $alias, 'motif' => 'projet cible introuvable'];

                continue;
            }
            if ($cible->slug === null || $cible->slug === '') {
                $rows[] = ['alias' => $alias, 'motif' => 'projet cible sans slug (/abc/null)'];
            }
        }

        return collect($rows);
    }

    /**
     * Aliases en conflit : deux entrées sur le même alias_slug (index unique
     * absent ou cassé), ou alias_slug égal au slug d'un autre projet — la
     * résolution par fiche (Projet::where('slug')) gagnerait toujours, et
     * la redirection ne serait jamais servie.
     *
     * @return Collection<int, array{alias: ProjectAlias, motif: string}>
     */
    public function aliasesEnConflit(): Collection
    {
        // slug => id des projets porteurs d'un slug.
        $slugsVersId = Projet::query()
            ->whereNotNull('slug')->where('slug', '!=', '')
            ->pluck('id', 'slug');

        $compte = [];
        $conflits = collect();

        foreach (ProjectAlias::query()->orderBy('alias_slug')->get() as $alias) {
            $compte[$alias->alias_slug] = ($compte[$alias->alias_slug] ?? 0) + 1;
            $porteurId = $slugsVersId->get($alias->alias_slug);
            if ($porteurId !== null && $porteurId !== $alias->project_id) {
                $conflits[] = [
                    'alias' => $alias,
                    'motif' => "« {$alias->alias_slug} » est aussi le slug du projet {$porteurId} : la fiche gagne, la redirection ne sert jamais",
                ];
            }
        }

        foreach ($compte as $aliasSlug => $n) {
            if ($n < 2) {
                continue;
            }
            ProjectAlias::query()->where('alias_slug', $aliasSlug)->get()
                ->each(fn (ProjectAlias $alias) => $conflits[] = [
                    'alias' => $alias,
                    'motif' => "« {$aliasSlug} » porté par {$n} aliases",
                ]);
        }

        return $conflits;
    }

    /**
     * Réparation complète : slug réattribué aux projets sans slug (dérivé
     * du nom, suffixe numérique en cas de collision), puis alias 301 pour
     * chaque slug divergent dont le slug calculé est libre. Par défaut en
     * lecture seule ($apply = false) : le plan est listé, rien n'est écrit.
     *
     * @return array{actions: array<int, array{type: string, projet_id: string, nom: string, detail: string}>, conflits: array<int, string>}
     */
    public function fix(bool $apply): array
    {
        $actions = [];
        $conflits = [];

        // 1. Slugs manquants : dérivation du nom, collision suffixée.
        //    Jamais de réécriture d'un slug existant.
        foreach ($this->sansSlug() as $projet) {
            $slug = Projet::makeUniqueSlug($projet->nom, $projet->id);
            $actions[] = [
                'type' => 'slug_reattribue',
                'projet_id' => $projet->id,
                'nom' => $projet->nom,
                'detail' => "slug absent → {$slug}",
            ];

            if (! $apply) {
                continue;
            }

            $projet->update(['slug' => $slug]);
            $this->journaliser('slugs_fix:slug_reattribue', $projet, [
                'avant' => ['projet' => $projet->id, 'slug' => null],
                'apres' => ['projet' => $projet->id, 'slug' => $slug],
            ]);
        }

        // 2. Divergences : le slug stocké (URL stable) reste en place, le
        //    slug calculé du nom devient un alias 301 s'il n'appartient ni
        //    à un autre projet ni déjà à un autre alias.
        $slugsVersId = Projet::query()
            ->whereNotNull('slug')->where('slug', '!=', '')
            ->pluck('id', 'slug');
        foreach ($this->divergents() as $divergent) {
            /** @var Projet $projet */
            $projet = $divergent['projet'];
            $calcule = $divergent['calcule'];

            $porteurId = $slugsVersId->get($calcule);
            if ($porteurId !== null && $porteurId !== $projet->id) {
                $info = $divergent['suffixe'] ? ' (collision d\'homonymes : slug suffixé, URLs stables des deux côtés)' : '';
                $conflits[] = "« {$calcule} » est le slug du projet {$porteurId}{$info} — à départager manuellement si les URLs historiques doivent servir l'un des deux projets";

                continue;
            }

            $existant = ProjectAlias::query()->where('alias_slug', $calcule)->first();
            if ($existant) {
                if ($existant->project_id !== $projet->id) {
                    $conflits[] = "« {$calcule} » est déjà un alias vers {$existant->project_id} — ne pointe pas vers {$projet->id}";
                }

                continue; // sinon déjà réparé : idempotence
            }

            $actions[] = [
                'type' => 'alias_cree',
                'projet_id' => $projet->id,
                'nom' => $projet->nom,
                'detail' => "/abc/{$calcule} → 301 → /abc/{$projet->slug}",
            ];

            if (! $apply) {
                continue;
            }

            ProjectAlias::create([
                'project_id' => $projet->id,
                // Aucun ancien projet : alias « même projet » (renommage,
                // slug hérité séquentiel) — voir la migration du
                // 2026-11-17 rendant la colonne nullable.
                'alias_project_id' => null,
                'alias_slug' => $calcule,
                'source' => $projet->source,
                'motif' => 'Slug calculé du nom divergent du slug stocké : URL indexée en 404, redirection 301 vers la fiche (audit SEO du 2026-11-17, abc:fix:slugs)',
                'created_by' => 'abc:fix:slugs',
            ]);
            $this->journaliser('slugs_fix:alias_cree', $projet, [
                'avant' => ['projet' => $projet->id, 'slug_stocke' => $projet->slug],
                'apres' => [
                    'projet' => $projet->id,
                    'alias_slug' => $calcule,
                    'redirection' => "/abc/{$calcule} → /abc/{$projet->slug}",
                ],
            ]);
        }

        return ['actions' => $actions, 'conflits' => $conflits];
    }

    private function journaliser(string $action, Projet $projet, array $payload): void
    {
        AuditLog::create([
            'action' => $action,
            'avant' => json_encode($payload['avant'], JSON_UNESCAPED_UNICODE),
            'apres' => json_encode($payload['apres'], JSON_UNESCAPED_UNICODE),
            'par_admin' => 'abc:fix:slugs',
        ]);
    }
}
