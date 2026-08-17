<?php

namespace App\Services;

class CommuneCorrectionsService
{
    /**
     * Applique les corrections de communes (clé = code INSEE erroné).
     *
     * @param  array<string, mixed>  $commune
     * @return array<string, mixed>
     */
    public function corrigerCommune(array $commune): array
    {
        $fix = config("communes-corrections.{$commune['code_geographique']}");
        if (! $fix) {
            return $commune;
        }

        return array_merge($commune, $fix);
    }
}
