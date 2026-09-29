<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seguimiento de cada operación de sincronización con Canvas/Teams
     * disparada desde el panel (alta de usuario, creación de curso/equipo,
     * matrícula, baja) — entidad JOB_SINCRONIZACION del MER de Avance 1.
     */
    public function up(): void
    {
        Schema::create('job_sincronizacions', function (Blueprint $table) {
            $table->id();
            $table->enum('tipo', ['alta_usuario', 'crear_curso', 'crear_team', 'matricula', 'baja']);
            $table->foreignId('usuario_solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('estado', ['pendiente', 'en_proceso', 'completado', 'error'])->default('pendiente');
            $table->timestamp('fecha_inicio')->nullable();
            $table->timestamp('fecha_fin')->nullable();
            $table->text('resultado')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_sincronizacions');
    }
};
