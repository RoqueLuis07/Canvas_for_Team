<?php

namespace App\Services\Teams;

use App\Contracts\TeamsClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Implementación sin efectos externos: se usa automáticamente cuando no hay
 * credenciales de Azure configuradas (entornos locales/demo, o tests), para
 * que el flujo académico completo se pueda ejercitar sin credenciales reales.
 */
class NullTeamsClient implements TeamsClient
{
    /** @var array<string, array{id: string, displayName: string, userPrincipalName: string, accountEnabled: bool}> upn (lowercase) -> usuario */
    protected static array $usersByUpn = [];

    /** @var array<string, string> nombre normalizado -> groupId */
    protected static array $groupsByName = [];

    public function createTeam(string $name): string
    {
        $id = (string) Str::uuid();

        self::$groupsByName[$this->normalizeGroupName($name)] = $id;

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

    /**
     * Sin credenciales reales no hay nada que descargar de OneDrive — en
     * vez de devolver bytes basura que rompan al lector de .xlsx río
     * abajo, se devuelve una planilla vacía (pero válida) recién generada,
     * para que el flujo completo se pueda ejercitar en modo simulado.
     */
    public function downloadSharedFile(string $shareUrl): string
    {
        Log::info("[Teams simulado] Descarga de archivo compartido simulada: {$shareUrl}");

        $path = tempnam(sys_get_temp_dir(), 'teams_simulado_').'.xlsx';

        $writer = new Writer();
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Diplomado simulado']));
        $writer->addRow(Row::fromValues(['Nombre', 'Cédula']));
        $writer->close();

        $contenido = file_get_contents($path);
        unlink($path);

        return $contenido === false ? '' : $contenido;
    }

    public function findGroupByName(string $name): ?string
    {
        return self::$groupsByName[$this->normalizeGroupName($name)] ?? null;
    }

    protected function normalizeGroupName(string $name): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;

        return strtolower(trim($transliterated));
    }
}
