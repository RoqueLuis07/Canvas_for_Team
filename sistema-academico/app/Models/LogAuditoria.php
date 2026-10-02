<?php

namespace App\Models;

use Database\Factories\LogAuditoriaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogAuditoria extends Model
{
    /** @use HasFactory<LogAuditoriaFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'usuario_id',
        'accion',
        'entidad_afectada',
        'detalle',
        'fecha',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
