<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('miembro_equipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipo_id')->constrained('equipo_teams')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->enum('rol_teams', ['Owner', 'Member']);
            $table->dateTime('fecha_alta');
            $table->timestamps();

            $table->unique(['equipo_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('miembro_equipos');
    }
};
