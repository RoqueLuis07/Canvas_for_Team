<?php

namespace App\Services\Teams;

use App\Contracts\TeamsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Implementación sin efectos externos: se usa automáticamente cuando no hay
 * credenciales de Azure configuradas (entornos locales/demo, o tests), para
 * que el flujo académico completo se pueda ejercitar sin credenciales reales.
 */
class NullTeamsClient implements TeamsClient
{
    public function createTeam(string $name): string
    {
        $id = (string) Str::uuid();

        Log::info("[Teams simulado] Equipo creado: {$name} ({$id})");

        return $id;
    }

    public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void
    {
        Log::info("[Teams simulado] Usuario {$azureUserId} agregado al equipo {$teamsGroupId} como {$role}");
    }

    public function removeMember(string $teamsGroupId, string $azureUserId): void
    {
        Log::info("[Teams simulado] Usuario {$azureUserId} quitado del equipo {$teamsGroupId}");
    }
}
