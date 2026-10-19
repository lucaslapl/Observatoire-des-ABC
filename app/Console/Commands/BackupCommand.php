<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'abc:backup';

    protected $description = 'Sauvegarde la base avec rotation (14) puis copie distante (BACKUP_UPLOAD_CMD si défini)';

    public function handle(BackupService $service): int
    {
        $r = $service->backupDb();
        $this->line("Sauvegarde : {$r['path']} ({$r['kept']} gardées)");

        [$rc, $out] = $service->upload($r['path']);
        if ($rc !== 0) {
            $this->error('Copie distante échouée : '.trim($out));

            return self::FAILURE;
        }
        if ($out !== '') {
            $this->line('Copie distante OK.');
        }

        return self::SUCCESS;
    }
}
