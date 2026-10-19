<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Snapshot extends Model
{
    public $incrementing = false;

    /** @phpstan-ignore-next-line Clé composite : Eloquent ne type que string */
    protected $primaryKey = ['snapshot_date', 'projet_id'];

    protected $fillable = ['snapshot_date', 'projet_id', 'avancement', 'source'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
        ];
    }
}
