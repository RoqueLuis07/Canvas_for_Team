<?php

namespace Database\Factories;

use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\Persona;
use App\Models\PlanEstudio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Matricula>
 */
class MatriculaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'persona_id' => Persona::factory()->alumno(),
            'plan_estudio_id' => PlanEstudio::factory(),
            'periodo_academico_id' => PeriodoAcademico::factory(),
            'postulacion_id' => null,
            'estado' => 'activa',
            'fecha_matricula' => fake()->dateTimeBetween('-1 week', 'now'),
        ];
    }
}
