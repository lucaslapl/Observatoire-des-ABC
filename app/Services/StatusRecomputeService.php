<?php

namespace App\Services;

use App\Models\Projet;
use App\Models\Snapshot;

/**
 * Recalcul des statuts agrégés et des flags (port exact de
 * `recomputeStatuses` dans src/collect.ts).
 */
class StatusRecomputeService
{
    public function recomputeStatuses(): array
    {
        $year = (int) date('Y');
        $anneeMin = (int) config('abc.annee_min');
        $dureeEstimeTermine = (int) config('abc.duree_estime_termine_ans');
        $dureeAbc = (int) config('abc.duree_abc_ans');

        $all = Projet::query()
            ->select(['id', 'statut', 'source', 'annee_debut',
                'potentiellement_termine', 'potentiellement_en_cours', 'estime_termine'])
            ->get();

        $snapAvancements = Snapshot::all()
            ->groupBy('projet_id')
            ->map(fn ($group) => $group->pluck('avancement')->filter()->values()->all())
            ->all();

        $changed = 0;
        $ptCount = 0;
        $pecCount = 0;
        $estimeCount = 0;
        $stale = 0;

        foreach ($all as $p) {
            $hist = $snapAvancements[$p->id] ?? [];
            $s = $p->statut;
            if (in_array('Fini', $hist, true)) {
                $s = 'termine';
            } elseif ($p->source === 'fondsvert-p113-2025') {
                $s = 'a_venir';
            }

            $anneeOk = $p->annee_debut !== null && $p->annee_debut >= $anneeMin;

            $pt = $s === 'en_cours' && $anneeOk && $p->annee_debut <= $year - $dureeAbc ? 1 : 0;
            if ($pt) {
                $ptCount++;
            }

            $pec = $s === 'a_venir' && $p->source !== 'wayback' && $anneeOk && $p->annee_debut <= $year - 2 ? 1 : 0;
            if ($pec) {
                $pecCount++;
            }

            $officiellementInconnu = $s === 'inconnu' || ($s === 'termine' && (bool) $p->estime_termine);
            $estime = $officiellementInconnu && $anneeOk && $p->annee_debut <= $year - $dureeEstimeTermine ? 1 : 0;
            if ($estime) {
                $s = 'termine';
                $estimeCount++;
            }

            if ($p->source === 'wayback') {
                $stale++;
            }

            if (
                $s !== $p->statut
                || $pt !== (int) $p->potentiellement_termine
                || $pec !== (int) $p->potentiellement_en_cours
                || $estime !== (int) $p->estime_termine
            ) {
                $p->statut = $s;
                $p->potentiellement_termine = $pt;
                $p->potentiellement_en_cours = $pec;
                $p->estime_termine = $estime;
                $p->save();
                $changed++;
            }
        }

        return [
            'changed' => $changed,
            'ptCount' => $ptCount,
            'pecCount' => $pecCount,
            'estimeCount' => $estimeCount,
            'stale' => $stale,
        ];
    }
}
