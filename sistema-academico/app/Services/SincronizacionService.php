<?php

namespace App\Services;

use App\Models\JobSincronizacion;
use App\Models\LogAuditoria;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Envuelve una operación de sincronización con Canvas/Teams (alta de
 * usuario, creación de curso/equipo, matrícula, baja) dejando trazabilidad
 * completa: un JOB_SINCRONIZACION con su ciclo de vida (pendiente →
 * en_proceso → completado/error) y un LOG_AUDITORIA de la acción, tal como
 * exige el MER de Avance 1 y la HU-05 (historial de auditoría).
 */
class SincronizacionService
{
    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $accion
     * @return TReturn
     *
     * @throws Throwable
     */
    public function ejecutar(string $tipo, string $accionNombre, string $entidadAfectada, \Closure $accion): mixed
    {
        $usuario = Auth::user();

        $job = JobSincronizacion::create([
            'tipo' => $tipo,
            'usuario_solicitante_id' => $usuario?->id,
            'estado' => 'en_proceso',
            'fecha_inicio' => now(),
        ]);

        try {
            $resultado = $accion();

            $job->update([
                'estado' => 'completado',
                'fecha_fin' => now(),
                'resultado' => 'OK',
            ]);

            LogAuditoria::create([
                'usuario_id' => $usuario?->id,
                'accion' => $accionNombre,
                'entidad_afectada' => $entidadAfectada,
                'detalle' => null,
                'fecha' => now(),
            ]);

            return $resultado;
        } catch (Throwable $e) {
            $job->update([
                'estado' => 'error',
                'fecha_fin' => now(),
                'resultado' => $e->getMessage(),
            ]);

            LogAuditoria::create([
                'usuario_id' => $usuario?->id,
                'accion' => $accionNombre,
                'entidad_afectada' => $entidadAfectada,
                'detalle' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                'fecha' => now(),
            ]);

            throw $e;
        }
    }
}
