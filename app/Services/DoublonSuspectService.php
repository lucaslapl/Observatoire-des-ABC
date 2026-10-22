<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DoublonDecision;
use App\Models\Projet;
use App\Models\Verification;

/**
 * Les notes automatiques « Doublon suspect (Fonds vert P113 …) » sont
 * dérivables : le collecteur Fonds vert les recrée tant qu'une ligne de
 * financement correspond à plusieurs projets homonymes. Ce service fait
 * l'inverse : dédoublonne et retire les notes dont l'ambiguïté est
 * résolue (le nom cité ne correspond plus qu'à un seul projet), par
 * exemple après une fusion ou une suppression d'homonyme. Il signale
 * aussi les homonymies indépendamment du Fonds vert.
 */
class DoublonSuspectService
{
    public function __construct(private ProjectIdService $ids) {}

    /**
     * Signale les projets homonymes (même nom normalisé, projets distincts)
     * par une note automatique « Doublon suspect (homonymie) » : le bouton
     * « Fusionner ce doublon » de /verify s'appuie sur ces notes. Indépendant
     * du Fonds vert : deux éditions d'un même ABC (instantané wayback 2022 /
     * registre data.gouv) ne passent jamais par une ligne de financement.
     * Les noms génériques sont exclus — l'homonymie y est la norme —, les
     * groupes tranchés « non-doublon » (marquerNonDoublon) sont ignorés, et
     * les lignes des groupes fusionnés partent avec nettoyer().
     *
     * @return int nombre de projets signalés
     */
    public function signalerHomonymes(): int
    {
        $groupes = Projet::query()
            ->get(['id', 'nom'])
            ->groupBy(fn (Projet $p) => $this->ids->slug((string) $p->nom));

        $signales = 0;
        foreach ($groupes as $slug => $membres) {
            if ($membres->count() < 2
                || $this->ids->estNomGenerique((string) $membres->first()->nom)
                || $this->estNonDoublon((string) $slug)) {
                continue;
            }

            $ids = $membres->pluck('id')->sort()->values();
            $ligne = sprintf(
                'Doublon suspect (homonymie) : « %s » est porté par %d projets (%s) — éditions distinctes ou doublon, à fusionner si nécessaire.',
                (string) $membres->first()->nom,
                $ids->count(),
                $ids->implode(', '),
            );

            foreach ($membres as $projet) {
                $verification = Verification::find($projet->id);
                if (! $verification) {
                    Verification::create([
                        'projet_id' => $projet->id,
                        'etat' => 'a_verifier',
                        'note_auto' => $ligne,
                    ]);
                    $signales++;

                    continue;
                }

                $noteAuto = $this->remplacerLigneHomonymie($verification->note_auto, $ligne);
                if ($noteAuto !== $verification->note_auto) {
                    $verification->update(['note_auto' => $noteAuto]);
                }
                $signales++;
            }
        }

        return $signales;
    }

    /**
     * Un admin a tranché depuis /verify que les projets portant ce nom
     * (normalisé) sont des ABC distincts : le collecteur ne recrée pas
     * les notes « Doublon suspect » pour ce nom tant que la décision tient.
     */
    public function estNonDoublon(string $slug): bool
    {
        return DoublonDecision::query()
            ->where('nom_slug', $slug)
            ->where('decision', 'non_doublon')
            ->exists();
    }

    /**
     * Bouton « Ce n'est pas un doublon » de /verify : persiste la décision
     * humaine (elle prime sur le signalement automatique aux collects
     * suivants), retire les notes « Doublon suspect » de tous les homonymes
     * du groupe, et journalise le tranchage dans audit_log.
     *
     * @return array{ok: bool, error?: string, homonymes?: array<int, string>, notes_retirees?: int}
     */
    public function marquerNonDoublon(string $projetId, ?string $motif, ?string $parAdmin): array
    {
        $projet = Projet::find($projetId);
        if (! $projet) {
            return ['ok' => false, 'error' => "Projet introuvable : {$projetId}"];
        }

        $nom = (string) $projet->nom;
        if ($this->ids->estNomGenerique($nom)) {
            return ['ok' => false, 'error' => "« {$nom} » est un nom générique porté par des dizaines de communes : l'homonymie y est la norme, rien à trancher."];
        }

        $slug = $this->ids->slug($nom);
        $homonymes = Projet::query()
            ->get(['id', 'nom'])
            ->filter(fn (Projet $p) => $this->ids->slug((string) $p->nom) === $slug);
        $idsHomonymes = $homonymes->pluck('id')->sort()->values();

        DoublonDecision::updateOrCreate(
            ['nom_slug' => $slug],
            [
                'nom' => $nom,
                'decision' => 'non_doublon',
                'motif' => $motif,
                'par_admin' => $parAdmin,
            ],
        );

        $retirees = 0;
        Verification::query()
            ->whereNotNull('note_auto')
            ->whereIn('projet_id', $idsHomonymes)
            ->get()
            ->each(function (Verification $v) use (&$retirees): void {
                $nettoyee = $this->retirerLignesDoublon($v->note_auto);
                if ($nettoyee !== $v->note_auto) {
                    $v->update(['note_auto' => $nettoyee]);
                    $retirees++;
                }
            });

        AuditLog::create([
            'action' => 'doublon_non_confirme',
            'avant' => json_encode([
                'projet_id' => $projet->id,
                'nom' => $nom,
                'homonymes' => $idsHomonymes->all(),
            ], JSON_UNESCAPED_UNICODE),
            'apres' => json_encode([
                'projet_id' => $projet->id,
                'nom' => $nom,
                'decision' => 'non_doublon',
                'motif' => $motif,
                'notes_retirees' => $retirees,
            ], JSON_UNESCAPED_UNICODE),
            'par_admin' => $parAdmin,
        ]);

        return ['ok' => true, 'homonymes' => $idsHomonymes->all(), 'notes_retirees' => $retirees];
    }

    /**
     * Retire toutes les lignes « Doublon suspect » d'une note automatique,
     * sans toucher aux autres motifs ni aux notes humaines.
     */
    private function retirerLignesDoublon(?string $noteAuto): ?string
    {
        if ($noteAuto === null || trim($noteAuto) === '') {
            return $noteAuto;
        }

        $restantes = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $noteAuto) ?: [],
            fn (string $l) => ! str_contains($l, 'Doublon suspect'),
        ));

        $nettoyee = trim(implode("\n", $restantes));

        return $nettoyee === '' ? null : $nettoyee;
    }

    /**
     * Remplace la ligne « Doublon suspect (homonymie) » d'une note
     * automatique par sa version à jour (la composition du groupe peut
     * changer), sans toucher aux autres motifs.
     */
    private function remplacerLigneHomonymie(?string $noteAuto, string $ligne): string
    {
        $restantes = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', (string) $noteAuto) ?: [],
            fn (string $l) => ! str_contains($l, 'Doublon suspect (homonymie)'),
        ));
        $restantes[] = $ligne;

        return implode("\n", $restantes);
    }

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

        // Décision humaine « ce n'est pas un doublon » : la ligne est
        // obsolète quoi qu'il arrive, le collecteur ne la recréera pas.
        if ($this->estNonDoublon($slug)) {
            return true;
        }

        $homonymes = Projet::query()
            ->whereKeyNot($ignorerIds)
            ->get(['nom'])
            ->filter(fn (Projet $p) => $this->ids->slug((string) $p->nom) === $slug);

        return $homonymes->count() <= 1;
    }
}
