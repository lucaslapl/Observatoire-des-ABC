<?php

namespace App\Services;

use App\Models\Commune;
use Illuminate\Support\Str;

class RegionService
{
    public function label(?string $code): ?string
    {
        if (! $code) {
            return null;
        }
        $regions = config('regions');
        if (isset($regions[$code])) {
            return $regions[$code];
        }
        // Déjà un libellé ?
        if (preg_match('/[a-zà-ÿ]/i', $code) && ! ctype_digit($code)) {
            return $code;
        }

        return null;
    }

    public function slug(string $label): string
    {
        return Str::slug($label);
    }

    /**
     * Tous les libellés de régions connus, indexés par slug URL.
     * (config + libellés réellement présents dans la base, ex. Nouvelle-Calédonie)
     *
     * @return array<string, string>
     */
    public function slugsIndex(): array
    {
        $map = [];
        foreach (config('regions') as $label) {
            $map[$this->slug($label)] = $label;
        }
        $values = Commune::query()
            ->whereNotNull('region')
            ->distinct()
            ->pluck('region');
        foreach ($values as $value) {
            $label = $this->label($value);
            if ($label) {
                $map[$this->slug($label)] = $label;
            }
        }

        return $map;
    }

    public function resolveBySlug(string $slug): ?string
    {
        return $this->slugsIndex()[$slug] ?? null;
    }

    /**
     * Normalise une valeur (code ou libellé) en libellé.
     */
    public function normalizeToLabel(?string $value): ?string
    {
        return $this->label($value);
    }
}
