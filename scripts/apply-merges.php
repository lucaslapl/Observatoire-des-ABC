<?php

/**
 * Applique les fusions listées dans data/triage/fusions-phase1.csv
 * (n;canonique;doublon;motif) via abc:merge-projects.
 *
 * Validation AVANT toute fusion : tous les projets doivent exister, aucun
 * id ne doit apparaître deux fois, aucun id ne peut être à la fois canonique
 * et doublon (refus des chaînes). En cas d'échec, rien n'est appliqué.
 *
 * Usage : php scripts/apply-merges.php <csv> [--dry-run]
 * (script Laravel : bootstrappe l'application, n'est PAS un fichier public)
 */

use App\Models\ProjectAlias;
use App\Models\Projet;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$csv = $argv[1] ?? 'data/triage/fusions-phase1.csv';
$dryRun = in_array('--dry-run', $argv, true);

if (! is_file($csv)) {
    fwrite(STDERR, "CSV introuvable : {$csv}\n");
    exit(1);
}

$rows = [];
$handle = fopen($csv, 'r');
$header = fgetcsv($handle, 0, ';');
while (($line = fgetcsv($handle, 0, ';')) !== false) {
    if (count($line) < 4) {
        continue;
    }
    $rows[] = ['n' => $line[0], 'canonique' => $line[1], 'doublon' => $line[2], 'motif' => $line[3]];
}
fclose($handle);

if (! $rows) {
    fwrite(STDERR, "Aucune fusion dans {$csv}\n");
    exit(1);
}

// --- Validation préalable complète (échec = rien n'est appliqué) ---
$errors = [];
$seenCanonique = [];
$seenDoublon = [];
foreach ($rows as $r) {
    if (! Projet::find($r['canonique'])) {
        $errors[] = "N°{$r['n']} : canonique introuvable ({$r['canonique']})";
    }
    if (! Projet::find($r['doublon'])) {
        $errors[] = "N°{$r['n']} : doublon introuvable ({$r['doublon']})";
    }
    if (ProjectAlias::where('alias_project_id', $r['doublon'])->exists()) {
        $errors[] = "N°{$r['n']} : le doublon est déjà un alias ({$r['doublon']})";
    }
    foreach ([['canonique', $seenDoublon], ['doublon', $seenCanonique]] as [$side, $opposite]) {
        if (isset($opposite[$r[$side]])) {
            $errors[] = "N°{$r['n']} : {$r[$side]} est déjà {$opposite[$r[$side]]} d'une autre paire (chaîne refusée)";
        }
    }
    $seenCanonique[$r['canonique']] = 'canonique';
    $seenDoublon[$r['doublon']] = 'doublon';
    if (isset($seenCanonique[$r['doublon']])) {
        $errors[] = "N°{$r['n']} : {$r['doublon']} est aussi canonique d'une autre paire";
    }
}

if ($errors) {
    fwrite(STDERR, "VALIDATION EN ÉCHEC — aucune fusion appliquée :\n".implode("\n", $errors)."\n");
    exit(1);
}

echo count($rows).' fusions validées'.($dryRun ? ' (--dry-run : aucune fusion appliquée)' : '')."\n";
if ($dryRun) {
    exit(0);
}

$ok = 0;
foreach ($rows as $r) {
    $code = Artisan::call('abc:merge-projects', [
        'doublon' => $r['doublon'],
        'canonique' => $r['canonique'],
        '--motif' => $r['motif'],
    ]);
    if ($code !== 0) {
        fwrite(STDERR, Artisan::output());
        fwrite(STDERR, "ÉCHEC N°{$r['n']} : {$r['doublon']} → {$r['canonique']} — interruption.\n");
        exit(1);
    }
    $ok++;
}

echo "{$ok} fusions appliquées. Projets restants : ".Projet::count()."\n";
