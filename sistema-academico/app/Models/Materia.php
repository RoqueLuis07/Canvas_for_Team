<?php

namespace App\Models;

use Database\Factories\MateriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Materia extends Model
{
    /** @use HasFactory<MateriaFactory> */
    use HasFactory;

    protected $fillable = [
        'plan_estudio_id',
        'codigo',
        'nombre',
        'creditos',
        'semestre_sugerido',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function planEstudio(): BelongsTo
    {
        return $this->belongsTo(PlanEstudio::class);
    }

    /**
     * Materias que esta materia exige como prerrequisito.
     */
    public function prerequisitos(): BelongsToMany
    {
        return $this->belongsToMany(Materia::class, 'materia_prerequisito', 'materia_id', 'prerequisito_id');
    }

    /**
     * Materias para las que esta materia es prerrequisito.
     */
    public function esPrerequisitoDe(): BelongsToMany
    {
        return $this->belongsToMany(Materia::class, 'materia_prerequisito', 'prerequisito_id', 'materia_id');
    }

    /**
     * Ofertas concretas de esta materia por período (las que efectivamente
     * se crean como curso en Canvas / equipo en Teams).
     */
    public function cursos(): HasMany
    {
        return $this->hasMany(Curso::class);
    }
}
