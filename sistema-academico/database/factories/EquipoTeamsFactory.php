<?php

namespace Database\Factories;

use App\Models\Curso;
use App\Models\EquipoTeams;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipoTeams>
 */
class EquipoTeamsFactory extends Factory
{
    public function definition(): array
    {
        return [
            'teams_group_id' => fake()->unique()->uuid(),
            'curso_id' => Curso::factory(),
            'nombre' => fake()->words(3, true),
            'visibilidad' => 'Private',
            'fecha_creacion' => now(),
        ];
    }
}
