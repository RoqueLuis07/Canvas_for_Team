<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cursos', function (Blueprint $table) {
            $table->foreignId('subcuenta_id')->nullable()->after('materia_id')->constrained('subcuentas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cursos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcuenta_id');
        });
    }
};
