<?php

namespace App\Services\Teams;

use App\Contracts\TeamsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpTeamsClient implements TeamsClient
{
    protected const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    public function createTeam(string $name): string
    {
        $nickname = $this->nicknameFor($name);

        $group = $this->client()->post(self::GRAPH_BASE_URL.'/groups', [
            'displayName' => $name,
            'description' => "Equipo para {$name}",
            'mailNickname' => $nickname,
            'mailEnabled' => false,
            'securityEnabled' => false,
            'groupTypes' => ['Unified'],
            'visibility' => 'Private',
        ])->throw()->json();

        $groupId = $group['id'];

        // Convertir el grupo M365 recién creado en un Team propiamente dicho.
        $this->client()->put(self::GRAPH_BASE_URL."/groups/{$groupId}/team", [
            'memberSettings' => ['allowCreatePrivateChannels' => true],
        ])->throw();

        return $groupId;
    }

    public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void
    {
        $endpoint = $role === 'Owner' ? 'owners' : 'members';

        $this->client()->post(self::GRAPH_BASE_URL."/groups/{$teamsGroupId}/{$endpoint}/\$ref", [
            '@odata.id' => "https://graph.microsoft.com/v1.0/directoryObjects/{$azureUserId}",
        ])->throw();
    }

    public function removeMember(string $teamsGroupId, string $azureUserId): void
    {
        $this->client()->delete(self::GRAPH_BASE_URL."/groups/{$teamsGroupId}/members/{$azureUserId}/\$ref")->throw();
    }

    public function findUserByUpn(string $upn): ?array
    {
        $response = $this->client()->get(self::GRAPH_BASE_URL."/users/{$upn}", [
            '$select' => 'id,displayName,userPrincipalName,accountEnabled',
        ]);

        if ($response->status() === 404) {
            return null;
        }

        $user = $response->throw()->json();

        return [
            'id' => (string) $user['id'],
            'displayName' => (string) ($user['displayName'] ?? ''),
            'userPrincipalName' => (string) ($user['userPrincipalName'] ?? ''),
            'accountEnabled' => (bool) ($user['accountEnabled'] ?? false),
        ];
    }

    public function createUser(array $datos): string
    {
        $payload = [
            'accountEnabled' => true,
            'displayName' => $datos['display_name'],
            'mailNickname' => $datos['mail_nickname'],
            'userPrincipalName' => $datos['upn'],
            'passwordProfile' => [
                'forceChangePasswordNextSignIn' => false,
                'password' => $datos['password'],
            ],
        ];

        if (! empty($datos['cedula'])) {
            $payload['postalCode'] = $datos['cedula'];
        }

        $response = $this->client()->post(self::GRAPH_BASE_URL.'/users', $payload)->throw()->json();

        return (string) $response['id'];
    }

    public function disableUser(string $azureUserId): void
    {
        $this->client()->patch(self::GRAPH_BASE_URL."/users/{$azureUserId}", [
            'accountEnabled' => false,
        ])->throw();
    }

    public function removeAllLicenses(string $azureUserId): array
    {
        $licencias = $this->client()
            ->get(self::GRAPH_BASE_URL."/users/{$azureUserId}/licenseDetails")
            ->throw()
            ->json('value', []);

        $skuIds = array_values(array_map(fn (array $licencia) => (string) $licencia['skuId'], $licencias));

        if ($skuIds === []) {
            return [];
        }

        $this->client()->post(self::GRAPH_BASE_URL."/users/{$azureUserId}/assignLicense", [
            'addLicenses' => [],
            'removeLicenses' => $skuIds,
        ])->throw();

        return $skuIds;
    }

    /**
     * Codifica una URL de enlace compartido de OneDrive/SharePoint al
     * formato que exige la API "shares" de Graph (`u!<base64url sin
     * padding>`). Puerto de `_encode_share_url` de producción.
     */
    protected function encodeShareUrl(string $shareUrl): string
    {
        $base64 = base64_encode($shareUrl);
        $base64Url = rtrim(strtr($base64, '+/', '-_'), '=');

        return "u!{$base64Url}";
    }

    public function downloadSharedFile(string $shareUrl): string
    {
        $encoded = $this->encodeShareUrl($shareUrl);

        return $this->client()
            ->get(self::GRAPH_BASE_URL."/shares/{$encoded}/driveItem/content")
            ->throw()
            ->body();
    }

    /**
     * Simplificación deliberada vs. producción: Graph no soporta un filtro
     * de coincidencia EXACTA sobre `displayName` de forma confiable, así
     * que producción ya hacía un `$filter=startswith(...)` + comparación
     * exacta del lado del cliente. Acá se omite la optimización
     * `startswith` (que solo acota la respuesta del servidor) y se trae la
     * lista completa de grupos, filtrando exacto en PHP — mismo resultado,
     * una llamada HTTP menos compleja.
     */
    public function findGroupByName(string $name): ?string
    {
        $objetivo = $this->normalizeGroupName($name);

        $grupos = $this->client()
            ->get(self::GRAPH_BASE_URL.'/groups', ['$select' => 'id,displayName'])
            ->throw()
            ->json('value', []);

        foreach ($grupos as $grupo) {
            if ($this->normalizeGroupName((string) ($grupo['displayName'] ?? '')) === $objetivo) {
                return (string) $grupo['id'];
            }
        }

        return null;
    }

    /**
     * Puerto de `list_disabled_users_with_licenses` de producción: cuentas
     * deshabilitadas con licencias asignadas, resolviendo cada SKU ID a un
     * nombre legible vía /subscribedSkus (una sola llamada extra, cacheada
     * dentro de este mismo request).
     */
    public function listOrphanedLicenses(): array
    {
        $usuarios = $this->client()
            ->get(self::GRAPH_BASE_URL.'/users', [
                '$select' => 'id,displayName,userPrincipalName,accountEnabled,assignedLicenses',
                '$filter' => 'accountEnabled eq false',
            ])
            ->throw()
            ->json('value', []);

        $conLicencia = array_values(array_filter($usuarios, fn (array $u) => ! empty($u['assignedLicenses'])));

        if ($conLicencia === []) {
            return [];
        }

        $skus = $this->client()
            ->get(self::GRAPH_BASE_URL.'/subscribedSkus')
            ->throw()
            ->json('value', []);

        $nombresPorSku = [];
        foreach ($skus as $sku) {
            if (! empty($sku['skuId'])) {
                $nombresPorSku[$sku['skuId']] = $sku['skuPartNumber'] ?? $sku['skuId'];
            }
        }

        return array_map(function (array $u) use ($nombresPorSku): array {
            return [
                'id' => (string) $u['id'],
                'display_name' => (string) ($u['displayName'] ?? ''),
                'upn' => (string) ($u['userPrincipalName'] ?? ''),
                'licenses' => array_map(
                    fn (array $lic) => $nombresPorSku[$lic['skuId'] ?? ''] ?? ($lic['skuId'] ?? ''),
                    $u['assignedLicenses'] ?? [],
                ),
            ];
        }, $conLicencia);
    }

    /**
     * Puerto de `get_inactive_users` de producción: cuentas habilitadas sin
     * inicio de sesión en los últimos $minDaysInactive días (o que nunca
     * iniciaron sesión), vía el campo `signInActivity` de Graph.
     */
    public function listInactiveUsers(int $minDaysInactive): array
    {
        $usuarios = $this->client()
            ->get(self::GRAPH_BASE_URL.'/users', [
                '$select' => 'id,displayName,userPrincipalName,accountEnabled,signInActivity',
                '$filter' => 'accountEnabled eq true',
            ])
            ->throw()
            ->json('value', []);

        $limite = now()->subDays($minDaysInactive);
        $inactivos = [];

        foreach ($usuarios as $u) {
            $ultimoAcceso = $u['signInActivity']['lastSignInDateTime'] ?? null;

            if ($ultimoAcceso === null) {
                $inactivos[] = $this->mapInactiveUser($u, null);

                continue;
            }

            try {
                if (\Illuminate\Support\Carbon::parse($ultimoAcceso)->lt($limite)) {
                    $inactivos[] = $this->mapInactiveUser($u, $ultimoAcceso);
                }
            } catch (\Throwable) {
                // Fecha no parseable: se ignora la fila, igual que producción
                // descarta silenciosamente cualquier signInActivity inválido.
            }
        }

        return $inactivos;
    }

    /**
     * @return array{id: string, display_name: string, upn: string, last_signin: ?string}
     */
    protected function mapInactiveUser(array $u, ?string $lastSignIn): array
    {
        return [
            'id' => (string) $u['id'],
            'display_name' => (string) ($u['displayName'] ?? ''),
            'upn' => (string) ($u['userPrincipalName'] ?? ''),
            'last_signin' => $lastSignIn,
        ];
    }

    /**
     * Normaliza un nombre de equipo para comparar sin importar
     * tildes/mayúsculas/espacios en las puntas. Puerto de
     * `_norm_group_name` de producción.
     */
    protected function normalizeGroupName(string $name): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;

        return strtolower(trim($transliterated));
    }

    protected function nicknameFor(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9]+/', '_', $name);

        return trim($ascii, '_');
    }

    protected function client()
    {
        return Http::withToken($this->accessToken())->acceptJson();
    }

    protected function accessToken(): string
    {
        return Cache::remember('teams_client.graph_token', 3000, function () {
            $tenantId = config('services.azure.tenant_id');
            $clientId = config('services.azure.client_id');
            $clientSecret = config('services.azure.client_secret');

            if (! $tenantId || ! $clientId || ! $clientSecret) {
                throw new RuntimeException('AZURE_TENANT_ID/CLIENT_ID/CLIENT_SECRET no están configurados.');
            }

            $response = Http::asForm()->post("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token", [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ])->throw()->json();

            return $response['access_token'];
        });
    }
}
