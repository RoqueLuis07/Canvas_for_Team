<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $table = 'materiales';

    protected $fillable = [
        'curso_id',
        'titulo',
        'tipo',
        'descripcion',
        'url',
        'orden',
        'publicado',
        'canvas_item_id',
    ];

    protected function casts(): array
    {
        return [
            'publicado' => 'boolean',
        ];
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function estaPublicadoEnCanvas(): bool
    {
        return $this->canvas_item_id !== null;
    }
}
