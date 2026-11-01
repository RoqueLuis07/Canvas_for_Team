<?php

namespace App\Services;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\EgresoException;
use App\Models\Persona;

/**
 * Baja de cuentas institucionales de una Persona — equivalente al módulo
 * "Egreso / Desvinculación" del sistema FastAPI en producción.
 *
 * Comportamiento por plataforma (igual al de producción):
 *   - Canvas: se ELIMINA la cuenta de la subcuenta (baja definitiva).
 *   - Teams/Azure AD: se DESHABILITA la cuenta y se le quitan las licencias
 *     asignadas — reversible, no se elimina el usuario de Entra ID.
 */
class EgresoService
{
    public function __construct(
        protected CanvasClient $canvas,
        protected TeamsClient $teams,
        protected SincronizacionService $sincronizacion,
    ) {}

    /**
     * @throws EgresoException
     */
    public function darDeBaja(Persona $persona, string $plataforma = 'both'): Persona
    {
        if (! in_array($plataforma, ['canvas', 'teams', 'both'], true)) {
            throw new EgresoException("Plataforma inválida '{$plataforma}'.");
        }

        $requiereCanvas = in_array($plataforma, ['canvas', 'both'], true);
        $requiereTeams = in_array($plataforma, ['teams', 'both'], true);

        if ($requiereCanvas && ! $persona->canvas_user_id && $requiereTeams && ! $persona->azure_user_id) {
            throw new EgresoException("{$persona->nombre_completo} no tiene cuentas institucionales creadas.");
        }

        $resultadosPorPlataforma = [];
        $plataformaActual = 'canvas';

        $this->sincronizacion->ejecutar(
            'egreso', 'egreso_baja_cuentas', "Persona#{$persona->id}",
            function () use ($persona, $requiereCanvas, $requiereTeams, &$resultadosPorPlataforma, &$plataformaActual) {
                if ($requiereCanvas && $persona->canvas_user_id) {
                    try {
                        $this->canvas->deleteUser($persona->canvas_user_id);
                        $persona->canvas_user_id = null;
                        $resultadosPorPlataforma['canvas'] = ['estado' => 'completado', 'resultado' => 'baja_ok'];
                    } catch (\Throwable $e) {
                        $resultadosPorPlataforma['canvas'] = ['estado' => 'error', 'error' => $e->getMessage()];

                        throw $e;
                    }
                }

                $plataformaActual = 'teams';

                if ($requiereTeams && $persona->azure_user_id) {
                    try {
                        $this->teams->disableUser($persona->azure_user_id);
                        $licenciasRemovidas = $this->teams->removeAllLicenses($persona->azure_user_id);
                        $resultadosPorPlataforma['teams'] = [
                            'estado' => 'completado',
                            'resultado' => 'suspendido ('.count($licenciasRemovidas).' licencia(s) removida(s))',
                        ];
                    } catch (\Throwable $e) {
                        $resultadosPorPlataforma['teams'] = ['estado' => 'error', 'error' => $e->getMessage()];

                        throw $e;
                    }
                }

                $persona->estado = 'inactivo';
                $persona->save();

                $this->sincronizacion->registrarAuditoriaPorPlataforma(
                    'egreso_baja_cuentas',
                    "Persona#{$persona->id}",
                    $resultadosPorPlataforma,
                    [
                        'estado' => $persona->estado,
                        'canvas_user_id' => $persona->canvas_user_id,
                        'azure_user_id' => $persona->azure_user_id,
                    ],
                );
            },
        );

        return $persona->refresh();
    }
}
