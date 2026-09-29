<?php

namespace App\Models;

use Database\Factories\JobSincronizacionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobSincronizacion extends Model
{
    /** @use HasFactory<JobSincronizacionFactory> */
    use HasFactory;

    protected $fillable = [
        'tipo',
        'usuario_solicitante_id',
        'estado',
        'fecha_inicio',
        'fecha_fin',
        'resultado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
        ];
    }

    public function usuarioSolicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_solicitante_id');
    }
}
