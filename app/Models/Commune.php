<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Colonnes jointes depuis projets/verifications (GeoJsonService) et alias
 * d'agrégats (StatsService) : documentés pour l'analyse statique.
 *
 * @property string|null $nom
 * @property string|null $slug
 * @property string|null $structure_porteuse
 * @property int|null $annee_debut
 * @property int|null $annee_fin
 * @property string|null $statut
 * @property bool|null $potentiellement_termine
 * @property bool|null $potentiellement_en_cours
 * @property bool|null $estime_termine
 * @property string|null $source
 * @property string|null $verif_etat
 * @property string|null $verif_note
 * @property string|null $verif_lien
 * @property int|null $n
 * @property string|null $libelle
 */
class Commune extends Model
{
    public $incrementing = false;

    /** @phpstan-ignore-next-line Clé composite : Eloquent ne type que string */
    protected $primaryKey = ['projet_id', 'code_geographique'];

    /** @return BelongsTo<Projet, $this> */
    public function projet(): BelongsTo
    {
        return $this->belongsTo(Projet::class, 'projet_id', 'id');
    }

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
