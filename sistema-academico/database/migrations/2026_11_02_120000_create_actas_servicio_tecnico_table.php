<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actas_servicio_tecnico', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->unsignedInteger('numero_secuencia');
            $table->unsignedSmallInteger('anio');

            $table->date('fecha');
            $table->string('hora', 10)->nullable();
            $table->string('departamento_area')->nullable();
            $table->string('ubicacion_oficina')->nullable();
            $table->string('persona_reporta')->nullable();
            $table->string('tipo_equipo')->nullable();
            $table->string('tipo_equipo_otro')->nullable();
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('nro_serie')->nullable();
            $table->text('falla_motivo')->nullable();
            $table->text('trabajo_realizado')->nullable();
            $table->text('repuestos_insumos')->nullable();
            $table->string('tecnico_responsable')->nullable();
            $table->string('encargado_ti_nombre')->nullable();
            $table->string('encargado_ti_ci')->nullable();
            $table->string('usuario_nombre')->nullable();
            $table->string('usuario_ci')->nullable();

            $table->string('pdf_generado_path')->nullable();
            $table->string('pdf_firmado_path')->nullable();
            $table->string('estado', 20)->default('generado');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['anio', 'numero_secuencia']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actas_servicio_tecnico');
    }
};
