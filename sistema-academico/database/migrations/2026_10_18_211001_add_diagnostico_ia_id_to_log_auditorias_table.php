<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_auditorias', function (Blueprint $table) {
            $table->foreignId('diagnostico_ia_id')
                ->nullable()
                ->after('usuario_id')
                ->constrained('diagnosticos_ia')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('log_auditorias', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diagnostico_ia_id');
        });
    }
};
