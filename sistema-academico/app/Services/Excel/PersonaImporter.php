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
 * Columnas esperadas (encabezados flexibles, ver ExcelHeaderMatcher — se
 * resuelven UNA vez contra los encabezados reales del archivo, no por fila):
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
    protected const TIPOS_VALIDOS = ['alumno', 'docente', 'administrativo', 'aspirante'];

    public function __construct(
        protected XlsxRowReader $lector,
        protected ExcelHeaderMatcher $matcher,
        protected IngresoService $ingreso,
    ) {}

    /**
     * @return array{succeeded: list<array<string, mixed>>, failed: list<array{input: array<string, mixed>, error: string}>}
     */
    public function importar(string $rutaArchivo): array
    {
        $leido = $this->lector->read($rutaArchivo, $this->matcher);
        $campos = $this->resolverCampos($leido['headers']);

        $resultado = ['succeeded' => [], 'failed' => []];

        foreach ($leido['rows'] as $fila) {
            try {
                $resultado['succeeded'][] = $this->procesarFila($fila, $campos);
            } catch (Throwable $e) {
                $resultado['failed'][] = ['input' => $fila, 'error' => $e->getMessage()];
            }
        }

        return $resultado;
    }

    /**
     * Resuelve, una sola vez por archivo, qué encabezado real corresponde a
     * cada campo esperado — en vez de volver a adivinar por fila.
     *
     * @param  array<string, int>  $headers
     * @return array<string, ?string>
     */
    protected function resolverCampos(array $headers): array
    {
        return [
            'nombre_completo' => $this->matcher->matchHeaderName($headers, 'nombre_completo', 'nombre', 'full_name', 'name'),
            'cedula' => $this->matcher->matchHeaderName($headers, 'cedula', 'ci', 'documento'),
            'email_personal' => $this->matcher->matchHeaderName($headers, 'email_personal', 'correo', 'email'),
            'tipo' => $this->matcher->matchHeaderName($headers, 'tipo', 'rol'),
            'plataforma' => $this->matcher->matchHeaderName($headers, 'plataforma', 'platform'),
        ];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, ?string>  $campos
     * @return array<string, mixed>
     */
    protected function procesarFila(array $fila, array $campos): array
    {
        $nombreCompleto = $this->valor($fila, $campos['nombre_completo']);
        $cedula = $this->valor($fila, $campos['cedula']);
        $emailPersonal = $this->valor($fila, $campos['email_personal']);
        $tipoCrudo = $this->valor($fila, $campos['tipo']);
        $plataformaCruda = $this->valor($fila, $campos['plataforma']);

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
     * @param  array<string, mixed>  $fila
     */
    protected function valor(array $fila, ?string $campo): ?string
    {
        if ($campo === null) {
            return null;
        }

        return $this->matcher->firstNonEmpty($fila, $campo);
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
