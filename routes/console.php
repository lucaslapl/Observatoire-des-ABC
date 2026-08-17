<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Sauvegarde quotidienne (remplace la sauvegarde auto du processus Node) ---
Schedule::command('abc:backup')->dailyAt('04:00');

// --- Collect mensuel (désactivable en prod : COLLECT_AUTOMATIC=false).
//     Lancé avec --init : synchronisation sans purge (pas de superuser requis
//     sur les hébergements mutualisés). ---
if (config('abc.collect_automatic')) {
    Schedule::command('abc:collect --init')->monthlyOn(1, '03:00');
}
