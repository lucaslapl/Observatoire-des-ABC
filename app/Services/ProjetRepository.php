<?php

namespace App\Services;

use App\Models\Commune;
use App\Models\Projet;
use App\Models\Snapshot;

/**
 * Upsert d'un projet + ses communes + snapshot (port de upsertProjet /
 * recordSnapshot côté Node).
 */
class ProjetRepository
{
    public function upsertProjet(array $p): void
    {
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
            $projet->fill($data);
            $projet->save();
        } else {
            $projet = Projet::create(['id' => $p['id']] + $data);
        }

        Commune::where('projet_id', $p['id'])->delete();
        if (! empty($p['communes'])) {
            $rows = [];
            foreach ($p['communes'] as $c) {
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

    public function recordSnapshot(string $snapshotDate, string $projetId, string $avancement, string $source): void
    {
        Snapshot::updateOrCreate(
            ['snapshot_date' => $snapshotDate, 'projet_id' => $projetId],
            ['avancement' => $avancement, 'source' => $source],
        );
    }
}
