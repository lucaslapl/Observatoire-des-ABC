<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Sauvegarde deux fois par jour (04:00 et 16:00) : la fenêtre de perte
//     maximale est réduite à ~12h de contributions. Rotation : les 14 plus
//     récentes sont conservées, les plus anciennes supprimées (7 jours). ---
// runInBackground : le cron du scheduler n'attend pas la fin du dump ;
// withoutOverlapping/onOneServer : une seule exécution à la fois.
Schedule::command('abc:backup')->twiceDaily(4, 16)
    ->runInBackground()
    ->withoutOverlapping()
    ->onOneServer();

// --- Watchdog SSR : relance le serveur Node d'Inertia s'il ne répond plus.
//     (Alternative à Supervisord, adaptée aux hébergements mutualisés.) ---
Schedule::command('ssr:watchdog')->everyMinute();

// --- Collect mensuel (désactivable en prod : COLLECT_AUTOMATIC=false).
//     Synchronisation par upsert + suppression ciblée : aucun superuser requis. ---
if (config('abc.collect_automatic')) {
    Schedule::command('abc:collect')->monthlyOn(1, '03:00')
        ->runInBackground()
        ->withoutOverlapping()
        ->onOneServer();
}
