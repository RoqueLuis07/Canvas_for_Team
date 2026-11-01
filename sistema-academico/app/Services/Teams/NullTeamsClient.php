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
    /** @var array<string, array{id: string, displayName: string, userPrincipalName: string, accountEnabled: bool}> upn (lowercase) -> usuario */
    protected static array $usersByUpn = [];

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

    public function findUserByUpn(string $upn): ?array
    {
        return self::$usersByUpn[strtolower($upn)] ?? null;
    }

    public function createUser(array $datos): string
    {
        $id = (string) Str::uuid();

        self::$usersByUpn[strtolower($datos['upn'])] = [
            'id' => $id,
            'displayName' => $datos['display_name'],
            'userPrincipalName' => $datos['upn'],
            'accountEnabled' => true,
        ];

        Log::info("[Teams simulado] Usuario creado: {$datos['display_name']} ({$datos['upn']})");

        return $id;
    }

    public function disableUser(string $azureUserId): void
    {
        foreach (self::$usersByUpn as $upn => $user) {
            if ($user['id'] === $azureUserId) {
                self::$usersByUpn[$upn]['accountEnabled'] = false;
                break;
            }
        }

        Log::info("[Teams simulado] Usuario {$azureUserId} deshabilitado");
    }

    public function removeAllLicenses(string $azureUserId): array
    {
        Log::info("[Teams simulado] Licencias removidas del usuario {$azureUserId}");

        return [];
    }
}
