<?php

namespace App\Services;

class CommuneCorrectionsService
{
    /**
     * Applique les corrections de communes : d'abord les corrections ciblées
     * par projet (communes homonymes, clé = code INSEE erroné), puis les
     * corrections globales par code INSEE.
     *
     * @param  array<string, mixed>  $commune
     * @return array<string, mixed>
     */
    public function corrigerCommune(array $commune, ?string $projetId = null): array
    {
        $fix = null;
        if ($projetId !== null) {
            $fix = config("communes-corrections.par_projet.{$projetId}.{$commune['code_geographique']}");
        }
        $fix ??= config("communes-corrections.par_code.{$commune['code_geographique']}");

        if (! $fix) {
            return $commune;
        }

        return array_merge($commune, $fix);
    }
}
