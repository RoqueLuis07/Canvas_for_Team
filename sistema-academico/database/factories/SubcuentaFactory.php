<?php

namespace Database\Factories;

use App\Models\Subcuenta;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subcuenta>
 */
class SubcuentaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'canvas_account_id' => fake()->unique()->numberBetween(1000, 9999),
            'nombre' => fake()->randomElement(['Facultad de Ingeniería', 'Facultad de Negocios', 'UBS Business School']),
            'carrera' => fake()->randomElement(['Ingeniería en Informática', 'Administración de Empresas', null]),
            'sede' => 'USIL Paraguay',
        ];
    }
}
