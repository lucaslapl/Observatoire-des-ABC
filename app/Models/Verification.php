<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Verification extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['projet_id', 'etat', 'note', 'lien', 'verifie_le'];

    protected function casts(): array
    {
        return [
            'verifie_le' => 'datetime',
        ];
    }
}
