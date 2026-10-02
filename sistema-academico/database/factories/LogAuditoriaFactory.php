<?php

namespace Database\Factories;

use App\Models\LogAuditoria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LogAuditoria>
 */
class LogAuditoriaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'accion' => 'curso_creado_canvas',
            'entidad_afectada' => 'Curso#1',
            'detalle' => null,
            'fecha' => now(),
        ];
    }
}
