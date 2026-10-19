<?php

namespace App\Models;

use Database\Factories\MiembroEquipoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MiembroEquipo extends Model
{
    /** @use HasFactory<MiembroEquipoFactory> */
    use HasFactory;

    protected $table = 'miembro_equipos';

    protected $fillable = [
        'equipo_id',
        'persona_id',
        'rol_teams',
        'fecha_alta',
    ];

    protected function casts(): array
    {
        return [
            'fecha_alta' => 'datetime',
        ];
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(EquipoTeams::class, 'equipo_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }
}
