<?php

namespace Database\Factories;

use App\Models\JobSincronizacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobSincronizacion>
 */
class JobSincronizacionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tipo' => fake()->randomElement(['alta_usuario', 'crear_curso', 'crear_team', 'matricula', 'baja']),
            'estado' => 'completado',
            'fecha_inicio' => now(),
            'fecha_fin' => now(),
            'resultado' => 'OK',
        ];
    }
}
