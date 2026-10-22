<?php

namespace App\Services;

use App\Collectors\DatagouvCollector;
use App\Collectors\FondsVertCollector;
use App\Collectors\WaybackCollector;
use App\Models\AuditLog;
use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Financement;
use App\Models\Projet;
use App\Models\Verification;
use RuntimeException;

/**
 * Orchestration du collect complet (port de src/collect.ts).
 *
 * Stratégie de synchronisation (v2.1) : les sources sont ré-ingérées par
 * upsert, et chaque projet vu dans une source est enregistré
 * (ProjetRepository::markIngested). Ne sont supprimés que les projets
 * ABSENTS des sources ET sans travail humain. Ne jamais se fier à
 * updated_at : un upsert sans changement de données ne le rafraîchit pas.
 */
class CollectService
{
    public function __construct(
        private DatagouvCollector $datagouv,
        private WaybackCollector $wayback,
        private FondsVertCollector $fondsVert,
        private StatusRecomputeService $recompute,
        private GeocodeService $geocode,
        private AnomalyService $anomalies,
    ) {}

    public function collectAll(bool $init = false, bool $force = false): array
    {
        ProjetRepository::refreshExclusions();
        ProjetRepository::resetIngested();

        $projetsAvant = Projet::count();

        $datagouv = $this->datagouv->collect();
        $wayback = $this->wayback->collect();
        $fondsVert = $this->fondsVert->collect();

        // Contrôle post-collect : le pipeline ne crée jamais en silence.
        // Bloqué AVANT la suppression ciblée et les recalculs : rien ne doit
        // être détruit sur la foi d'un collect suspect.
        $this->verifierCoherence($projetsAvant, ProjetRepository::createdIds());

        $stale = $this->deleteStaleProjects(ProjetRepository::ingestedIds(), $force);

        $this->recompute->recomputeStatuses();
        $geocoding = $this->geocode->enrichGeocoding();
        $anomalies = $this->anomalies->computeAnomalies();

        $total = Projet::count();
        $communes = Commune::count();
        $geocoded = Commune::whereNotNull('lon')->whereNotNull('lat')
            ->where(fn ($q) => $q->where('lon', '!=', 0)->orWhere('lat', '!=', 0))
            ->count();
        $statuses = Projet::selectRaw('statut, COUNT(*) AS n')
            ->groupBy('statut')
            ->orderByDesc('n')
            ->get()
            ->pluck('n', 'statut')
            ->all();

        return [
            'total' => $total,
            'communes' => $communes,
            'geocoded' => $geocoded,
            'stale_deleted' => $stale,
            'anomalies' => $anomalies,
            'statuses' => $statuses,
            'geocoding' => $geocoding,
            'sources' => [
                'datagouv' => ['projets' => $datagouv[0], 'communes' => $datagouv[1]],
                'wayback' => ['projets' => $wayback[0], 'communes' => $wayback[1]],
                'fondsvert' => $fondsVert,
            ],
        ];
    }

    /**
     * Supprime les projets absents des sources (ids non ingérés), SAUF ceux
     * qui portent du travail humain (verifications, contributions) : le
     * delete en cascade emporterait ce travail. Les communes et snapshots
     * des projets supprimés partent en cascade.
     *
     * Garde-fous :
     * - ids ingérés vides → erreur (sources indisponibles) ;
     * - plus de la moitié de la base concernée → erreur, sauf $force :
     *   une chute massive d'une source ne doit jamais vider la base.
     *
     * @param  array<int, string>  $ingestedIds
     * @return int nombre de projets supprimés
     */
    public function deleteStaleProjects(array $ingestedIds, bool $force = false): int
    {
        if (empty($ingestedIds)) {
            throw new RuntimeException(
                'Aucun projet ingéré depuis les sources : suppression annulée (source indisponible ?).'
            );
        }

        $candidates = Projet::query()
            ->whereNotIn('id', $ingestedIds)
            ->whereNotIn('id', Verification::select('projet_id'))
            ->whereNotIn('id', Contribution::select('projet_id'))
            // Un projet financé (Fonds vert) ne disparaît pas avec la ligne
            // de financement : le rattachement est conservé.
            ->whereNotIn('id', Financement::select('project_id'))
            ->pluck('id');

        if ($candidates->isEmpty()) {
            return 0;
        }

        $existing = Projet::count();
        if (! $force && $candidates->count() > $existing / 2) {
            throw new RuntimeException(sprintf(
                'Suppression annulée : %d projets sur %d seraient supprimés (une source a-t-elle échoué ?).'.
                ' Relancez avec --force si cette chute est réellement voulue.',
                $candidates->count(),
                $existing,
            ));
        }

        Projet::whereIn('id', $candidates)->delete();

        return $candidates->count();
    }

    /**
     * Contrôle post-collect (audit du 2026-10-21, phase 4) : un collect ne
     * doit jamais insérer en silence des projets suspects. Deux alertes
     * bloquantes, journalisées dans audit_log (action alerte_collect) :
     *
     * - plus de 5 % de créations nettes par rapport à l'état avant collect
     *   (une source qui triple subitement le registre a un problème) ;
     *   ignoré sur base vide : un import initial crée nécessairement tout ;
     * - un nouveau projet partageant un nom normalisé avec un projet
     *   EXISTANT AVANT le collect (doublon probable : édition distincte à
     *   confirmer, ou projet scindé — jamais inséré à l'aveugle). Les
     *   doublons inter-sources créés au sein du même collect relèvent du
     *   triage de fusion, pas de cette alerte.
     *
     * L'exception interrompt le collect avant toute suppression ciblée :
     * les projets incriminés restent en base, l'alerte les liste, et
     * l'opérateur décide (restauration depuis la sauvegarde, fusion,
     * exclusion ou correction de la source).
     *
     * @param  array<int, string>  $creees
     */
    private function verifierCoherence(int $projetsAvant, array $creees): void
    {
        if ($creees === []) {
            return;
        }

        $ids = app(ProjectIdService::class);
        $alertes = [];

        if ($projetsAvant > 0) {
            $seuil = max(1, (int) ceil($projetsAvant * 0.05));
            if (count($creees) > $seuil) {
                $alertes[] = sprintf(
                    '%d créations nettes (> %d, seuil de 5 %% de %d projets) : une source a-t-elle changé de format ?',
                    count($creees),
                    $seuil,
                    $projetsAvant
                );
            }
        }

        // Index des noms normalisés des projets EXISTANTS avant ce collect :
        // un nouveau projet qui partage le nom d'un ancien est un doublon
        // probable.
        $existants = Projet::query()
            ->whereNotIn('id', $creees)
            ->pluck('nom', 'id')
            ->groupBy(fn ($nom) => $ids->slug((string) $nom));
        foreach ($creees as $id) {
            $projet = Projet::find($id);
            if (! $projet) {
                continue; // déjà supprimé ou fusionné entre-temps
            }
            $freres = ($existants->get($ids->slug((string) $projet->nom)) ?? collect())->keys();
            if ($freres->isNotEmpty()) {
                $alertes[] = "« {$projet->nom} » ({$id}) partage son nom normalisé avec : ".$freres->implode(', ');
            }
        }

        if ($alertes === []) {
            return;
        }

        AuditLog::create([
            'action' => 'alerte_collect',
            'avant' => json_encode(['projets_avant' => $projetsAvant], JSON_UNESCAPED_UNICODE),
            'apres' => json_encode([
                'crees' => $creees,
                'alertes' => $alertes,
            ], JSON_UNESCAPED_UNICODE),
            'par_admin' => 'CollectService',
        ]);

        throw new RuntimeException(
            "COLLECT SUSPECT — aucune donnée n'est supprimée, le collect est interrompu :\n- ".
            implode("\n- ", $alertes).
            "\nLes projets listés sont en base : examiner, puis restaurer depuis la sauvegarde, fusionner, exclure ou corriger la source."
        );
    }
}
