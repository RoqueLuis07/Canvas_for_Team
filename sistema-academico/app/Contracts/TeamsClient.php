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

    /**
     * Busca un usuario en Azure AD por su userPrincipalName (correo
     * institucional).
     *
     * @return array{id: string, displayName: string, userPrincipalName: string, accountEnabled: bool}|null
     */
    public function findUserByUpn(string $upn): ?array;

    /**
     * Crea un usuario en Azure AD. La cédula se guarda en el campo
     * `postalCode` — Azure AD no tiene un campo nativo de documento de
     * identidad, así que se reutiliza ese campo libre para poder cruzar
     * cuentas por cédula más adelante (igual que el SIS ID en Canvas).
     *
     * @param  array{display_name: string, upn: string, mail_nickname: string, password: string, cedula: ?string}  $datos
     * @return string El azure_user_id creado.
     */
    public function createUser(array $datos): string;

    /**
     * Deshabilita una cuenta en Azure AD (baja reversible — no elimina el
     * usuario de Entra ID, a diferencia de la baja en Canvas).
     */
    public function disableUser(string $azureUserId): void;

    /**
     * Quita todas las licencias asignadas a un usuario — una cuenta
     * deshabilitada no debe seguir ocupando un asiento pago.
     *
     * @return list<string> Los SKU IDs removidos.
     */
    public function removeAllLicenses(string $azureUserId): array;
}
