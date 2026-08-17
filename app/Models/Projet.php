<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Projet extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'nom', 'structure_porteuse', 'type_de_structure_porteuse',
        'annee_debut', 'annee_fin', 'avancement_raw', 'statut',
        'potentiellement_termine', 'potentiellement_en_cours', 'estime_termine',
        'statut_maj_at', 'ami_ofb', 'source', 'url_page',
    ];

    protected function casts(): array
    {
        return [
            'annee_debut' => 'integer',
            'annee_fin' => 'integer',
            'potentiellement_termine' => 'boolean',
            'potentiellement_en_cours' => 'boolean',
            'estime_termine' => 'boolean',
            'ami_ofb' => 'boolean',
            'statut_maj_at' => 'datetime',
        ];
    }

    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class, 'projet_id', 'id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'projet_id', 'id');
    }

    public function enrichissement(): HasOne
    {
        return $this->hasOne(Enrichissement::class, 'projet_id', 'id');
    }

    public function verification(): HasOne
    {
        return $this->hasOne(Verification::class, 'projet_id', 'id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class, 'projet_id', 'id');
    }
}
