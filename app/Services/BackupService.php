<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Sauvegarde de la base avec rotation (port de src/backup.ts).
 */
class BackupService
{
    public function backupDb(): array
    {
        $dir = storage_path('app/abc/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return $this->backupSqlite($dir);
        }

        return $this->backupPostgres($dir);
    }

    private function backupPostgres(string $dir): array
    {
        $path = $dir.'/abc-'.date('Y-m-d-H-i-s').'.dump';
        $config = config('database.connections.pgsql');

        $host = $config['host'];
        $port = $config['port'];
        $name = $config['database'];
        $user = $config['username'];
        $pass = $config['password'];

        $cmd = sprintf(
            '%s -h %s -p %s -U %s -F c -b -f %s %s 2>&1',
            escapeshellarg((string) config('abc.backup_pg_dump_bin', 'pg_dump')),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($path),
            escapeshellarg($name),
        );

        [$rc, $out] = $this->run($cmd, ['PGPASSWORD' => $pass]);

        if ($rc !== 0) {
            $error = trim($out);

            // Dev local sous Windows : pg_dump est absent, on retente via le
            // conteneur Docker. Ailleurs (production, conteneur app), ce
            // fallback n'existe pas et masquerait la vraie erreur pg_dump.
            if (PHP_OS_FAMILY !== 'Windows') {
                throw new \RuntimeException('pg_dump a échoué : '.$error);
            }

            $cmd = sprintf(
                'docker exec -e PGPASSWORD=%s abc-postgis pg_dump -U %s -F c -b %s 2>&1',
                escapeshellarg($pass),
                escapeshellarg($user),
                escapeshellarg($name),
            );
            [$rc, $out] = $this->run($cmd);
            if ($rc !== 0) {
                throw new \RuntimeException(
                    "pg_dump a échoué : {$error}\nFallback Docker : ".trim($out)
                );
            }
            file_put_contents($path, $out);
        }

        $kept = $this->rotate($dir);

        return ['path' => $path, 'kept' => $kept];
    }

    /**
     * Exécute une commande en capturant stdout (proc_open : compatible Windows/cmd et Unix).
     *
     * @return array{0: int, 1: string} [code de sortie, sortie]
     */
    private function run(string $cmd, array $env = []): array
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env ?: null);
        if (! is_resource($proc)) {
            return [255, 'proc_open a échoué'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        return [$rc, trim($stdout).($stderr !== '' ? "\n".trim($stderr) : '')];
    }

    private function backupSqlite(string $dir): array
    {
        $path = $dir.'/abc-'.date('Y-m-d-H-i-s').'.db';
        $src = config('database.connections.sqlite.database');
        copy($src, $path);

        $kept = $this->rotate($dir);

        return ['path' => $path, 'kept' => $kept];
    }

    public function rotate(string $dir): int
    {
        $retention = (int) config('abc.backup_retention');
        $files = glob($dir.'/abc-*.{dump,db}', GLOB_BRACE) ?: [];
        sort($files);
        $toDelete = array_slice($files, 0, max(0, count($files) - $retention));
        foreach ($toDelete as $f) {
            unlink($f);
        }

        return count($files) - count($toDelete);
    }

    /**
     * Copie distante du dump (BACKUP_UPLOAD_CMD, `{file}` remplacé).
     * Un dump resté sur le même disque que la base n'est pas une sauvegarde.
     *
     * @return array{0: int, 1: string} [code de sortie, sortie]
     */
    public function upload(string $path): array
    {
        $cmd = (string) config('abc.backup_upload_cmd');
        if ($cmd === '') {
            return [0, ''];
        }

        return $this->run(str_replace('{file}', escapeshellarg($path), $cmd));
    }
}
