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
        return $this->hasActiveRole('Admin TI');
    }

    public function isDocente(): bool
    {
        return $this->hasActiveRole('Docente');
    }

    public function isEstudiante(): bool
    {
        return $this->hasActiveRole('Estudiante');
    }

    public function hasActiveRole(string $roleName): bool
    {
        return $this->persona()
            ->where('estado', 'activo')
            ->whereHas('rol', fn ($query) => $query->whereRaw('LOWER(nombre) = ?', [mb_strtolower($roleName)]))
            ->exists();
    }

    public function activePersonaIdForRole(string $roleName): ?int
    {
        $id = $this->persona()
            ->where('estado', 'activo')
            ->whereHas('rol', fn ($query) => $query->whereRaw('LOWER(nombre) = ?', [mb_strtolower($roleName)]))
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * El acceso al panel requiere un rol institucional activo reconocido.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdminTI() || $this->isDocente() || $this->isEstudiante();
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
