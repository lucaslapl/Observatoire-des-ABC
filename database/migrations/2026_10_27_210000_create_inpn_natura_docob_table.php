<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Documents d'objectifs (DOCOB) des sites Natura 2000, tels que
     * référencés dans la base INPN (generique_upload) : titre (« DOCOB
     * 2012 ») et lien vers le document hébergé par l'INPN. Les PDF
     * copiés localement sous public/docob/FR{sitecode}.pdf, s'ils
     * existent, sont préférés à l'affichage (présence testée à
     * l'exécution, pas stockée en base).
     */
    public function up(): void
    {
        Schema::create('inpn_natura_docob', function (Blueprint $table) {
            $table->id();
            $table->string('sitecode', 9);
            // text : certains liens INPN dépassent 255 caractères.
            $table->text('titre')->nullable();
            $table->text('lien')->nullable();
            $table->timestamps();

            $table->foreign('sitecode')->references('sitecode')->on('inpn_natura_sites')->cascadeOnDelete();
            $table->unique(['sitecode', 'titre']);
            $table->index('sitecode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inpn_natura_docob');
    }
};
