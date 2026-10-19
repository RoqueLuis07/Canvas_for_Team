<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de auditoría de cada operación de sincronización (HU-05 del
     * Avance 1): quién ejecutó qué acción, sobre qué entidad, y el detalle
     * del resultado — entidad LOG_AUDITORIA del MER.
     */
    public function up(): void
    {
        Schema::create('log_auditorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('accion', 80);
            $table->string('entidad_afectada', 80);
            $table->text('detalle')->nullable();
            $table->timestamp('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_auditorias');
    }
};
