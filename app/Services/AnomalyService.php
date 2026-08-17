<?php

namespace App\Services;

use App\Models\Commune;

/**
 * Détection des communes incohérentes (anomalies). Port exact de
 * src/anomalies.ts : haversine, centroïde médian robuste aux outliers,
 * double passe, seuil 100 km, distance arrondie à 0.1 km.
 */
class AnomalyService
{
    public function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $R = 6371;
        $t = fn (float $x): float => ($x * M_PI) / 180;
        $a = sin($t($lat2 - $lat1) / 2) ** 2
            + cos($t($lat1)) * cos($t($lat2)) * sin($t($lon2 - $lon1) / 2) ** 2;

        return 2 * $R * asin(sqrt($a));
    }

    private function median(array $vals): float
    {
        sort($vals);
        $m = intdiv(count($vals), 2);

        return count($vals) % 2 ? $vals[$m] : ($vals[$m - 1] + $vals[$m]) / 2;
    }

    /**
     * Recalcule `anomalie` et `distance_centre_km` pour toutes les communes.
     *
     * @return int nombre d'anomalies détectées
     */
    public function computeAnomalies(): int
    {
        $rows = Commune::query()
            ->select(['projet_id', 'code_geographique', 'lon', 'lat'])
            ->whereNotNull('lon')
            ->whereNotNull('lat')
            ->get();

        $groupes = $rows->groupBy('projet_id');
        $anomalies = 0;
        $seuil = (float) config('abc.anomalie_km');

        foreach ($groupes as $pid => $cs) {
            if ($cs->count() < 2) {
                continue;
            }

            // Centroïde médian (robuste aux outliers).
            $clat = $this->median($cs->pluck('lat')->map(fn ($v) => (float) $v)->all());
            $clon = $this->median($cs->pluck('lon')->map(fn ($v) => (float) $v)->all());

            $flagged = [];
            for ($pass = 0; $pass < 2; $pass++) {
                $outliers = $cs->filter(
                    fn ($c) => ! isset($flagged[$c->code_geographique])
                        && $this->haversineKm((float) $c->lat, (float) $c->lon, $clat, $clon) > $seuil,
                );
                if ($outliers->count() === 0) {
                    break;
                }
                foreach ($outliers as $o) {
                    $flagged[$o->code_geographique] = true;
                }
                $ok = $cs->filter(fn ($c) => ! isset($flagged[$c->code_geographique]));
                if ($ok->count() > 0) {
                    $clat = $ok->avg('lat');
                    $clon = $ok->avg('lon');
                }
            }

            $ok = $cs->filter(fn ($c) => ! isset($flagged[$c->code_geographique]));
            if ($ok->count() > 0) {
                $clat = $ok->avg('lat');
                $clon = $ok->avg('lon');
            }

            foreach ($cs as $c) {
                $d = $this->haversineKm((float) $c->lat, (float) $c->lon, $clat, $clon);
                $isAnomalie = isset($flagged[$c->code_geographique]);
                Commune::where('projet_id', $c->projet_id)
                    ->where('code_geographique', $c->code_geographique)
                    ->update([
                        'anomalie' => $isAnomalie,
                        'distance_centre_km' => round($d * 10) / 10,
                    ]);
                if ($isAnomalie) {
                    $anomalies++;
                }
            }
        }

        return $anomalies;
    }
}
