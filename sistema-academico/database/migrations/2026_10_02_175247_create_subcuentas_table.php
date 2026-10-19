<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subcuentas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('canvas_account_id')->unique();
            $table->string('nombre', 120);
            $table->string('carrera', 120)->nullable();
            $table->string('sede', 60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subcuentas');
    }
};
