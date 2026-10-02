<?php

namespace Database\Factories;

use App\Models\Materia;
use App\Models\PlanEstudio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Materia>
 */
class MateriaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'plan_estudio_id' => PlanEstudio::factory(),
            'codigo' => strtoupper(fake()->unique()->bothify('MAT-###')),
            'nombre' => fake()->unique()->randomElement([
                'Introducción a la Programación', 'Matemática I', 'Álgebra Lineal',
                'Estructuras de Datos', 'Bases de Datos I', 'Redes de Computadoras',
                'Ingeniería de Software', 'Cálculo I', 'Física I', 'Contabilidad General',
            ]),
            'creditos' => fake()->numberBetween(2, 6),
            'semestre_sugerido' => fake()->numberBetween(1, 8),
            'activa' => true,
        ];
    }

    public function primerSemestre(): static
    {
        return $this->state(['semestre_sugerido' => 1]);
    }
}
