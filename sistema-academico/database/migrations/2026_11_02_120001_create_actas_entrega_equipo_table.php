<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actas_entrega_equipo', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->unsignedInteger('numero_secuencia');
            $table->unsignedSmallInteger('anio');

            $table->date('fecha_emision');
            $table->string('entrega_nombre')->nullable();
            $table->string('entrega_ci')->nullable();
            $table->string('entrega_cargo')->nullable();
            $table->string('recibe_nombre')->nullable();
            $table->string('recibe_ci')->nullable();
            $table->string('recibe_cargo')->nullable();
            $table->string('insumo')->nullable();
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->string('nro_serie')->nullable();
            $table->text('especificaciones')->nullable();
            // Lista libre de accesorios entregados (ej. ["Cargador", "Mouse"]) —
            // igual que producción, no un catálogo cerrado.
            $table->json('accesorios')->nullable();
            $table->string('accesorios_otros')->nullable();
            $table->string('estado_equipo')->nullable();
            $table->text('observaciones_entrega')->nullable();
            $table->date('fecha_devolucion')->nullable();
            $table->string('motivo_devolucion')->nullable();
            $table->string('estado_equipo_devolucion')->nullable();
            $table->text('observaciones_devolucion')->nullable();

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
        Schema::dropIfExists('actas_entrega_equipo');
    }
};
