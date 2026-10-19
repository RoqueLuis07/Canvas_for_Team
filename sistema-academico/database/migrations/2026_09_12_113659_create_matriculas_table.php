<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matriculas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('plan_estudio_id')->constrained('plan_estudios')->cascadeOnDelete();
            $table->foreignId('periodo_academico_id')->constrained('periodos_academicos')->cascadeOnDelete();
            // Postulación de origen — solo aplica a alumnos nuevos (primera
            // matrícula). Alumnos que continúan de un período anterior no
            // vuelven a postular, así que queda nula.
            $table->foreignId('postulacion_id')->nullable()->constrained('postulaciones')->nullOnDelete();
            $table->enum('estado', ['activa', 'retirada', 'finalizada'])->default('activa');
            $table->date('fecha_matricula');
            $table->timestamps();

            $table->unique(['persona_id', 'periodo_academico_id'], 'matricula_unica_por_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matriculas');
    }
};
