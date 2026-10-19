<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipo_teams', function (Blueprint $table) {
            $table->id();
            $table->string('teams_group_id', 60)->unique();
            $table->foreignId('curso_id')->unique()->constrained('cursos')->cascadeOnDelete();
            $table->string('nombre', 150);
            $table->enum('visibilidad', ['Private', 'Public'])->default('Private');
            $table->dateTime('fecha_creacion');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipo_teams');
    }
};
