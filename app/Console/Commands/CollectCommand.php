<?php

namespace App\Console\Commands;

use App\Services\CollectService;
use Illuminate\Console\Command;

class CollectCommand extends Command
{
    protected $signature = 'abc:collect';

    protected $description = 'Collecte des 4 sources (registre, wayback, fonds vert) puis statuts, géocodage et anomalies';

    public function handle(CollectService $service): int
    {
        $this->line('Collect complet en cours…');
        $service->collectAll();

        return self::SUCCESS;
    }
}
