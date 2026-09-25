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
}
