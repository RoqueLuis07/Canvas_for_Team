<?php

namespace App\Models;

use Database\Factories\PostulacionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Postulacion extends Model
{
    /** @use HasFactory<PostulacionFactory> */
    use HasFactory;

    protected $table = 'postulaciones';

    protected $fillable = [
        'persona_id',
        'programa_id',
        'periodo_academico_id',
        'estado',
        'fecha_postulacion',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_postulacion' => 'date',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function programa(): BelongsTo
    {
        return $this->belongsTo(Programa::class);
    }

    public function periodoAcademico(): BelongsTo
    {
        return $this->belongsTo(PeriodoAcademico::class, 'periodo_academico_id');
    }

    public function matricula(): HasOne
    {
        return $this->hasOne(Matricula::class);
    }
}
