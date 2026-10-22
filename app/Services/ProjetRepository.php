<?php

namespace App\Services;

use App\Models\Commune;
use App\Models\Contribution;
use App\Models\ProjectAlias;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use App\Models\Snapshot;
use App\Models\Verification;
use Illuminate\Support\Carbon;

/**
 * Upsert d'un projet + ses communes + snapshot (port de upsertProjet /
 * recordSnapshot côté Node).
 */
class ProjetRepository
{
    /** Ids exclus, chargés une fois par collect (mémoire). */
    private static ?array $excludedIds = null;

    /** Ids alias (projets fusionnés), chargés une fois par collect (mémoire). */
    private static ?array $aliasedIds = null;

    /** Ids vus dans les sources pendant le collect courant (mémoire). */
    private static array $ingestedIds = [];

    /** Ids créés pendant le collect courant (mémoire) : contrôle post-collect. */
    private static array $createdIds = [];

    /**
     * Démarre une nouvelle fenêtre de collect : vide les ids ingérés.
     * À appeler avant les collectors.
     */
    public static function resetIngested(): void
    {
        self::$ingestedIds = [];
        self::$createdIds = [];
    }

    /**
     * Ids créés depuis resetIngested() (contrôle post-collect : le pipeline
     * ne doit jamais créer en silence un doublon ou une masse de projets).
     *
     * @return array<int, string>
     */
    public static function createdIds(): array
    {
        return array_keys(self::$createdIds);
    }

    /**
     * Ids vus dans les sources depuis resetIngested().
     *
     * @return array<int, string>
     */
    public static function ingestedIds(): array
    {
        return array_keys(self::$ingestedIds);
    }

    /**
     * Marque un projet comme présent dans la source courante — même s'il
     * n'est pas ré-upserté (ex. wayback : apporte seulement les projets
     * inconnus, mais les projets existants restent vus dans la source).
     */
    public function markIngested(string $id): void
    {
        self::$ingestedIds[$id] = true;
    }

    public static function refreshExclusions(): void
    {
        self::$excludedIds = ProjectExclusion::query()->pluck('projet_id')->all();
        self::$aliasedIds = ProjectAlias::query()->pluck('alias_project_id')->all();
    }

    /**
     * Id d'un projet fusionné (alias) : il ne doit jamais être recréé par
     * un collect, sous peine d'annuler la fusion.
     */
    public function isAliased(string $id): bool
    {
        if (self::$aliasedIds === null) {
            $this->refreshExclusions();
        }

        return in_array($id, self::$aliasedIds ?? [], true);
    }

    public function isExcluded(string $id): bool
    {
        if (self::$excludedIds === null) {
            self::$excludedIds = ProjectExclusion::query()->pluck('projet_id')->all();
        }

        return in_array($id, self::$excludedIds, true);
    }

    public function upsertProjet(array $p): void
    {
        $this->markIngested($p['id']);

        if ($this->isExcluded($p['id'])) {
            return;
        }

        if ($this->isAliased($p['id'])) {
            return;
        }

        $data = [
            'nom' => $p['nom'],
            'structure_porteuse' => $p['structure_porteuse'] ?? null,
            'type_de_structure_porteuse' => $p['type_de_structure_porteuse'] ?? null,
            'annee_debut' => $p['annee_debut'] ?? null,
            'avancement_raw' => $p['avancement_raw'] ?? null,
            'statut' => $p['statut'],
            'ami_ofb' => $p['ami_ofb'] ?? null,
            'source' => $p['source'],
            'url_page' => $p['url_page'] ?? null,
        ];

        $projet = Projet::find($p['id']);
        if ($projet) {
            if ($this->hasConfirmedDates($p['id'])) {
                // Dates confirmées par un humain (contribution validée ou
                // verdict de vérification) : la source ne doit pas les
                // écraser. Une correction se fait par une nouvelle
                // contribution ou un nouveau verdict.
                unset($data['annee_debut']);
            }
            $projet->fill($data);
            $projet->save();
        } else {
            $projet = Projet::create(['id' => $p['id']] + $data);
            self::$createdIds[$p['id']] = true;
        }

        Commune::where('projet_id', $p['id'])->delete();
        if (! empty($p['communes'])) {
            $rows = [];
            $seen = [];
            foreach ($p['communes'] as $c) {
                $code = (string) $c['code_geographique'];
                if (isset($seen[$code])) {
                    continue; // équivalent d'INSERT OR IGNORE (1re occurrence conservée)
                }
                $seen[$code] = true;
                $rows[] = [
                    'projet_id' => $p['id'],
                    'code_geographique' => $c['code_geographique'],
                    'libelle_geographique' => $c['libelle_geographique'] ?? null,
                    'epci' => $c['epci'] ?? null,
                    'libelle_epci' => $c['libelle_epci'] ?? null,
                    'departement' => $c['departement'] ?? null,
                    'libelle_departement' => $c['libelle_departement'] ?? null,
                    'region' => $c['region'] ?? null,
                    'libelle_pnr' => $c['libelle_pnr'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            Commune::insert($rows);
        }
    }

    /**
     * Des dates ont été confirmées par un humain : soit une contribution de
     * dates (type date_debut) validée, soit un verdict de vérification
     * (« confirme_date » ou ligne « Dates confirmées : … » dans la note).
     * Ces valeurs humaines doivent survivre aux collects suivants. Le
     * retrait de la contribution (statut retiree) retire la protection
     * correspondante et rend la main à la source.
     */
    protected function hasConfirmedDates(string $projetId): bool
    {
        return Contribution::query()
            ->where('projet_id', $projetId)
            ->where('type', 'date_debut')
            ->where('statut', 'validee')
            ->exists()
            || Verification::query()
                ->where('projet_id', $projetId)
                ->where(fn ($q) => $q
                    ->where('etat', 'confirme_date')
                    ->orWhere('note', 'LIKE', '%Dates confirmées%'))
                ->exists();
    }

    public function recordSnapshot(string $snapshotDate, string $projetId, string $avancement, string $source): void
    {
        if ($this->isExcluded($projetId)) {
            return;
        }

        if ($this->isAliased($projetId)) {
            return;
        }

        // Date passée en Carbon : la clé de recherche doit se sérialiser
        // exactement comme la valeur stockée (SQLite compare les dates en
        // texte, '2026-10-22' ≠ '2026-10-22 00:00:00' — un re-collect le
        // même jour violait sinon la clé primaire composite).
        Snapshot::updateOrCreate(
            ['snapshot_date' => Carbon::parse($snapshotDate), 'projet_id' => $projetId],
            ['avancement' => $avancement, 'source' => $source],
        );
    }
}
