<?php

namespace App\Models;

use Database\Factories\InscripcionMateriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InscripcionMateria extends Model
{
    /** @use HasFactory<InscripcionMateriaFactory> */
    use HasFactory;

    protected $table = 'inscripcion_materias';

    protected $fillable = [
        'matricula_id',
        'curso_id',
        'origen',
        'estado',
        'fecha_inscripcion',
        'canvas_enrollment_at',
        'teams_enrollment_at',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inscripcion' => 'date',
            'canvas_enrollment_at' => 'datetime',
            'teams_enrollment_at' => 'datetime',
        ];
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function estaAlDiaEnCanvas(): bool
    {
        return $this->canvas_enrollment_at !== null;
    }

    public function estaAlDiaEnTeams(): bool
    {
        return $this->teams_enrollment_at !== null;
    }
}
