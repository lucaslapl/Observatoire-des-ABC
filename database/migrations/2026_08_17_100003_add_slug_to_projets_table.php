<?php

use App\Models\Projet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projets', function (Blueprint $table) {
            $table->string('slug', 255)->nullable()->unique()->after('id');
        });

        // Backfill : slug unique pour chaque projet existant.
        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('projets', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    private function backfill(): void
    {
        $projets = Projet::query()->whereNull('slug')->get(['id', 'nom']);

        foreach ($projets as $projet) {
            if (! $projet->nom) {
                continue;
            }
            $base = Str::slug($projet->nom);
            if ($base === '' || $base === '/') {
                $base = 'abc';
            }
            $slug = $base;
            $n = 2;
            while (Projet::query()->where('slug', $slug)->where('id', '!=', $projet->id)->exists()) {
                $slug = $base.'-'.$n++;
            }
            Projet::query()->whereKey($projet->id)->update(['slug' => $slug]);
        }
    }
};
