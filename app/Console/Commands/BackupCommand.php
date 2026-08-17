<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'abc:backup';

    protected $description = 'Sauvegarde la base avec rotation (14)';

    public function handle(BackupService $service): int
    {
        $r = $service->backupDb();
        $this->line("Sauvegarde : {$r['path']} ({$r['kept']} gardées)");

        return self::SUCCESS;
    }
}
