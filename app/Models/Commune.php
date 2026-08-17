<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commune extends Model
{
    public $incrementing = false;

    protected $primaryKey = ['projet_id', 'code_geographique'];

    protected $fillable = [
        'projet_id', 'code_geographique', 'libelle_geographique', 'epci',
        'libelle_epci', 'departement', 'libelle_departement', 'region',
        'libelle_pnr', 'lon', 'lat', 'anomalie', 'distance_centre_km',
    ];

    protected function casts(): array
    {
        return [
            'lon' => 'float',
            'lat' => 'float',
            'anomalie' => 'boolean',
            'distance_centre_km' => 'float',
        ];
    }
}
