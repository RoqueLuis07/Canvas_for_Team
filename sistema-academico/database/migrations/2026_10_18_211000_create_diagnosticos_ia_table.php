<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosticos_ia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_sincronizacion_id')
                ->constrained('job_sincronizacions')
                ->restrictOnDelete();
            $table->foreignId('solicitante_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 20)->default('pendiente')->index();
            $table->string('proveedor', 60)->nullable();
            $table->string('modelo', 100)->nullable();
            $table->string('version_prompt', 30);
            $table->text('prompt_sanitizado')->nullable();
            $table->json('contexto_sanitizado')->nullable();
            $table->json('respuesta')->nullable();
            $table->string('codigo_error', 60)->nullable();
            $table->string('error_saneado', 200)->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            $table->unsignedInteger('tokens_entrada')->nullable();
            $table->unsignedInteger('tokens_salida')->nullable();
            $table->timestamps();

            $table->index(['job_sincronizacion_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos_ia');
    }
};
