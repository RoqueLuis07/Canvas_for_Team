<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Curso" = la oferta concreta de una Materia en un Período académico
     * puntual: es lo que efectivamente se crea como curso en Canvas y como
     * equipo en Teams, y a lo que se matricula un alumno (no a la Materia
     * en abstracto, que es solo la definición curricular del plan).
     */
    public function up(): void
    {
        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('materia_id')->constrained('materias')->cascadeOnDelete();
            $table->foreignId('periodo_academico_id')->constrained('periodos_academicos')->cascadeOnDelete();
            $table->unsignedSmallInteger('cupo_maximo')->nullable();
            $table->string('canvas_course_id')->nullable();
            $table->string('teams_group_id')->nullable();
            $table->enum('estado', ['planificado', 'publicado', 'cerrado'])->default('planificado');
            $table->timestamps();

            $table->unique(['materia_id', 'periodo_academico_id'], 'curso_unico_por_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursos');
    }
};
