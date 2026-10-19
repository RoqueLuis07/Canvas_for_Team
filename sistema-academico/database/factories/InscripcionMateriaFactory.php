<?php

namespace Database\Factories;

use App\Models\Curso;
use App\Models\InscripcionMateria;
use App\Models\Matricula;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InscripcionMateria>
 */
class InscripcionMateriaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'matricula_id' => Matricula::factory(),
            'curso_id' => Curso::factory()->creadoEnCanvasYTeams(),
            'origen' => 'manual',
            'estado' => 'inscrita',
            'fecha_inscripcion' => now(),
        ];
    }
}
