<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materia_prerequisito', function (Blueprint $table) {
            $table->foreignId('materia_id')->constrained('materias')->cascadeOnDelete();
            $table->foreignId('prerequisito_id')->constrained('materias')->cascadeOnDelete();

            $table->primary(['materia_id', 'prerequisito_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('materia_prerequisito');
    }
};
