<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $verifie_le
 */
class Verification extends Model
{
    protected $primaryKey = 'projet_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['projet_id', 'etat', 'note', 'lien', 'verifie_le', 'verifie_par'];

    protected function casts(): array
    {
        return [
            'verifie_le' => 'datetime',
        ];
    }
}
