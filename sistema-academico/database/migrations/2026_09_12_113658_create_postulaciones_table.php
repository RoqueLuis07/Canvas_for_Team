<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('programa_id')->constrained('programas')->cascadeOnDelete();
            $table->foreignId('periodo_academico_id')->constrained('periodos_academicos')->cascadeOnDelete();
            $table->enum('estado', ['pendiente', 'admitido', 'rechazado'])->default('pendiente');
            $table->date('fecha_postulacion');
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['persona_id', 'programa_id', 'periodo_academico_id'], 'postulacion_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postulaciones');
    }
};
