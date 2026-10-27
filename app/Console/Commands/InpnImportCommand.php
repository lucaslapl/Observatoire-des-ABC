<?php

namespace App\Console\Commands;

use App\Models\InpnImport;
use App\Services\InpnImportService;
use Illuminate\Console\Command;

/**
 * Import des référentiels INPN (data/inpn-data/) vers les tables inpn_*.
 * À lancer en local uniquement (Docker) : la production reçoit ensuite le
 * SQL portable généré par abc:export-inpn. Idempotent : chaque source est
 * rechargée intégralement.
 */
class InpnImportCommand extends Command
{
    protected $signature = 'abc:import-inpn
        {--source=all : znieff|natura|aires|all}
        {--dir= : répertoire des données INPN (défaut : config abc.inpn.dir)}';

    protected $description = 'Importe les référentiels INPN (ZNIEFF, Natura 2000, aires protégées)';

    public function handle(InpnImportService $inpn): int
    {
        $source = (string) $this->option('source');
        $dir = $this->option('dir') !== null ? (string) $this->option('dir') : null;

        if (! in_array($source, ['znieff', 'natura', 'aires', 'all'], true)) {
            $this->error("Source inconnue : {$source} (znieff|natura|aires|all)");

            return self::FAILURE;
        }

        if (in_array($source, ['znieff', 'all'], true)) {
            $this->importSource($inpn, 'znieff', $dir, fn () => $inpn->importZnieff(null, $dir));
        }

        if (in_array($source, ['natura', 'all'], true)) {
            $this->importSource($inpn, 'natura', $dir, fn () => $inpn->importNatura(null, $dir));
        }

        if (in_array($source, ['aires', 'all'], true)) {
            $this->importSource($inpn, 'aires', $dir, fn () => $inpn->importAiresProtegees(null, $dir));

            $nb = $inpn->jointureSpatialeAires($dir);
            if ($nb < 0) {
                $this->warn('  Jointure spatiale aires ↔ communes ignorée : requiert PostgreSQL + PostGIS');
                $this->warn('  et le GeoJSON Admin Express (communes) dans '.(string) config('abc.inpn.admin_express_dir'));
            } else {
                $this->line(sprintf('  jointure_communes : %s', number_format($nb)));
            }
        }

        $this->info('Import INPN terminé.');

        return self::SUCCESS;
    }

    /**
     * @param  callable(): array<string, int>  $run
     */
    private function importSource(InpnImportService $inpn, string $source, ?string $dir, callable $run): void
    {
        $this->line("{$source}…");
        $counts = $run();
        foreach ($counts as $label => $count) {
            $this->line(sprintf('  %s : %s', $label, number_format($count)));
        }

        $this->registerImport($source, $counts);
    }

    /**
     * Journalise la source (version de référence + volumes) dans
     * inpn_imports : c'est de là que sort l'affichage « données INPN —
     * base … » côté public.
     *
     * @param  array<string, int>  $counts
     */
    private function registerImport(string $source, array $counts): void
    {
        $nbEntites = $counts['zones'] ?? $counts['sites'] ?? $counts['aires'] ?? array_sum($counts);

        InpnImport::updateOrCreate(
            ['source' => $source],
            [
                'version_source' => (string) config("abc.inpn.versions.{$source}"),
                'nb_entites' => $nbEntites,
                'importe_le' => now(),
            ],
        );
    }
}
