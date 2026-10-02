<?php

namespace Database\Factories;

use App\Models\Curso;
use App\Models\Materia;
use App\Models\PeriodoAcademico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Curso>
 */
class CursoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'materia_id' => Materia::factory(),
            'periodo_academico_id' => PeriodoAcademico::factory(),
            'cupo_maximo' => fake()->randomElement([30, 40, 50, null]),
            'estado' => 'planificado',
        ];
    }

    public function creadoEnCanvasYTeams(): static
    {
        return $this->state([
            'canvas_course_id' => fake()->numerify('####'),
            'teams_group_id' => fake()->uuid(),
            'estado' => 'publicado',
        ]);
    }
}
