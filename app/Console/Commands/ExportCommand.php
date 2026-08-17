<?php

namespace App\Console\Commands;

use App\Services\ExportService;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    protected $signature = 'abc:export {--fmt=csv : csv|geojson}';

    protected $description = 'Exporte les données (CSV ou GeoJSON)';

    public function handle(ExportService $service): int
    {
        $fmt = $this->option('fmt');
        $out = $service->export($fmt);

        $dir = config('abc.export_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $filename = $fmt === 'geojson' ? 'abc.geojson' : 'abc-projets.csv';
        $path = $dir.'/'.$filename;
        file_put_contents($path, $out);

        $count = $fmt === 'geojson'
            ? substr_count($out, '"type":"Feature"')
            : substr_count($out, "\n") - 1;

        $this->line("{$filename} écrit : {$path} ({$count} éléments)");

        return self::SUCCESS;
    }
}
