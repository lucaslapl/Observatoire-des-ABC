<?php

namespace App\Services;

use App\Models\Projet;

/**
 * Les notes automatiques « Doublon suspect (Fonds vert P113 …) » sont
 * dérivables : le collecteur Fonds vert les recrée tant qu'une ligne de
 * financement correspond à plusieurs projets homonymes. Ce service fait
 * l'inverse : dédoublonne et retire les notes dont l'ambiguïté est
 * résolue (le nom cité ne correspond plus qu'à un seul projet), par
 * exemple après une fusion ou une suppression d'homonyme.
 */
class DoublonSuspectService
{
    public function __construct(private ProjectIdService $ids) {}

    /**
     * Dédoublonne les lignes d'une note automatique et retire les
     * « Doublon suspect » obsolètes : un projet ignoré (le doublon en
     * cours de fusion) ne compte pas dans l'ambiguïté.
     *
     * @param  array<int, string>  $ignorerIds  ids de projets à écarter du décompte (ex. doublon en cours de fusion)
     */
    public function nettoyer(?string $noteAuto, array $ignorerIds = []): ?string
    {
        if ($noteAuto === null || trim($noteAuto) === '') {
            return $noteAuto;
        }

        $lignes = array_values(array_unique(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $noteAuto) ?: [])
        )));

        $restantes = [];
        foreach ($lignes as $ligne) {
            if (! $this->ambiguiteResolue($ligne, $ignorerIds)) {
                $restantes[] = $ligne;
            }
        }

        $nettoyee = trim(implode("\n", $restantes));

        return $nettoyee === '' ? null : $nettoyee;
    }

    /**
     * Une ligne « Doublon suspect » est obsolète si le nom qu'elle cite
     * (« … ») ne correspond plus qu'à zéro ou un projet : l'ambiguïté a
     * été tranchée (fusion, suppression d'un homonyme), ou si ce nom est
     * générique — l'homonymie y est la norme et le collector ne crée plus
     * ces notes. Les autres lignes (notes humaines, autres motifs
     * automatiques) sont gardées.
     *
     * @param  array<int, string>  $ignorerIds
     */
    private function ambiguiteResolue(string $ligne, array $ignorerIds): bool
    {
        if (! str_contains($ligne, 'Doublon suspect')) {
            return false;
        }

        if (preg_match('/« ([^»]+) »/u', $ligne, $m) !== 1) {
            return false;
        }

        if ($this->ids->estNomGenerique($m[1])) {
            return true;
        }

        $slug = $this->ids->slug($m[1]);

        $homonymes = Projet::query()
            ->whereKeyNot($ignorerIds)
            ->get(['nom'])
            ->filter(fn (Projet $p) => $this->ids->slug((string) $p->nom) === $slug);

        return $homonymes->count() <= 1;
    }
}
