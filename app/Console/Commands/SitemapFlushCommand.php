<?php

namespace App\Console\Commands;

use App\Services\SitemapService;
use Illuminate\Console\Command;

/**
 * Oublie toutes les clés de cache abc:sitemap* puis régénère le sitemap
 * (à jouer après chaque déploiement). Boucle de sécurité contre un cache
 * figé : l'entrée courante est versionnée (SitemapService::CACHE_KEY),
 * mais cette commande purge aussi les entrées héritées des versions
 * antérieures et réchauffe immédiatement la nouvelle — la prochaine
 * requête /sitemap.xml sert le sitemap neuf sans le reconstruire.
 *
 * Le même traitement est exposé au panneau d'administration (bouton
 * « Régénérer le sitemap », route /api/admin/sitemap/flush) pour les
 * hébergements sans accès shell.
 */
class SitemapFlushCommand extends Command
{
    protected $signature = 'abc:sitemap:flush';

    protected $description = 'Oublie le cache du sitemap puis le régénère (total et répartition par section)';

    public function handle(SitemapService $sitemap): int
    {
        $r = $sitemap->flush();

        $this->info('Clés abc:sitemap* oubliées, sitemap régénéré (clé : '.SitemapService::CACHE_KEY.').');
        $this->line("  total : {$r['total']} URL(s)");
        foreach ($r['sections'] as $section => $n) {
            $this->line('  '.str_pad($section, 16).$n);
        }

        return self::SUCCESS;
    }
}
