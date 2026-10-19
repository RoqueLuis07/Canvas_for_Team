<?php

namespace App\Models;

use Database\Factories\EquipoTeamsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipoTeams extends Model
{
    /** @use HasFactory<EquipoTeamsFactory> */
    use HasFactory;

    protected $table = 'equipo_teams';

    protected $fillable = [
        'teams_group_id',
        'curso_id',
        'nombre',
        'visibilidad',
        'fecha_creacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_creacion' => 'datetime',
        ];
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function miembros(): HasMany
    {
        return $this->hasMany(MiembroEquipo::class, 'equipo_id');
    }
}
