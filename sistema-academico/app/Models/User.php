<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function persona(): HasOne
    {
        return $this->hasOne(Persona::class);
    }

    public function isAdminTI(): bool
    {
        return $this->persona()
            ->where('estado', 'activo')
            ->whereHas('rol', fn ($query) => $query->whereRaw('LOWER(nombre) = ?', ['admin ti']))
            ->exists();
    }

    /**
     * El acceso al panel administrativo queda restringido al personal con rol
     * administrativo institucional. Los usuarios de alumnos/docentes o usuarios
     * sin rol no pueden ingresar.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdminTI();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
