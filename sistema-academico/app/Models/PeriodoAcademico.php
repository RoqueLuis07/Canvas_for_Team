<?php

namespace App\Models;

use Database\Factories\PeriodoAcademicoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodoAcademico extends Model
{
    /** @use HasFactory<PeriodoAcademicoFactory> */
    use HasFactory;

    protected $table = 'periodos_academicos';

    protected $fillable = [
        'nombre',
        'fecha_inicio',
        'fecha_fin',
        'fecha_limite_inscripcion_materias',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_limite_inscripcion_materias' => 'date',
        ];
    }

    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class, 'periodo_academico_id');
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class, 'periodo_academico_id');
    }
}
