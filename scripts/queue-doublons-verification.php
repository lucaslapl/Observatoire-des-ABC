<?php

/**
 * Met en file de vérification (/verify) les paires « À vérifier » du triage
 * (data/triage/doublons-a-verifier-phase1.csv : n;id_projet_1;id_projet_2;motif).
 *
 * Pour chaque projet de chaque paire :
 * - sans vérification : création d'une entrée etat=a_verifier avec le motif
 *   de la paire dans note_auto → le projet apparaît dans la page /verify ;
 * - avec vérification existante (travail humain) : l'état est conservé et le
 *   motif automatique est complété dans note_auto, jamais dans la note
 *   humaine (idempotent : le marqueur « paire N°x » n'est pas dupliqué).
 *
 * Chaque paire traitée est journalisée dans audit_log.
 *
 * Usage : php scripts/queue-doublons-verification.php <csv> [--dry-run]
 */

use App\Models\AuditLog;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$csv = $argv[1] ?? 'data/triage/doublons-a-verifier-phase1.csv';
$dryRun = in_array('--dry-run', $argv, true);

if (! is_file($csv)) {
    fwrite(STDERR, "CSV introuvable : {$csv}\n");
    exit(1);
}

$rows = [];
$handle = fopen($csv, 'r');
fgetcsv($handle, 0, ';'); // en-têtes
while (($line = fgetcsv($handle, 0, ';')) !== false) {
    if (count($line) < 4) {
        continue;
    }
    $rows[] = ['n' => $line[0], 'id1' => $line[1], 'id2' => $line[2], 'motif' => $line[3]];
}
fclose($handle);

$errors = [];
foreach ($rows as $r) {
    foreach (['id1', 'id2'] as $side) {
        if (! Projet::find($r[$side])) {
            $errors[] = "N°{$r['n']} : {$side} introuvable ({$r[$side]})";
        }
    }
}
if ($errors) {
    fwrite(STDERR, "ABANDON :\n".implode("\n", $errors)."\n");
    exit(1);
}

echo count($rows).' paires à mettre en file'.($dryRun ? ' (--dry-run)' : '')."\n";
if ($dryRun) {
    exit(0);
}

foreach ($rows as $r) {
    $marqueur = 'Paire N°'.$r['n'].' du triage 2026-10-21';

    $change = false;
    foreach ([$r['id1'] => $r['id2'], $r['id2'] => $r['id1']] as $projetId => $autreId) {
        $note = 'Doublon suspect ('.$marqueur.') : vérifier s’il s’agit du même ABC que « '.$autreId.' » — '.$r['motif'];

        $verification = Verification::find($projetId);
        if (! $verification) {
            Verification::create(['projet_id' => $projetId, 'etat' => 'a_verifier', 'note_auto' => $note]);
            $change = true;
        } elseif (! str_contains((string) $verification->note_auto, $marqueur)) {
            $verification->update([
                'note_auto' => trim(($verification->note_auto ? $verification->note_auto."\n" : '').$note),
            ]);
            $change = true;
        }
    }

    // Journalisation uniquement si la paire vient d'être ajoutée (idempotence).
    if ($change) {
        AuditLog::create([
            'action' => 'worklist_doublon',
            'avant' => json_encode(['paire' => $r['n'], 'projets' => [$r['id1'], $r['id2']]], JSON_UNESCAPED_UNICODE),
            'apres' => json_encode(['motif' => $r['motif'], 'worklist' => '/verify'], JSON_UNESCAPED_UNICODE),
            'par_admin' => 'scripts/queue-doublons-verification',
        ]);
    }
}

$enFile = Verification::where('etat', 'a_verifier')->count();
echo 'Terminé. Vérifications en file (etat=a_verifier) : '.$enFile."\n";
