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
