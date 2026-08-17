<?php

namespace App\Console\Commands;

use App\Models\Commune;
use App\Models\Projet;
use App\Services\StatusService;
use Illuminate\Console\Command;

class VerifyCommand extends Command
{
    protected $signature = 'abc:verify';

    protected $description = 'Génère la worklist de vérification (CSV)';

    public function handle(): int
    {
        $rows = Projet::query()
            ->select([
                'projets.id', 'projets.nom', 'projets.structure_porteuse',
                'projets.annee_debut', 'projets.statut', 'projets.source',
                'projets.potentiellement_termine', 'projets.potentiellement_en_cours',
            ])
            ->where('projets.potentiellement_termine', true)
            ->orWhere('projets.potentiellement_en_cours', true)
            ->orWhere('projets.source', 'wayback')
            ->orWhereNull('projets.annee_debut')
            ->orderBy('projets.statut')
            ->orderBy('projets.nom')
            ->get();

        $ids = $rows->pluck('id');
        $communes = Commune::whereIn('projet_id', $ids)
            ->whereNotNull('libelle_geographique')
            ->get(['projet_id', 'libelle_geographique', 'libelle_departement']);
        $grouped = $communes->groupBy('projet_id');

        $dir = config('abc.export_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $header = "nom;structure_porteuse;communes;annee_debut;statut_a_verifier;motif;requete_recherche\n";
        $lines = [];
        $status = new StatusService;

        foreach ($rows as $p) {
            $list = $grouped->get($p->id, collect());
            $communesStr = $list->pluck('libelle_geographique')->implode(', ');

            $motifs = [];
            if ($p->potentiellement_termine) {
                $motifs[] = 'début '.($p->annee_debut ?? '?').' → potentiellement terminé';
            }
            if ($p->potentiellement_en_cours) {
                $motifs[] = '« va débuter » depuis '.($p->annee_debut ?? '?').' → potentiellement en cours';
            }
            if ($p->source === 'wayback') {
                $motifs[] = 'statut figé à 2022 (archives)';
            }
            if ($p->annee_debut === null) {
                $motifs[] = 'date début inconnue';
            }
            $place = trim(preg_replace('/ABC\s*/i', '', (string) ($p->structure_porteuse ?? $p->nom)));
            $q = trim('"atlas de la biodiversité communale" "'.$place.'" '.trim(explode(',', (string) $communesStr)[0] ?? ''));

            $lines[] = str_replace("\n", ' ', implode(';', [
                $p->nom,
                $p->structure_porteuse ?? '',
                $communesStr,
                $p->annee_debut ?? '',
                $status->statutLabel($p->statut),
                implode(' + ', $motifs),
                $q,
            ]));
        }

        $path = $dir.'/verification-worklist.csv';
        file_put_contents($path, $header.implode("\n", $lines)."\n");
        $this->line("Worklist de vérification : {$path} (".count($rows).' projets à vérifier)');

        return self::SUCCESS;
    }
}
