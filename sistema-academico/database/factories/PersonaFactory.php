<?php

namespace Database\Factories;

use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Persona>
 */
class PersonaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nombre_completo' => fake()->name(),
            'cedula' => fake()->unique()->numerify('#######'),
            'email_personal' => fake()->unique()->safeEmail(),
            'telefono' => fake()->phoneNumber(),
            'tipo' => 'aspirante',
        ];
    }

    public function alumno(): static
    {
        return $this->state(['tipo' => 'alumno']);
    }

    public function docente(): static
    {
        return $this->state(['tipo' => 'docente']);
    }
}
