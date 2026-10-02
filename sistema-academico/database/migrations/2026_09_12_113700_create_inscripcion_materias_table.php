<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscripcion_materias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->cascadeOnDelete();
            // Curso = la oferta concreta (Materia + Período) a la que el
            // alumno se matricula, y la que carga el canvas_course_id /
            // teams_group_id usados para darlo de alta en ambas plataformas.
            $table->foreignId('curso_id')->constrained('cursos')->cascadeOnDelete();
            // "predefinida": el Departamento Académico la asignó automáticamente
            // (alumno nuevo, primer semestre según el plan de estudio).
            // "manual": el propio alumno/departamento la dio de alta a pedido.
            $table->enum('origen', ['predefinida', 'manual'])->default('manual');
            $table->enum('estado', ['inscrita', 'retirada', 'aprobada', 'reprobada'])->default('inscrita');
            $table->date('fecha_inscripcion');
            // Estado del alta/baja efectiva en las plataformas externas —
            // permite reintentar si Canvas/Teams fallan sin bloquear la
            // matriculación académica.
            $table->timestamp('canvas_enrollment_at')->nullable();
            $table->timestamp('teams_enrollment_at')->nullable();
            $table->timestamps();

            $table->unique(['matricula_id', 'curso_id'], 'inscripcion_unica_por_matricula');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscripcion_materias');
    }
};
