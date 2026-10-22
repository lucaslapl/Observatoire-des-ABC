<?php

/**
 * Extrait les décisions du tableur de triage (audit 2026-10-21) :
 * - data/triage/fusions-phase1.csv            : paires « Fusionner » (48)
 * - data/triage/doublons-a-verifier-phase1.csv : paires « À vérifier » (26)
 *
 * Règle de direction : le projet du registre OFB (data.gouv) est canonique ;
 * la copie (wayback / Fonds vert) devient l'alias. Le script échoue bruyamment
 * sur toute paire ambigue (deux sources data.gouv, ou aucune).
 *
 * Usage : php scripts/extract-triage.php <fichier.xlsx>
 */
const CANONICAL_SOURCE = 'data.gouv';

function xlsx(ZipArchive $z, string $entry): SimpleXMLElement
{
    return simplexml_load_string($z->getFromName($entry));
}

function colIndex(string $ref): int
{
    preg_match('/^([A-Z]+)/', $ref, $m);
    $col = 0;
    foreach (str_split($m[1]) as $ch) {
        $col = $col * 26 + (ord($ch) - 64);
    }

    return $col - 1;
}

function sheetRows(ZipArchive $z, string $entry, array $shared): array
{
    $rows = [];
    $sx = xlsx($z, $entry);
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $col = colIndex((string) $c['r']);
            $type = (string) $c['t'];
            if ($type === 's') {
                $cells[$col] = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $cells[$col] = (string) $c->is->t;
            } else {
                $cells[$col] = (string) $c->v;
            }
        }
        $rows[] = $cells;
    }

    return $rows;
}

function csvLine(array $cells): string
{
    return implode(';', array_map(
        fn ($v) => '"'.str_replace(['"', "\n"], ['""', ' '], (string) $v).'"',
        $cells,
    ));
}

$source = $argv[1] ?? 'triage-abc-doublons-éditions-et-candidats-fonds-vert.xlsx';
$z = new ZipArchive;
if (! $z->open($source)) {
    fwrite(STDERR, "Impossible d'ouvrir {$source}\n");
    exit(1);
}

$shared = [];
$sst = xlsx($z, 'xl/sharedStrings.xml');
foreach ($sst->si as $si) {
    $text = '';
    foreach ($si->r as $run) {
        $text .= (string) $run->t;
    }
    if ($text === '' && isset($si->t)) {
        $text = (string) $si->t;
    }
    $shared[] = $text;
}

$wb = xlsx($z, 'xl/workbook.xml');
$rels = xlsx($z, 'xl/_rels/workbook.xml.rels');
$targets = [];
foreach ($rels->Relationship as $r) {
    $targets[(string) $r['Id']] = (string) $r['Target'];
}

$entry = null;
foreach ($wb->sheets->sheet as $sheet) {
    if ((string) $sheet['name'] === 'Fusions à réaliser') {
        $rid = (string) ($sheet->attributes('r', true)['id'] ?? '');
        $entry = 'xl/'.ltrim($targets[$rid] ?? '', '/');
        break;
    }
}
if (! $entry) {
    fwrite(STDERR, "Feuille « Fusions à réaliser » introuvable\n");
    exit(1);
}

// Cartographie des colonnes par en-têtes (robuste aux colonnes vides).
$rows = sheetRows($z, $entry, $shared);
$colMap = null;
foreach ($rows as $i => $cells) {
    if (in_array('Décision', array_map('trim', $cells), true)) {
        $colMap = [];
        foreach ($cells as $idx => $label) {
            $colMap[trim((string) $label)] = $idx;
        }
        unset($rows[$i]);
        break;
    }
}
if (! isset($colMap['Décision'], $colMap['ID projet 1'], $colMap['Source 1'], $colMap['ID projet 2'], $colMap['Source 2'], $colMap['N°'])) {
    fwrite(STDERR, "En-têtes attendus introuvables dans « Fusions à réaliser »\n");
    exit(1);
}
$colReco = $colMap['Recommandation'] ?? null;

$fusions = [];
$aVerifier = [];
$errors = [];

foreach ($rows as $cells) {
    $decision = trim((string) ($cells[$colMap['Décision']] ?? ''));
    if (! in_array($decision, ['Fusionner', 'À vérifier', 'Conserver distincts'], true)) {
        continue;
    }

    $n = trim((string) ($cells[$colMap['N°']] ?? ''));
    $id1 = trim((string) ($cells[$colMap['ID projet 1']] ?? ''));
    $source1 = trim((string) ($cells[$colMap['Source 1']] ?? ''));
    $id2 = trim((string) ($cells[$colMap['ID projet 2']] ?? ''));
    $source2 = trim((string) ($cells[$colMap['Source 2']] ?? ''));
    $recommandation = trim((string) ($colReco !== null ? ($cells[$colReco] ?? '') : ''));

    if ($decision === 'Fusionner') {
        if ($source1 === CANONICAL_SOURCE && $source2 !== CANONICAL_SOURCE) {
            [$canonique, $doublon] = [$id1, $id2];
        } elseif ($source2 === CANONICAL_SOURCE && $source1 !== CANONICAL_SOURCE) {
            [$canonique, $doublon] = [$id2, $id1];
        } else {
            $errors[] = "paire N°{$n} ambigüe (sources : {$source1} / {$source2})";

            continue;
        }
        $fusions[] = [$n, $canonique, $doublon, $recommandation];
    } elseif ($decision === 'À vérifier') {
        $aVerifier[] = [$n, $id1, $id2, $recommandation];
    }
}

if ($errors) {
    fwrite(STDERR, "ERREURS (direction indéterminable) :\n".implode("\n", $errors)."\n");
    exit(1);
}

@mkdir('data/triage', 0777, true);
file_put_contents('data/triage/fusions-phase1.csv',
    "n;canonique;doublon;motif\n".implode("\n", array_map('csvLine', $fusions))."\n");
file_put_contents('data/triage/doublons-a-verifier-phase1.csv',
    "n;id_projet_1;id_projet_2;motif\n".implode("\n", array_map('csvLine', $aVerifier))."\n");

echo 'fusions : '.count($fusions).' (attendu 48) ; à vérifier : '.count($aVerifier).' (attendu 26)'."\n";
