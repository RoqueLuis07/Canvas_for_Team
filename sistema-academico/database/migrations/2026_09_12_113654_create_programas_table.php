<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->enum('tipo', ['grado', 'mba', 'diplomado'])->default('grado');
            $table->string('sede')->nullable();
            // IDs de las plataformas externas con las que este programa ya
            // se sincroniza hoy (Canvas subcuenta / Teams), para la futura
            // integración con el sistema FastAPI de Canvas↔Teams.
            $table->string('canvas_account_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programas');
    }
};
