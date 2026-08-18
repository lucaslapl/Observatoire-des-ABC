<?php

namespace App\Services;

use App\Models\Actualite;
use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LandingService
{
    public function __construct(private RegionService $regions) {}

    public const STATUTS =
        ['a_venir' => 'Va débuter', 'en_cours' => 'En cours', 'termine' => 'Terminé', 'inconnu' => 'Statut inconnu'];

    public const SOURCES =
        ['data.gouv' => 'Registre OFB', 'wayback' => 'Registre OFB (archives 2022)', 'fondsvert-p113-2024' => 'Fonds vert 2024', 'fondsvert-p113-2025' => 'Fonds vert 2025'];

    public function statutLabel(?string $statut): string
    {
        return self::STATUTS[$statut] ?? (! $statut ? 'Statut inconnu' : Str::headline($statut));
    }

    public function sourceLabel(?string $source): string
    {
        return self::SOURCES[$source] ?? ($source ?: '—');
    }

    public function regionLabel(?string $value): ?string
    {
        return $this->regions->normalizeToLabel($value);
    }

    public function regionSlug(?string $value): ?string
    {
        $label = $this->regionLabel($value);

        return $label ? $this->regions->slug($label) : null;
    }

    /**
     * Fiche projet (page /abc/{slug}).
     */
    public function projet(Projet $projet): array
    {
        $projet->loadMissing(['communes' => fn ($q) => $q->orderBy('libelle_geographique')], 'enrichissement', 'verification');

        $communes = $projet->communes
            ->map(fn (Commune $c) => $this->communeListing($c, includeProjet: false))
            ->values()
            ->all();

        $regionLabel = $projet->communes
            ->map(fn ($c) => $this->regionLabel($c->region))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        $verification = $projet->verification
            ? [
                'etat' => $projet->verification->etat,
                'label' => $this->verdictLabel($projet->verification->etat),
                'note' => $projet->verification->note,
                'lien' => $projet->verification->lien,
                'verifieLe' => $projet->verification->verifie_le?->toDateTimeString(),
            ]
            : null;

        return [
            'id' => $projet->id,
            'nom' => $projet->nom,
            'slug' => $projet->slug,
            'structure_porteuse' => $projet->structure_porteuse,
            'type_de_structure_porteuse' => $projet->type_de_structure_porteuse,
            'annee_debut' => $projet->annee_debut,
            'annee_fin' => $projet->annee_fin,
            'statut' => $projet->statut,
            'statut_label' => $this->statutLabel($projet->statut),
            'source' => $projet->source,
            'source_label' => $this->sourceLabel($projet->source),
            'url_page' => $projet->url_page,
            'ami_ofb' => $projet->ami_ofb,
            'potentiellement_termine' => (bool) $projet->potentiellement_termine,
            'potentiellement_en_cours' => (bool) $projet->potentiellement_en_cours,
            'estime_termine' => (bool) $projet->estime_termine,
            'donnees_2022' => $projet->source === 'wayback',
            'description' => $projet->enrichissement?->description,
            'verification' => $verification,
            'communes' => $communes,
            'communes_anomalies' => $projet->communes->where('anomalie', true)->count(),
            'region_label' => $regionLabel,
            'region_slug' => $regionLabel ? $this->regions->slug($regionLabel) : null,
        ];
    }

    /**
     * Page commune (/commune/{code_geographique}).
     */
    public function commune(string $code): array
    {
        $rows = Commune::query()
            ->where('code_geographique', $code)
            ->where('anomalie', false)
            ->get();

        if ($rows->isEmpty()) {
            $any = Commune::where('code_geographique', $code)->exists();
            if (! $any) {
                abort(404);
            }
            $rows = Commune::where('code_geographique', $code)->get();
        }

        $libelle = $this->firstNonEmpty($rows, 'libelle_geographique');
        $departementCode = $this->firstNonEmpty($rows, 'departement');
        $departementLabel = $this->firstNonEmpty($rows, 'libelle_departement');
        $regionLabel = $rows->map(fn ($c) => $this->regionLabel($c->region))->filter()->first();

        $epcis = Commune::query()
            ->where('code_geographique', $code)
            ->whereNotNull('libelle_epci')
            ->distinct()
            ->orderBy('libelle_epci')
            ->pluck('libelle_epci')
            ->take(5)
            ->all();

        $projets = $rows
            ->groupBy('projet_id')
            ->map(function (Collection $communes) {
                /** @var Commune $first */
                $first = $communes->first();
                $p = $first->projet;

                return [
                    'projet_id' => $first->projet_id,
                    'nom' => $p?->nom,
                    'slug' => $p?->slug,
                    'statut' => $p?->statut,
                    'statut_label' => $p ? $this->statutLabel($p->statut) : null,
                    'annee_debut' => $p?->annee_debut,
                    'source_label' => $p ? $this->sourceLabel($p->source) : null,
                    'anomalie' => (bool) $communes->where('anomalie', true)->count(),
                ];
            })
            ->values()
            ->all();

        return [
            'code' => $code,
            'libelle' => $libelle,
            'departement' => ['code' => $departementCode ? mb_strtolower((string) $departementCode) : null, 'label' => $departementLabel],
            'region' => ['label' => $regionLabel, 'slug' => $regionLabel ? $this->regions->slug($regionLabel) : null],
            'epcis' => $epcis,
            'n_projets' => count($projets),
            'projets' => $projets,
        ];
    }

    /**
     * Page département (/departement/{code}).
     */
    public function departement(string $code): array
    {
        $norm = mb_strtolower($code);
        $rows = Commune::query()
            ->whereRaw('LOWER(departement) = ?', [$norm])
            ->get();

        if ($rows->isEmpty()) {
            abort(404);
        }

        $label = $this->firstNonEmpty($rows, 'libelle_departement');
        $regionLabels = $rows->map(fn ($c) => $this->regionLabel($c->region))->filter()->unique()->sort()->values();

        $communes = $rows
            ->filter(fn ($c) => $c->anomalie === false)
            ->groupBy('code_geographique')
            ->map(function (Collection $cs) {
                $first = $cs->first();

                return [
                    'code' => $first->code_geographique,
                    'libelle' => $first->libelle_geographique ?: $first->code_geographique,
                    'n' => $cs->pluck('projet_id')->unique()->count(),
                ];
            })
            ->values()
            ->all();

        $projets = $rows
            ->filter(fn ($c) => $c->anomalie === false)
            ->groupBy('projet_id')
            ->map(function (Collection $cs) {
                $first = $cs->first();
                $p = $first->projet;

                return [
                    'projet_id' => $first->projet_id,
                    'nom' => $p?->nom,
                    'slug' => $p?->slug,
                    'statut_label' => $p ? $this->statutLabel($p->statut) : null,
                    'statut' => $p?->statut,
                    'annee_debut' => $p?->annee_debut,
                    'commune_principale' => $first->libelle_geographique,
                    'source_label' => $p ? $this->sourceLabel($p->source) : null,
                ];
            })
            ->values()
            ->all();

        usort($projets, fn ($a, $b) => strcmp((string) $a['nom'], (string) $b['nom']));

        return [
            'code' => $norm,
            'label' => $label ?: $norm,
            'regions' => $regionLabels->map(fn ($l) => ['label' => $l, 'slug' => $this->regions->slug($l)])->values()->all(),
            'n_communes' => count($communes),
            'n_projets' => count($projets),
            'communes' => $communes,
            'projets' => $projets,
        ];
    }

    /**
     * Page région (/region/{slug}).
     */
    public function region(string $slug): array
    {
        $label = $this->regions->resolveBySlug($slug);
        if (! $label) {
            abort(404);
        }

        $values = Commune::query()
            ->whereNotNull('region')
            ->distinct()
            ->pluck('region')
            ->filter(fn ($v) => $this->regionLabel($v) === $label)
            ->all();

        $rows = Commune::query()
            ->whereIn('region', $values)
            ->whereNotNull('departement')
            ->get();

        $departements = $rows
            ->filter(fn ($c) => $c->anomalie === false)
            ->groupBy(fn ($c) => mb_strtolower((string) $c->departement))
            ->map(function (Collection $cs) {
                $first = $cs->first();

                return [
                    'code' => mb_strtolower((string) $first->departement),
                    'label' => $first->libelle_departement ?: (string) $first->departement,
                    'n' => $cs->pluck('projet_id')->unique()->count(),
                ];
            })
            ->values()
            ->all();

        usort($departements, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));

        return [
            'label' => $label,
            'slug' => $slug,
            'n_departements' => count($departements),
            'n_projets' => $rows->where('anomalie', false)->pluck('projet_id')->unique()->count(),
            'n_communes' => $rows->where('anomalie', false)->pluck('code_geographique')->unique()->count(),
            'departements' => $departements,
        ];
    }

    /**
     * Article d'actualité publié.
     */
    public function actualite(Actualite $actualite): array
    {
        if ($actualite->statut !== 'publie'
            || ($actualite->date_publication && $actualite->date_publication->gt(now()))) {
            abort(404);
        }

        return [
            'id' => $actualite->id,
            'titre' => $actualite->titre,
            'slug' => $actualite->slug,
            'contenu' => $actualite->contenu,
            'date_publication' => $actualite->date_publication?->toDateString(),
            'auteur' => $actualite->auteur?->name,
        ];
    }

    private function firstNonEmpty(Collection $rows, string $field): ?string
    {
        foreach ($rows as $row) {
            if (! empty($row->{$field})) {
                return (string) $row->{$field};
            }
        }

        return null;
    }

    private function verdictLabel(string $etat): string
    {
        return [
            'confirme_termine' => '✓ Vérifié : Terminé',
            'confirme_en_cours' => '✓ Vérifié : En cours',
            'toujours_a_venir' => '✓ Vérifié : Va débuter',
            'confirme_date' => '✓ Vérifié : Date confirmée',
            'introuvable' => '⚠ Vérifié : introuvable',
            'douteux' => '⚠ Vérifié : incertain',
        ][$etat] ?? 'À vérifier';
    }

    private function communeListing(Commune $c, bool $includeProjet): array
    {
        $region = $this->regionLabel($c->region);

        return [
            'code' => $c->code_geographique,
            'libelle' => $c->libelle_geographique ?: $c->code_geographique,
            'departement_code' => $c->departement ? mb_strtolower((string) $c->departement) : null,
            'departement_label' => $c->libelle_departement,
            'region_label' => $region,
            'region_slug' => $region ? $this->regions->slug($region) : null,
            'anomalie' => (bool) $c->anomalie,
        ];
    }
}
