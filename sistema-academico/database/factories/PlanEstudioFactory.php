<?php

namespace Database\Factories;

use App\Models\PlanEstudio;
use App\Models\Programa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanEstudio>
 */
class PlanEstudioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'programa_id' => Programa::factory(),
            'nombre' => 'Plan de Estudio '.fake()->year(),
            'version' => 1,
            'vigente_desde' => fake()->dateTimeBetween('-2 years', 'now'),
            'vigente_hasta' => null,
            'activo' => true,
        ];
    }
}
