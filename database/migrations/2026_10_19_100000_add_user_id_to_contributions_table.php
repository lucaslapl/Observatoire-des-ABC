<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lie les contributions à un compte membre (nullable : la contribution
     * anonyme reste possible). nullOnDelete : la contribution — travail
     * humain — est conservée et devient anonyme si le compte est supprimé.
     */
    public function up(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('projet_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contributions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
