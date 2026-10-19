<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contenido académico de un Curso (documentos, enlaces, tareas,
     * anuncios) que el docente carga y que se publica como página del
     * curso en Canvas.
     */
    public function up(): void
    {
        Schema::create('materiales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('curso_id')->constrained('cursos')->cascadeOnDelete();
            $table->string('titulo');
            $table->enum('tipo', ['documento', 'enlace', 'tarea', 'anuncio'])->default('documento');
            $table->text('descripcion')->nullable();
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('publicado')->default(false);
            $table->string('canvas_item_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materiales');
    }
};
