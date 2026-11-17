<?php

namespace App\Console\Commands;

use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Services\SlugHealthService;
use Illuminate\Console\Command;

/**
 * Diagnostic des slugs de fiches (lecture seule) : à jouer après chaque
 * déploiement (audit SEO du 2026-11-17, C2 — fiches référencées en 404
 * alors que le projet existe).
 */
class DiagnosticSlugsCommand extends Command
{
    protected $signature = 'abc:diagnostic:slugs';

    protected $description = 'Liste les anomalies de slugs (sans slug, divergents, aliases cassés ou en conflit)';

    public function handle(SlugHealthService $slugs): int
    {
        $anomalies = 0;

        $sansSlug = $slugs->sansSlugAvecCommunes();
        $sansSlugTous = $slugs->sansSlug();
        $this->info("[1/4] Projets sans slug — {$sansSlugTous->count()} au total, dont {$sansSlug->count()} avec au moins une commune (fiche inaccessible) :");
        foreach ($sansSlug as $projet) {
            $this->line('  - '.$this->signatureProjet($projet));
            $anomalies++;
        }

        $divergents = $slugs->divergents();
        $suffixees = $divergents->where('suffixe', true)->count();
        $this->info(sprintf('[2/4] Slugs divergeant du slug calculé du nom — %d (dont %d collision suffixée d\'homonymes, cas sain) :', $divergents->count(), $suffixees));
        foreach ($divergents as $d) {
            /** @var Projet $projet */
            $projet = $d['projet'];
            $marque = $d['suffixe'] ? ' [collision suffixée]' : '';
            $this->line('  - '.$this->signatureProjet($projet)." slug actuel « {$projet->slug} » → slug calculé « {$d['calcule']} »{$marque}");
            if (! $d['suffixe']) {
                $anomalies++;
            }
        }

        $cassees = $slugs->aliasesCassees();
        $this->info("[3/4] Aliases cassés (cible disparue ou sans slug) — {$cassees->count()} :");
        foreach ($cassees as $cassee) {
            $this->line('  - '.$this->signatureAlias($cassee['alias'])." : {$cassee['motif']}");
            $anomalies++;
        }

        $conflits = $slugs->aliasesEnConflit();
        $this->info("[4/4] Aliases en double ou conflit — {$conflits->count()} :");
        foreach ($conflits as $conflit) {
            $this->line('  - '.$this->signatureAlias($conflit['alias'])." : {$conflit['motif']}");
            $anomalies++;
        }

        if ($anomalies === 0) {
            $this->info('Aucune anomalie de slug : chaque projet a une fiche accessible et chaque alias redirige vers une fiche.');

            return self::SUCCESS;
        }

        $this->warn("{$anomalies} anomalie(s) : voir le plan de réparation avec « abc:fix:slugs » (lecture seule par défaut, --apply pour l'écrire).");

        return self::SUCCESS;
    }

    private function signatureProjet(Projet $projet): string
    {
        $dates = $projet->created_at?->format('Y-m-d').' → '.$projet->updated_at?->format('Y-m-d');

        return "{$projet->id} « {$projet->nom} » ({$projet->source}, {$dates})";
    }

    private function signatureAlias(ProjectAlias $alias): string
    {
        return "alias « {$alias->alias_slug} » → projet {$alias->project_id}".($alias->created_by ? " ({$alias->created_by})" : '');
    }
}
