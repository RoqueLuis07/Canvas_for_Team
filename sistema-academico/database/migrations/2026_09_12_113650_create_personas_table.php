<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            // Vínculo opcional al usuario del sistema (login) — nulo mientras
            // la persona es solo un aspirante sin credenciales todavía; se
            // completa recién al ser admitido (mismo patrón que el alta de
            // credenciales del sistema FastAPI Canvas↔Teams).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('nombre_completo');
            $table->string('cedula')->unique();
            $table->string('email_personal')->nullable();
            $table->string('telefono')->nullable();
            $table->enum('tipo', ['aspirante', 'alumno', 'docente', 'administrativo'])->default('aspirante');
            // Campos de integración con el sistema FastAPI de Canvas LMS /
            // Microsoft Teams — se completan cuando esta persona pasa a
            // tener cuenta institucional activa.
            $table->string('canvas_user_id')->nullable();
            $table->string('azure_user_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
