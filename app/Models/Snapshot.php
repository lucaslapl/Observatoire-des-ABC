<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Snapshot extends Model
{
    public $incrementing = false;

    protected $primaryKey = ['snapshot_date', 'projet_id'];

    protected $fillable = ['snapshot_date', 'projet_id', 'avancement', 'source'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
        ];
    }
}
