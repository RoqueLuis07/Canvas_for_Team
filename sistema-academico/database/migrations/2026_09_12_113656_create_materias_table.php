<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_estudio_id')->constrained('plan_estudios')->cascadeOnDelete();
            $table->string('codigo');
            $table->string('nombre');
            $table->unsignedTinyInteger('creditos')->default(0);
            $table->unsignedTinyInteger('semestre_sugerido')->default(1);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['plan_estudio_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materias');
    }
};
