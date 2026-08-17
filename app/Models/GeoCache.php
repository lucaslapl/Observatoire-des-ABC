<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoCache extends Model
{
    protected $table = 'geo_cache';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code_geographique', 'lon', 'lat', 'name'];

    protected function casts(): array
    {
        return [
            'lon' => 'float',
            'lat' => 'float',
        ];
    }
}
