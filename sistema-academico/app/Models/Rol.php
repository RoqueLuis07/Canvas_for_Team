<?php

namespace App\Models;

use Database\Factories\RolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    /** @use HasFactory<RolFactory> */
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'nombre',
        'descripcion',
    ];

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class);
    }
}
