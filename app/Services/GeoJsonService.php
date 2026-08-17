<?php

namespace App\Services;

use App\Models\Commune;

/**
 * Builder GeoJSON (port exact de src/geojson.ts).
 */
class GeoJsonService
{
    public function __construct(private StatusService $status) {}

    public function buildGeoJson(): array
    {
        $communes = Commune::query()
            ->join('projets as p', 'p.id', '=', 'communes.projet_id')
            ->leftJoin('verifications as v', 'v.projet_id', '=', 'p.id')
            ->select([
                'communes.*', 'p.nom', 'p.structure_porteuse', 'p.annee_debut',
                'p.annee_fin', 'p.statut', 'p.source',
                'p.potentiellement_termine', 'p.potentiellement_en_cours',
                'p.estime_termine',
                'v.etat as verif_etat', 'v.note as verif_note', 'v.lien as verif_lien',
            ])
            ->whereNotNull('communes.lon')
            ->whereNotNull('communes.lat')
            ->where(fn ($q) => $q->where('communes.lon', '!=', 0)->orWhere('communes.lat', '!=', 0))
            ->get();

        $features = $communes->map(function ($c) {
            $statutAffichage = $this->status->statutDepuisVerification($c->verif_etat) ?? $c->statut;

            return [
                'type' => 'Feature',
                'geometry' => ['type' => 'Point', 'coordinates' => [(float) $c->lon, (float) $c->lat]],
                'properties' => [
                    'projet_id' => $c->projet_id,
                    'nom' => $c->nom,
                    'structure_porteuse' => $c->structure_porteuse,
                    'annee_debut' => $c->annee_debut,
                    'annee_fin' => $c->annee_fin,
                    'statut' => $c->statut,
                    'statut_affichage' => $statutAffichage,
                    'categorie' => $this->status->statutLabel($statutAffichage),
                    'potentiellement_termine' => (bool) $c->potentiellement_termine,
                    'potentiellement_en_cours' => (bool) $c->potentiellement_en_cours,
                    'estime_termine' => (bool) $c->estime_termine,
                    'donnees_2022' => $c->source === 'wayback',
                    'verifie' => $c->verif_etat !== null && $c->verif_etat !== 'a_verifier',
                    'verif_etat' => $c->verif_etat,
                    'verif_note' => $c->verif_note,
                    'verif_lien' => $c->verif_lien,
                    'anomalie' => (bool) $c->anomalie,
                    'distance_km' => $c->distance_centre_km !== null ? (float) $c->distance_centre_km : null,
                    'commune' => $c->libelle_geographique,
                    'code_commune' => $c->code_geographique,
                    'departement' => $c->libelle_departement,
                    'region' => $c->region,
                    'source' => $c->source,
                ],
            ];
        });

        return ['type' => 'FeatureCollection', 'features' => $features->values()->all()];
    }
}
