<?php

namespace Database\Factories;

use App\Models\Programa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Programa>
 */
class ProgramaFactory extends Factory
{
    public function definition(): array
    {
        $nombre = fake()->unique()->randomElement([
            'Ingeniería en Informática',
            'Administración de Empresas',
            'Diplomado en Gestión Logística Integral',
            'Diplomado en Finanzas y Evaluación Estratégica de Inversiones',
        ]);

        return [
            'codigo' => strtoupper(fake()->unique()->bothify('??-###')),
            'nombre' => $nombre,
            'tipo' => str_contains($nombre, 'Diplomado') ? 'diplomado' : 'grado',
            'sede' => 'Asunción',
            'activo' => true,
        ];
    }
}
