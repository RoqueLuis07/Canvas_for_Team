<?php

namespace App\Models;

use Database\Factories\MatriculaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matricula extends Model
{
    /** @use HasFactory<MatriculaFactory> */
    use HasFactory;

    protected $fillable = [
        'persona_id',
        'plan_estudio_id',
        'periodo_academico_id',
        'postulacion_id',
        'estado',
        'fecha_matricula',
    ];

    protected function casts(): array
    {
        return [
            'fecha_matricula' => 'date',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function planEstudio(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class);
    }

    public function periodoAcademico(): BelongsTo
    {
        return $this->belongsTo(PeriodoAcademico::class, 'periodo_academico_id');
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function inscripciones(): HasMany
    {
        return $this->hasMany(InscripcionMateria::class);
    }

    /**
     * Es una matrícula de alumno nuevo (viene de una postulación admitida)
     * en vez de una continuación de un período anterior. Determina si el
     * Departamento Académico debe predefinirle las materias del primer
     * semestre en vez de que el alumno las elija.
     */
    public function esDeAlumnoNuevo(): bool
    {
        return $this->postulacion_id !== null;
    }
}
