<?php

namespace App\Services\Excel;

use App\Exceptions\IngresoException;
use App\Models\Persona;
use App\Services\IngresoService;
use Throwable;

/**
 * Importación masiva de Personas desde un .xlsx: crea o actualiza el
 * registro de cada fila y da de alta sus cuentas institucionales (Canvas
 * y/o Teams) vía IngresoService — equivalente a /ingreso/bulk-file +
 * /excel/canvas/users en producción, pero pasando por el modelo Persona en
 * vez de crear la cuenta de plataforma sin dejar rastro en el sistema.
 *
 * Columnas esperadas (encabezados flexibles, ver ExcelHeaderMatcher):
 * Nombre Completo, Cédula, Email Personal, Tipo (alumno/docente/
 * administrativo/aspirante — "alumno" si está vacío o no se reconoce),
 * Plataforma (canvas/teams/both — "both" si está vacío).
 *
 * Simplificación deliberada vs. producción: las filas se procesan de forma
 * secuencial, no en paralelo (producción usa asyncio.gather) — el cliente
 * HTTP síncrono de Laravel y las transacciones de base de datos hacen que
 * la concurrencia real acá no valga la complejidad que agregaría.
 */
class PersonaImporter
{
    public function __construct(
        protected XlsxRowReader $lector,
        protected ExcelHeaderMatcher $matcher,
        protected IngresoService $ingreso,
    ) {}

    protected const TIPOS_VALIDOS = ['alumno', 'docente', 'administrativo', 'aspirante'];

    /**
     * @return array{succeeded: list<array<string, mixed>>, failed: list<array{input: array<string, mixed>, error: string}>}
     */
    public function importar(string $rutaArchivo): array
    {
        $leido = $this->lector->read($rutaArchivo, $this->matcher);

        $resultado = ['succeeded' => [], 'failed' => []];

        foreach ($leido['rows'] as $fila) {
            try {
                $resultado['succeeded'][] = $this->procesarFila($fila);
            } catch (Throwable $e) {
                $resultado['failed'][] = ['input' => $fila, 'error' => $e->getMessage()];
            }
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    protected function procesarFila(array $fila): array
    {
        $nombreCompleto = $this->matcher->firstNonEmpty($fila, 'nombre_completo', 'nombre', 'full_name', 'name');
        $cedula = $this->matcher->firstNonEmpty($fila, 'cedula', 'ci', 'documento');
        $emailPersonal = $this->matcher->firstNonEmpty($fila, 'email_personal', 'correo', 'email');
        $tipoCrudo = $this->matcher->firstNonEmpty($fila, 'tipo', 'rol');
        $plataformaCruda = $this->matcher->firstNonEmpty($fila, 'plataforma', 'platform');

        if ($nombreCompleto === null || $cedula === null) {
            throw new IngresoException('Nombre Completo y Cédula son obligatorios.');
        }

        $cedula = preg_replace('/[.\-\s]/', '', $cedula) ?? $cedula;

        $tipo = in_array($tipoCrudo, self::TIPOS_VALIDOS, true) ? $tipoCrudo : 'alumno';
        $plataforma = in_array($plataformaCruda, ['canvas', 'teams', 'both'], true) ? $plataformaCruda : 'both';

        $persona = Persona::query()->firstOrNew(['cedula' => $cedula]);
        $persona->nombre_completo = $nombreCompleto;
        $persona->tipo = $tipo;

        if ($emailPersonal !== null) {
            $persona->email_personal = $emailPersonal;
        }

        if (! $persona->exists) {
            $persona->estado = 'activo';
        }

        $persona->save();

        // Si ya tiene ambas cuentas, no hay nada que dar de alta — se
        // informa como éxito ("ya existía") en vez de que IngresoService
        // tire IngresoException por "ya tiene cuentas creadas".
        if ($persona->canvas_user_id && $persona->azure_user_id) {
            return $this->resumenPersona($persona, 'ya_tenia_cuentas');
        }

        $persona = $this->ingreso->darDeAlta($persona, $plataforma);

        return $this->resumenPersona($persona, 'alta_ok');
    }

    /**
     * @return array<string, mixed>
     */
    protected function resumenPersona(Persona $persona, string $resultado): array
    {
        return [
            'id' => $persona->id,
            'nombre_completo' => $persona->nombre_completo,
            'cedula' => $persona->cedula,
            'email_institucional' => $persona->email_institucional,
            'canvas_user_id' => $persona->canvas_user_id,
            'azure_user_id' => $persona->azure_user_id,
            'resultado' => $resultado,
        ];
    }
}
