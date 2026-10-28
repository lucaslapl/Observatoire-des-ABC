<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exécute un fichier SQL (dump portable type abc:export-inpn /
 * abc:export-deploy) contre la base de l'application : pour les
 * hébergements sans phpPgAdmin ni accès psql.
 *
 * Le fichier est exécuté EN FLUX, relevé par relevé : un dump INPN
 * (267 Mo, ~2,2 M lignes) ne tient ni en mémoire PHP ni en une seule
 * requête. Le découpage respecte les chaînes littérales ('' échappé),
 * chaque relevé est exécuté sur la connexion PDO. Comme les dumps
 * produits par l'application sont enveloppés dans BEGIN; ... COMMIT;,
 * l'import est atomique : en cas d'erreur, rien n'est écrit.
 */
class ImportSqlCommand extends Command
{
    protected $signature = 'abc:import-sql
        {file : chemin du fichier SQL (relatif au projet ou absolu)}
        {--force : exécuter sans confirmation}';

    protected $description = 'Exécute un fichier SQL (dump portable) sur la base de l\'application';

    /** Octets consommés par le générateur (progression affichée). */
    private int $octetsLus = 0;

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (! str_starts_with($file, '/')) {
            $file = base_path($file);
        }
        if (! is_file($file)) {
            $this->error("Fichier introuvable : {$file}");

            return self::FAILURE;
        }
        if (! is_readable($file)) {
            $this->error("Fichier illisible : {$file}");

            return self::FAILURE;
        }

        $tailleMo = number_format(filesize($file) / 1_048_576, 1);
        $driver = DB::connection()->getDriverName();

        $this->line("Fichier : {$file} ({$tailleMo} Mo)");
        $this->line("Base : {$driver}");

        if (! $this->option('force') && ! $this->confirm('Exécuter ce fichier SQL sur la base ?')) {
            return self::FAILURE;
        }

        set_time_limit(0);
        $pdo = DB::connection()->getPdo();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $releves = 0;
        $depart = microtime(true);
        // Les BEGIN/COMMIT du fichier sont ignorés : c'est la commande qui
        // gère l'atomicité (même effet en production, et compatible avec
        // un appel depuis un contexte déjà en transaction — tests, CRON
        // Laravel).
        try {
            DB::transaction(function () use ($pdo, $file, &$releves): void {
                foreach ($this->releves($file) as $sql) {
                    $this->executer($pdo, $sql);
                    $releves++;
                    if ($releves % 200 === 0) {
                        $lu = number_format($this->octetsLus / 1_048_576, 1);
                        $this->line("  {$releves} relevés, {$lu} Mo lus…");
                    }
                }
            });
        } catch (\PDOException $e) {
            $this->error("Échec de l'import, rien n'a été écrit (transaction annulée) :");
            $this->error('  '.$e->getMessage());

            return self::FAILURE;
        }

        $duree = number_format(microtime(true) - $depart, 1);
        $this->info("Import terminé : {$releves} relevés en {$duree} s.");

        return self::SUCCESS;
    }

    /**
     * Exécute un relevé. Les « SET » de session (client_encoding,
     * statement_timeout…) sont ignorés silencieusement s'ils ne sont pas
     * supportés par le pilote — tout le reste doit réussir.
     */
    private function executer(\PDO $pdo, string $sql): void
    {
        if (preg_match('/^(BEGIN|COMMIT|START TRANSACTION|END);?$/i', trim($sql))) {
            return; // atomicité gérée par la commande (DB::transaction)
        }
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            if (preg_match('/^\s*SET\s/i', $sql)) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Découpe le fichier en relevés, en flux : le point-virgule en fin de
     * relevé est reconnu hors chaîne littérale ('' = guillemet échappé).
     *
     * @return \Generator<int, string>
     */
    private function releves(string $file): \Generator
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new RuntimeException("Impossible de lire {$file}");
        }

        $buffer = '';
        $inString = false;
        $pending = false; // ';' vu : le relevé se termine si le caractère suivant est un saut de ligne

        try {
            while (($chunk = fread($handle, 65536)) !== false) {
                if ($chunk === '') {
                    break;
                }
                $len = strlen($chunk);
                for ($i = 0; $i < $len; $i++) {
                    $c = $chunk[$i];

                    if ($pending) {
                        $pending = false;
                        if ($c === "\n" || $c === "\r" || $c === ' ' || $c === "\t") {
                            yield trim($buffer);
                            $buffer = '';

                            continue;
                        }
                        // point-virgule au milieu d'un relevé : on continue
                        $buffer .= $c;

                        continue;
                    }

                    if ($inString) {
                        $buffer .= $c;
                        if ($c === "'") {
                            if ($i + 1 < $len && $chunk[$i + 1] === "'") {
                                $buffer .= $chunk[++$i];
                            } else {
                                $inString = false;
                            }
                        }

                        continue;
                    }

                    if ($c === "'") {
                        $inString = true;
                        $buffer .= $c;

                        continue;
                    }

                    if ($c === ';') {
                        $buffer .= $c;
                        $pending = true;

                        continue;
                    }

                    $buffer .= $c;
                }
                $this->octetsLus += $len;
            }

            if (trim($buffer) !== '') {
                yield trim($buffer);
            }
        } finally {
            fclose($handle);
        }
    }
}
