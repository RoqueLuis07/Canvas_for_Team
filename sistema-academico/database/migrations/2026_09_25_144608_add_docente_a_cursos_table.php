<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cursos', function (Blueprint $table) {
            $table->foreignId('docente_persona_id')->nullable()->after('periodo_academico_id')
                ->constrained('personas')->nullOnDelete();
            $table->timestamp('docente_canvas_at')->nullable()->after('teams_group_id');
            $table->timestamp('docente_teams_at')->nullable()->after('docente_canvas_at');
        });
    }

    public function down(): void
    {
        Schema::table('cursos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('docente_persona_id');
            $table->dropColumn(['docente_canvas_at', 'docente_teams_at']);
        });
    }
};
