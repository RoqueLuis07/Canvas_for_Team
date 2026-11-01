<?php

namespace App\Services\Excel;

use App\Exceptions\CursoException;
use App\Models\Curso;
use App\Models\Materia;
use App\Models\PeriodoAcademico;
use App\Services\CursoService;
use Throwable;

/**
 * Importación masiva de Cursos desde un .xlsx: para cada fila (código de
 * Materia + Período Académico) crea o reutiliza el Curso correspondiente y
 * lo da de alta en Canvas y Teams vía CursoService — equivalente a
 * /excel/canvas/courses en producción.
 *
 * Columnas esperadas: Código de Materia, Período Académico (el Nombre
 * exacto del período, ej. "2026-1").
 *
 * Decisión deliberada: a diferencia de producción (que crea la Materia al
 * vuelo si no existe), este importador NO crea Materias ni Períodos
 * Académicos nuevos — solo asigna a Canvas/Teams materias y períodos que ya
 * existen en el catálogo. Crear datos académicos nuevos a partir de una
 * planilla es una decisión de alcance mayor (¿a qué Plan de Estudio
 * pertenece?, ¿qué prerrequisitos tiene?) que "Excel masivo" no debería
 * tomar en silencio; si el código/período no existe, la fila falla con un
 * mensaje claro en vez de inventar un registro académico incompleto.
 */
class CursoImporter
{
    public function __construct(
        protected XlsxRowReader $lector,
        protected ExcelHeaderMatcher $matcher,
        protected CursoService $cursos,
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
     * @param  array<string, int>  $headers
     * @return array<string, ?string>
     */
    protected function resolverCampos(array $headers): array
    {
        return [
            'codigo' => $this->matcher->matchHeaderName($headers, 'codigo_de_materia', 'codigo', 'codigo_materia'),
            'periodo' => $this->matcher->matchHeaderName($headers, 'periodo_academico', 'periodo'),
        ];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, ?string>  $campos
     * @return array<string, mixed>
     */
    protected function procesarFila(array $fila, array $campos): array
    {
        $codigo = $campos['codigo'] !== null ? $this->matcher->firstNonEmpty($fila, $campos['codigo']) : null;
        $nombrePeriodo = $campos['periodo'] !== null ? $this->matcher->firstNonEmpty($fila, $campos['periodo']) : null;

        if ($codigo === null || $nombrePeriodo === null) {
            throw new CursoException('Código de Materia y Período Académico son obligatorios.');
        }

        $materia = Materia::where('codigo', $codigo)->first();

        if ($materia === null) {
            throw new CursoException("No existe una Materia con código \"{$codigo}\" en el catálogo — no se crea automáticamente.");
        }

        $periodo = PeriodoAcademico::where('nombre', $nombrePeriodo)->first();

        if ($periodo === null) {
            throw new CursoException("No existe un Período Académico llamado \"{$nombrePeriodo}\" — no se crea automáticamente.");
        }

        $curso = Curso::firstOrCreate([
            'materia_id' => $materia->id,
            'periodo_academico_id' => $periodo->id,
        ]);

        $curso = $this->cursos->crearEnCanvas($curso);
        $curso = $this->cursos->crearEnTeams($curso);

        return [
            'id' => $curso->id,
            'materia' => $materia->nombre,
            'codigo' => $materia->codigo,
            'periodo' => $periodo->nombre,
            'canvas_course_id' => $curso->canvas_course_id,
            'teams_group_id' => $curso->teams_group_id,
        ];
    }
}
