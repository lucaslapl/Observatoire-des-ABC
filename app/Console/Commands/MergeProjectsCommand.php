<?php

namespace App\Console\Commands;

use App\Services\ProjectMergeService;
use Illuminate\Console\Command;

/**
 * Fusionne deux projets dupliqués : toute la logique vit dans
 * ProjectMergeService (aussi pilotée depuis le panneau d'administration) ;
 * cette commande en est l'entrée CLI. Voir le service pour le détail
 * des garanties (alias, 301, sans perte, audit, snapshot de rollback).
 */
class MergeProjectsCommand extends Command
{
    protected $signature = 'abc:merge-projects
        {doublon : id du projet doublon (devient alias)}
        {canonique : id du projet canonique (conservé)}
        {--motif= : motif de la fusion (journalisé)}';

    protected $description = 'Fusionne un projet doublon dans un projet canonique (alias + union des données, sans perte)';

    public function handle(ProjectMergeService $service): int
    {
        /** @var string $doublonId */
        $doublonId = $this->argument('doublon');
        /** @var string $canoniqueId */
        $canoniqueId = $this->argument('canonique');
        /** @var string|null $motif */
        $motif = $this->option('motif');

        $result = $service->merge($doublonId, $canoniqueId, $motif, 'abc:merge-projects', 'abc:merge-projects');

        if (! $result['ok']) {
            $this->error($result['error'] ?? 'Fusion impossible.');

            return self::FAILURE;
        }

        $stats = $result['stats'];
        $this->info("Fusion {$doublonId} → {$canoniqueId} :");
        $this->line("  communes unies : +{$stats['communes_deplacees']}");
        $this->line("  snapshots unis : +{$stats['snapshots_deplaces']}");
        $this->line("  contributions migrées : {$stats['contributions_deplacees']}");
        $this->line("  financements migrés : {$stats['financements_deplaces']}");
        $this->line("  vérification : {$stats['verification']}");
        $this->line("  enrichissement : {$stats['enrichissement']}");
        $this->line("  rollback possible : {$stats['snapshot_rollback']}");

        return self::SUCCESS;
    }
}
