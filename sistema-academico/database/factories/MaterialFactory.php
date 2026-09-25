<?php

namespace Database\Factories;

use App\Models\Curso;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    public function definition(): array
    {
        return [
            'curso_id' => Curso::factory(),
            'titulo' => fake()->sentence(4),
            'tipo' => 'documento',
            'descripcion' => fake()->paragraph(),
            'orden' => 0,
            'publicado' => false,
        ];
    }

    public function publicadoEnCanvas(): static
    {
        return $this->state([
            'publicado' => true,
            'canvas_item_id' => fake()->numerify('####'),
        ]);
    }
}
