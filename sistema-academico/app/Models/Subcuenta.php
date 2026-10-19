<?php

namespace App\Models;

use Database\Factories\SubcuentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subcuenta extends Model
{
    /** @use HasFactory<SubcuentaFactory> */
    use HasFactory;

    protected $fillable = [
        'canvas_account_id',
        'nombre',
        'carrera',
        'sede',
    ];

    public function cursos(): HasMany
    {
        return $this->hasMany(Curso::class);
    }
}
