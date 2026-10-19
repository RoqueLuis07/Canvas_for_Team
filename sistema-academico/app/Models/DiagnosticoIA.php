<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiagnosticoIA extends Model
{
    protected $table = 'diagnosticos_ia';

    protected $fillable = [
        'job_sincronizacion_id',
        'solicitante_id',
        'estado',
        'proveedor',
        'modelo',
        'version_prompt',
        'prompt_sanitizado',
        'contexto_sanitizado',
        'respuesta',
        'codigo_error',
        'error_saneado',
        'duracion_ms',
        'tokens_entrada',
        'tokens_salida',
    ];

    protected function casts(): array
    {
        return [
            'contexto_sanitizado' => 'array',
            'respuesta' => 'array',
        ];
    }

    public function jobSincronizacion(): BelongsTo
    {
        return $this->belongsTo(JobSincronizacion::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    public function logsAuditoria(): HasMany
    {
        return $this->hasMany(LogAuditoria::class, 'diagnostico_ia_id');
    }
}
