<?php

namespace App\Models;

use Database\Factories\ProgramaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Programa extends Model
{
    /** @use HasFactory<ProgramaFactory> */
    use HasFactory;

    protected $fillable = [
        'codigo',
        'nombre',
        'tipo',
        'sede',
        'canvas_account_id',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function planesEstudio(): HasMany
    {
        return $this->hasMany(PlanEstudio::class);
    }

    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class);
    }
}
