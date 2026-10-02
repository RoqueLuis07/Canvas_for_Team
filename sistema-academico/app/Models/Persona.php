<?php

namespace App\Models;

use Database\Factories\PersonaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Persona extends Model
{
    /** @use HasFactory<PersonaFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'rol_id',
        'nombre_completo',
        'cedula',
        'email_personal',
        'email_institucional',
        'telefono',
        'tipo',
        'canvas_user_id',
        'azure_user_id',
        'estado',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    public function membresiasEquipo(): HasMany
    {
        return $this->hasMany(MiembroEquipo::class);
    }
}
