<?php

namespace App\Services\Canvas;

use App\Contracts\CanvasClient;
use Illuminate\Support\Facades\Log;

/**
 * Implementación sin efectos externos: se usa automáticamente cuando no hay
 * CANVAS_ACCESS_TOKEN configurado (entornos locales/demo, o tests), para que
 * el flujo académico completo se pueda ejercitar sin credenciales reales.
 */
class NullCanvasClient implements CanvasClient
{
    protected static int $nextCourseId = 1000;

    protected static int $nextPageId = 5000;

    protected static int $nextUserId = 9000;

    /** @var array<string, array{id: string, name: string, login_id: string, email: ?string, sis_user_id: string}> */
    protected static array $usersBySisId = [];

    /** @var array<string, string> login_id (lowercase) -> sis_user_id */
    protected static array $sisIdByLoginId = [];

    public function createCourse(string $name, string $sisCourseId): string
    {
        Log::info("[Canvas simulado] Curso creado: {$name} ({$sisCourseId})");

        return (string) self::$nextCourseId++;
    }

    public function enrollUser(string $canvasCourseId, string $canvasUserId, string $role = 'StudentEnrollment'): void
    {
        Log::info("[Canvas simulado] Usuario {$canvasUserId} matriculado en curso {$canvasCourseId} como {$role}");
    }

    public function unenrollUser(string $canvasCourseId, string $canvasUserId): void
    {
        Log::info("[Canvas simulado] Usuario {$canvasUserId} dado de baja del curso {$canvasCourseId}");
    }

    public function createPage(string $canvasCourseId, string $title, string $body): string
    {
        Log::info("[Canvas simulado] Página \"{$title}\" publicada en curso {$canvasCourseId}");

        return (string) self::$nextPageId++;
    }

    public function findUserBySisId(string $sisUserId): ?array
    {
        return self::$usersBySisId[$sisUserId] ?? null;
    }

    public function findUserByLoginId(string $loginId): ?array
    {
        $sisUserId = self::$sisIdByLoginId[strtolower($loginId)] ?? null;

        return $sisUserId !== null ? (self::$usersBySisId[$sisUserId] ?? null) : null;
    }

    public function createUser(array $datos): string
    {
        $id = (string) self::$nextUserId++;

        self::$usersBySisId[$datos['sis_user_id']] = [
            'id' => $id,
            'name' => $datos['full_name'],
            'login_id' => $datos['login_id'],
            'email' => $datos['email'],
            'sis_user_id' => $datos['sis_user_id'],
        ];
        self::$sisIdByLoginId[strtolower($datos['login_id'])] = $datos['sis_user_id'];

        Log::info("[Canvas simulado] Usuario creado: {$datos['full_name']} ({$datos['login_id']})");

        return $id;
    }

    public function deleteUser(string $canvasUserId): void
    {
        foreach (self::$usersBySisId as $sisUserId => $user) {
            if ($user['id'] === $canvasUserId) {
                unset(self::$usersBySisId[$sisUserId]);
                unset(self::$sisIdByLoginId[strtolower($user['login_id'])]);
                break;
            }
        }

        Log::info("[Canvas simulado] Usuario {$canvasUserId} eliminado");
    }
}
