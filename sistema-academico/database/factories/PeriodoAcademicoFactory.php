<?php

namespace Database\Factories;

use App\Models\PeriodoAcademico;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodoAcademico>
 */
class PeriodoAcademicoFactory extends Factory
{
    public function definition(): array
    {
        $inicio = fake()->dateTimeBetween('now', '+2 months');

        return [
            'nombre' => fake()->unique()->numerify('20##-#'),
            'fecha_inicio' => $inicio,
            'fecha_fin' => (clone $inicio)->modify('+4 months'),
            'fecha_limite_inscripcion_materias' => (clone $inicio)->modify('+2 weeks'),
            'estado' => 'planificado',
        ];
    }
}
