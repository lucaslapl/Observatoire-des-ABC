<?php

namespace App\Services;

use App\Models\Projet;

/**
 * Exports CSV (1 ligne/projet avec `note`) et GeoJSON (1 point/commune).
 */
class ExportService
{
    public function __construct(
        private GeoJsonService $geoJson,
        private StatusService $status,
    ) {}

    public function export(string $fmt): string
    {
        if ($fmt === 'csv') {
            return $this->exportCsv();
        }
        if ($fmt === 'geojson') {
            return $this->exportGeoJson();
        }

        throw new \InvalidArgumentException("Format inconnu : {$fmt}");
    }

    private function exportCsv(): string
    {
        $projects = Projet::query()
            ->select('projets.*')
            ->selectRaw('(SELECT COUNT(*) FROM communes c WHERE c.projet_id = projets.id) AS nb_communes')
            ->selectRaw('(SELECT libelle_departement FROM communes c WHERE c.projet_id = projets.id AND c.libelle_departement IS NOT NULL LIMIT 1) AS departement')
            ->selectRaw('(SELECT region FROM communes c WHERE c.projet_id = projets.id AND c.region IS NOT NULL LIMIT 1) AS region')
            ->get();

        $header = "id;nom;structure_porteuse;type_de_structure_porteuse;annee_debut;statut;categorie;note;nb_communes;departement;region;source;ami_ofb;url_page\n";

        $lines = [];
        foreach ($projects as $p) {
            $notes = [];
            if ($p->potentiellement_termine) {
                $notes[] = 'Potentiellement terminé (début '.($p->annee_debut ?? '?').', durée ABC ~3 ans)';
            }
            if ($p->potentiellement_en_cours) {
                $notes[] = 'Potentiellement en cours (début annoncé '.($p->annee_debut ?? '?').', encore « va débuter »)';
            }
            if ($p->source === 'wayback') {
                $notes[] = 'Statut issu des archives 2022, à vérifier';
            }
            if ($p->estime_termine) {
                $notes[] = 'Terminé (estimation) : statut officiel inconnu, projet débuté en '.($p->annee_debut ?? '?').' (> 5 ans)';
            }

            $lines[] = str_replace("\n", ' ', implode(';', [
                $p->id,
                $p->nom,
                $p->structure_porteuse ?? '',
                $p->type_de_structure_porteuse ?? '',
                $p->annee_debut ?? '',
                $p->statut,
                $this->status->statutLabel($p->statut),
                implode(' ; ', $notes),
                $p->nb_communes,
                $p->departement ?? '',
                $p->region ?? '',
                $p->source ?? '',
                $p->ami_ofb ? 'Oui' : 'Non',
                $p->url_page ?? '',
            ]));
        }

        return $header.implode("\n", $lines)."\n";
    }

    private function exportGeoJson(): string
    {
        return json_encode($this->geoJson->buildGeoJson(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
