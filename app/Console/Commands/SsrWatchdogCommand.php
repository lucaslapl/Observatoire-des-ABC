<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SsrWatchdogCommand extends Command
{
    protected $signature = 'ssr:watchdog
        {--restart : Tue le processus SSR existant puis le relance (à utiliser après chaque déploiement)}';

    protected $description = 'Vérifie que le serveur SSR répond et le relance si besoin';

    public function handle(): int
    {
        $url = rtrim(config('inertia.ssr.url', 'http://127.0.0.1:13714'), '/');

        if ($this->option('restart')) {
            $this->stopProcess();

            return $this->startProcess();
        }

        if ($this->isHealthy($url)) {
            $this->info('SSR : serveur opérationnel.');

            return self::SUCCESS;
        }

        $this->warn('SSR : serveur injoignable, relance en cours…');

        return $this->startProcess();
    }

    private function isHealthy(string $url): bool
    {
        try {
            return Http::timeout(3)->get($url.'/health')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function startProcess(): int
    {
        $nodeBin = config('inertia.ssr.node_bin', 'node');
        $bundle = base_path('bootstrap/ssr/ssr.js');
        $pidFile = storage_path('app/ssr.pid');
        $logDir = storage_path('logs');
        $log = $logDir.'/ssr.log';

        $this->line('SSR : racine = '.base_path());

        if (! is_file($bundle)) {
            $this->error('Bundle SSR introuvable ('.$bundle.'). Lancez « npm run build ».');

            return self::FAILURE;
        }

        if (! is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        // Détache complètement le processus : stdin/err détournés vers le log,
        // stdin fermé (</dev/null) pour que la commande ne « bloque » jamais.
        $cmd = sprintf(
            'cd %s && nohup %s %s >> %s 2>&1 < /dev/null & echo $!',
            escapeshellarg(base_path()),
            escapeshellarg($nodeBin),
            escapeshellarg($bundle),
            escapeshellarg($log)
        );

        $output = [];
        exec($cmd, $output);
        $pid = trim((string) ($output[0] ?? ''));

        if ($pid !== '') {
            file_put_contents($pidFile, $pid);
        }

        $this->info('SSR : processus lancé (pid '.$pid.', log '.$log.').');

        // Lit brièvement la fin du log (lecture locale uniquement, jamais de
        // requête réseau) pour signaler un échec de démarrage immédiat.
        usleep(900000);

        if (is_file($log) && filesize($log) > 0) {
            $this->line('--- fin du log ---');
            $this->line(implode(PHP_EOL, array_slice(file($log), -12)));
            $this->line('--- fin du log ---');
        }

        return self::SUCCESS;
    }

    private function stopProcess(): void
    {
        $pidFile = storage_path('app/ssr.pid');

        if (! is_file($pidFile)) {
            $this->warn('SSR : aucun fichier PID, rien à arrêter.');

            return;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid > 0) {
            exec(sprintf('kill %d 2>/dev/null || true', $pid));
            $this->info('SSR : processus '.$pid.' arrêté.');
        }

        @unlink($pidFile);
    }
}
