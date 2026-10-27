<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal des imports INPN : une ligne par source (znieff, natura,
 * aires), avec la version de référence de la base INPN importée. C'est
 * la source du bandeau public « données INPN — base … » et de l'audit
 * de fraîcheur des référentiels.
 *
 * @property string $source
 * @property string $version_source
 * @property int $nb_entites
 */
class InpnImport extends Model
{
    protected $fillable = ['source', 'version_source', 'nb_entites', 'importe_le'];

    protected function casts(): array
    {
        return [
            'nb_entites' => 'integer',
            'importe_le' => 'datetime',
        ];
    }
}
