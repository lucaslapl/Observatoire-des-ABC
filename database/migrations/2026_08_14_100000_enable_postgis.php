<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
        }
    }

    public function down(): void
    {
        // L'extension reste en place (la désactiver casserait d'autres tables).
    }
};
