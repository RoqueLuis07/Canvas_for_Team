<?php

namespace Database\Factories;

use App\Models\EquipoTeams;
use App\Models\MiembroEquipo;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MiembroEquipo>
 */
class MiembroEquipoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'equipo_id' => EquipoTeams::factory(),
            'persona_id' => Persona::factory(),
            'rol_teams' => 'Member',
            'fecha_alta' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(['rol_teams' => 'Owner']);
    }
}
