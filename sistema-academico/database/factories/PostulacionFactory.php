<?php

namespace Database\Factories;

use App\Models\PeriodoAcademico;
use App\Models\Persona;
use App\Models\Postulacion;
use App\Models\Programa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Postulacion>
 */
class PostulacionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory(),
            'programa_id' => Programa::factory(),
            'periodo_academico_id' => PeriodoAcademico::factory(),
            'estado' => 'pendiente',
            'fecha_postulacion' => fake()->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function admitido(): static
    {
        return $this->state(['estado' => 'admitido']);
    }
}
