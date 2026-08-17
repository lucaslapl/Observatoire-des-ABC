<?php

namespace App\Services;

use League\Csv\Reader;
use RuntimeException;

/**
 * Lecture CSV tolérante.
 *
 * `league/csv` est strict : il rejette les fichiers mal quotés
 * (ex. `"PETR '"Pays de la Jeune Loire'"'`). On tente d'abord league/csv,
 * puis on retombe sur un parseur de repli qui reproduit le comportement
 * `csv-parse` (relax_quotes, relax_column_count, skip_records_with_error).
 */
class CsvReaderService
{
    /**
     * @return array<int, array<string, string>> lignes sous forme de tableaux associatifs
     */
    public function read(string $file, string $delimiter = ';', bool $forceTolerant = false): array
    {
        $text = file_get_contents($file);
        if ($text === false) {
            throw new RuntimeException("Impossible de lire le fichier CSV : {$file}");
        }

        // BOM UTF-8
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        if (! $forceTolerant) {
            $rows = $this->tryLeagueCsv($text, $delimiter);
            if ($rows !== null) {
                return $rows;
            }
        }

        return $this->parseTolerant($text, $delimiter);
    }

    /**
     * Tente league/csv ; retourne null si le fichier n'est pas strictement valide.
     */
    private function tryLeagueCsv(string $text, string $delimiter): ?array
    {
        try {
            $reader = Reader::createFromString($text);
            $reader->setDelimiter($delimiter);
            $reader->setHeaderOffset(0);
            $records = [];
            foreach ($reader->getRecords() as $record) {
                $records[] = $record;
            }

            return $records;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parseur de repli inspiré de csv-parse (relax_quotes / relax_column_count /
     * skip_records_with_error). Gère les guillemets simples/doubles imbriqués de
     * façon permissive et ignore les lignes dont le nombre de colonnes diffère.
     *
     * @return array<int, array<string, string>>
     */
    private function parseTolerant(string $text, string $delimiter): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = $this->splitLine($lines[0], $delimiter);
        $header = array_map(fn ($h) => trim($h), $header);

        $out = [];
        $n = count($header);
        for ($i = 1, $c = count($lines); $i < $c; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $fields = $this->splitLine($line, $delimiter);
            if (count($fields) !== $n) {
                continue; // skip_records_with_error
            }
            $row = [];
            foreach ($header as $j => $col) {
                $row[$col] = $fields[$j] ?? '';
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Découpe une ligne en respectant les guillemets, de façon permissive.
     * Une guillemet doublé ou un guillemet dans un champ n'interrompt pas le
     * champ s'il est entre guillemets.
     *
     * @return array<int, string>
     */
    private function splitLine(string $line, string $delimiter): array
    {
        $fields = [];
        $field = '';
        $inQuotes = false;
        $quoteChar = '';
        $len = strlen($line);

        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];

            if ($ch === '"' || $ch === "'") {
                if (! $inQuotes) {
                    // Ouverture : seulement si début de champ ou après délimiteur.
                    $inQuotes = true;
                    $quoteChar = $ch;
                } elseif ($ch === $quoteChar) {
                    // Guillemet fermant (ou doublé).
                    if ($i + 1 < $len && $line[$i + 1] === $quoteChar) {
                        $field .= $quoteChar;
                        $i++;
                    } else {
                        $inQuotes = false;
                    }
                } else {
                    $field .= $ch;
                }

                continue;
            }

            if ($ch === $delimiter && ! $inQuotes) {
                $fields[] = $field;
                $field = '';

                continue;
            }

            $field .= $ch;
        }
        $fields[] = $field;

        return $fields;
    }
}
