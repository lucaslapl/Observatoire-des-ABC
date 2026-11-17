<?php

namespace App\Console\Commands;

use App\Services\SlugHealthService;
use Illuminate\Console\Command;

/**
 * Réparation des slugs de fiches (audit SEO du 2026-11-17, C2) :
 *
 * - un projet sans slug en reçoit un dérivé de son nom (Str::slug,
 *   suffixe numérique en cas de collision) ;
 * - un slug existant n'est JAMAIS modifié en place (URL stable) : quand
 *   il diverge du slug calculé du nom — celui que les moteurs indexent —
 *   l'URL calculée devient un alias 301 vers la fiche ;
 * - chaque action est journalisée dans audit_log (slugs_fix:*,
 *   opérateur abc:fix:slugs) ; jamais de suppression ni d'écrasement.
 *
 * Lecture seule par défaut ; --apply écrit.
 */
class FixSlugsCommand extends Command
{
    protected $signature = 'abc:fix:slugs {--apply : écrit les réparations (sinon : dry-run, rien n\'est modifié)}';

    protected $description = 'Répare les slugs (réattribution aux projets sans slug, alias 301 des slugs calculés divergents — dry-run par défaut)';

    public function handle(SlugHealthService $slugs): int
    {
        $apply = (bool) $this->option('apply');

        $r = $slugs->fix($apply);

        foreach ($r['actions'] as $action) {
            $prefixe = ['slug_reattribue' => 'slug réattribué', 'alias_cree' => 'alias créé'][$action['type']] ?? $action['type'];
            $this->line('  ['.$prefixe."] {$action['projet_id']} « {$action['nom']} » — {$action['detail']}");
        }
        foreach ($r['conflits'] as $conflit) {
            $this->warn('  [conflit] '.$conflit);
        }

        $slugReat = count(array_filter($r['actions'], fn ($a) => $a['type'] === 'slug_reattribue'));
        $aliasCre = count(array_filter($r['actions'], fn ($a) => $a['type'] === 'alias_cree'));
        $conflits = count($r['conflits']) === 0
            ? ''
            : sprintf(' ; %d conflit(s) listé(s) ci-dessus, aucune écriture pour eux (arbitrage manuel)', count($r['conflits']));

        if ($r['actions'] === []) {
            $this->info('Rien à réparer'.$conflits.'.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s : %d slug(s) réattribué(s), %d alias 301 créé(s)%s.',
            $apply ? 'Réparations écrites et journalisées (audit_log, action slugs_fix:*)' : 'Dry-run — rien n\'a été écrit, relancer avec --apply',
            $slugReat,
            $aliasCre,
            $conflits,
        ));

        return self::SUCCESS;
    }
}
