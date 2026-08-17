<?php

namespace App\Services;

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
        // déjà un libellé ?
        if (preg_match('/[a-zà-ÿ]/i', $code) && ! ctype_digit($code)) {
            return $code;
        }

        return null;
    }
}
