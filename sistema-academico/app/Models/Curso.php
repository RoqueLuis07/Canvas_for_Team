<?php

namespace App\Models;

use Database\Factories\CursoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Curso extends Model
{
    /** @use HasFactory<CursoFactory> */
    use HasFactory;

    protected $fillable = [
        'materia_id',
        'subcuenta_id',
        'periodo_academico_id',
        'docente_persona_id',
        'cupo_maximo',
        'canvas_course_id',
        'teams_group_id',
        'docente_canvas_at',
        'docente_teams_at',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'docente_canvas_at' => 'datetime',
            'docente_teams_at' => 'datetime',
        ];
    }

    public function materia(): BelongsTo
    {
        return $this->belongsTo(Materia::class);
    }

    public function subcuenta(): BelongsTo
    {
        return $this->belongsTo(Subcuenta::class);
    }

    public function equipoTeams(): HasOne
    {
        return $this->hasOne(EquipoTeams::class);
    }

    public function periodoAcademico(): BelongsTo
    {
        return $this->belongsTo(PeriodoAcademico::class, 'periodo_academico_id');
    }

    public function docente(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'docente_persona_id');
    }

    public function inscripciones(): HasMany
    {
        return $this->hasMany(InscripcionMateria::class);
    }

    public function materiales(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    public function estaCreadoEnCanvas(): bool
    {
        return $this->canvas_course_id !== null;
    }

    public function estaCreadoEnTeams(): bool
    {
        return $this->teams_group_id !== null;
    }

    public function tieneDocenteAsignado(): bool
    {
        return $this->docente_persona_id !== null;
    }

    public function docenteEstaAlDiaEnCanvas(): bool
    {
        return $this->docente_canvas_at !== null;
    }

    public function docenteEstaAlDiaEnTeams(): bool
    {
        return $this->docente_teams_at !== null;
    }

    /**
     * Cuántos cupos quedan libres, contando solo inscripciones activas
     * (no retiradas). Null = sin límite de cupo.
     */
    public function cupoDisponible(): ?int
    {
        if ($this->cupo_maximo === null) {
            return null;
        }

        $ocupados = $this->inscripciones()->where('estado', '!=', 'retirada')->count();

        return max(0, $this->cupo_maximo - $ocupados);
    }
}
