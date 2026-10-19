<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->foreignId('rol_id')->nullable()->after('user_id')->constrained('roles')->nullOnDelete();
            $table->string('email_institucional', 150)->nullable()->unique()->after('email_personal');
            $table->enum('estado', ['activo', 'inactivo'])->default('activo')->after('azure_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rol_id');
            $table->dropColumn(['email_institucional', 'estado']);
        });
    }
};
