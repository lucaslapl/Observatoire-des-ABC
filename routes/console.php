<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --- Sauvegarde quotidienne (remplace la sauvegarde auto du processus Node) ---
Schedule::command('abc:backup')->dailyAt('04:00');

// --- Collect mensuel (remplace le CRON mensuel manuel) ---
Schedule::command('abc:collect')->monthlyOn(1, '03:00');
