<?php

use App\Models\Verification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colonne note_auto : motifs générés par la machine (doublons Fonds vert,
     * paires du triage), jusque-là concaténés dans la note humaine — ils
     * polluaient les verdicts affichés avec les contributions et l'export
     * GeoJSON. Les lignes existantes « Doublon suspect (…) » sont basculées.
     */
    public function up(): void
    {
        Schema::table('verifications', function (Blueprint $table) {
            $table->text('note_auto')->nullable()->after('note');
        });

        DB::table('verifications')
            ->whereNotNull('note')
            ->where('note', 'like', '%Doublon suspect (%')
            ->orderBy('projet_id')
            ->each(function ($v) {
                [$note, $noteAuto] = Verification::splitAutoNotes($v->note);

                DB::table('verifications')
                    ->where('projet_id', $v->projet_id)
                    ->update(['note' => $note, 'note_auto' => $noteAuto]);
            });
    }

    public function down(): void
    {
        // Remise en ligne : les motifs automatiques repartent dans la note.
        DB::table('verifications')
            ->whereNotNull('note_auto')
            ->orderBy('projet_id')
            ->each(function ($v) {
                $note = trim(($v->note ? $v->note."\n" : '').(string) $v->note_auto);

                DB::table('verifications')
                    ->where('projet_id', $v->projet_id)
                    ->update(['note' => $note !== '' ? $note : null, 'note_auto' => null]);
            });

        Schema::table('verifications', function (Blueprint $table) {
            $table->dropColumn('note_auto');
        });
    }
};
