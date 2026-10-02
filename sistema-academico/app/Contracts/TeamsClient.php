<?php

namespace App\Contracts;

interface TeamsClient
{
    /**
     * Crea un equipo de Microsoft Teams (grupo M365 + Team).
     *
     * @return string El teams_group_id creado.
     */
    public function createTeam(string $name): string;

    /**
     * Agrega a un usuario ya existente en Azure AD como miembro del equipo.
     */
    public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void;

    /**
     * Quita a un usuario del equipo.
     */
    public function removeMember(string $teamsGroupId, string $azureUserId): void;
}
