<?php

namespace App\Services;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\InscripcionMateriaException;
use App\Models\Curso;
use App\Models\InscripcionMateria;
use App\Models\Materia;
use App\Models\Matricula;
use App\Models\MiembroEquipo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Flujo completo de alta en materias, de punta a punta:
 *
 *   inscripción/asignación de materia → asignación de curso (Canvas/Teams
 *   ya creados) → matriculación del alumno en ese curso → alta automática
 *   en Canvas y en Teams.
 *
 * Reglas de negocio:
 *  - Un alumno nuevo (matrícula originada en una postulación admitida) recibe
 *    los cursos del primer semestre de su plan de estudio ya predefinidos
 *    por el Departamento Académico — no los elige.
 *  - Un alumno que continúa (matrícula sin postulación de origen) da de alta
 *    sus cursos manualmente, sujeto a prerrequisitos y cupo.
 *  - No se puede matricular a un alumno en un Curso que todavía no fue
 *    creado en Canvas y en Teams: la asignación de curso es un paso previo
 *    y obligatorio a la matriculación.
 *  - Dar de baja o cambiar de materia también da de baja al alumno del
 *    curso/equipo correspondiente en Canvas/Teams.
 */
class InscripcionMateriaService
{
    public function __construct(
        protected CanvasClient $canvas,
        protected TeamsClient $teams,
        protected SincronizacionService $sincronizacion,
    ) {}

    /**
     * @throws InscripcionMateriaException
     */
    public function inscribirManual(Matricula $matricula, Curso $curso): InscripcionMateria
    {
        $this->assertCursoListoParaMatricular($curso);
        $this->assertPersonaConCuentas($matricula);

        // Reintento: una inscripción previa quedó sin alta completa en
        // Canvas/Teams (falla de la plataforma). Ya ocupa su cupo y ya pasó
        // las validaciones, así que solo se completa el alta pendiente.
        $pendiente = $this->inscripcionPendienteDeAlta($matricula, $curso);

        if ($pendiente) {
            $this->altaEnPlataformas($matricula, $curso, $pendiente);

            return $pendiente->refresh();
        }

        $this->assertPrerequisitosCumplidos($matricula, $curso);
        $this->assertCupoDisponible($curso);
        $this->assertNoInscritoAun($matricula, $curso);

        $inscripcion = DB::transaction(fn () => InscripcionMateria::create([
            'matricula_id' => $matricula->id,
            'curso_id' => $curso->id,
            'origen' => 'manual',
            'estado' => 'inscrita',
            'fecha_inscripcion' => now(),
        ]));

        $this->altaEnPlataformas($matricula, $curso, $inscripcion);

        return $inscripcion->refresh();
    }

    /**
     * Da de alta al Departamento Académico los cursos del primer semestre
     * del plan de estudio para un alumno nuevo. No valida prerrequisitos
     * (un alumno nuevo no puede tenerlos aprobados) ni cupo — el Departamento
     * es responsable de que la oferta alcance para los alumnos admitidos.
     * Sí exige que cada curso ya esté creado en Canvas/Teams.
     *
     * @return Collection<int, InscripcionMateria>
     *
     * @throws InscripcionMateriaException
     */
    public function inscribirPredefinidasAlumnoNuevo(Matricula $matricula): Collection
    {
        if (! $matricula->esDeAlumnoNuevo()) {
            throw new InscripcionMateriaException(
                'Esta matrícula no corresponde a un alumno nuevo: los cursos deben darse de alta manualmente.'
            );
        }

        $cursosPrimerSemestre = Curso::query()
            ->where('periodo_academico_id', $matricula->periodo_academico_id)
            ->whereHas('materia', fn ($q) => $q
                ->where('plan_estudio_id', $matricula->plan_estudio_id)
                ->where('semestre_sugerido', 1)
                ->where('activa', true))
            ->get();

        return $cursosPrimerSemestre->map(function (Curso $curso) use ($matricula) {
            $this->assertCursoListoParaMatricular($curso);

            $inscripcion = InscripcionMateria::firstOrCreate(
                ['matricula_id' => $matricula->id, 'curso_id' => $curso->id],
                ['origen' => 'predefinida', 'estado' => 'inscrita', 'fecha_inscripcion' => now()],
            );

            $this->altaEnPlataformas($matricula, $curso, $inscripcion);

            return $inscripcion->refresh();
        });
    }

    /**
     * Da de baja al alumno de un curso, tanto académicamente como en
     * Canvas/Teams.
     */
    public function darDeBaja(InscripcionMateria $inscripcion): InscripcionMateria
    {
        $this->bajaEnPlataformas($inscripcion);

        $inscripcion->update(['estado' => 'retirada']);

        return $inscripcion->refresh();
    }

    /**
     * Cambia a un alumno de un curso a otro. La validación del curso nuevo
     * ocurre antes de retirar el curso actual para evitar que una decisión
     * inválida descuadre al alumno o desaparezca de su inscripción vigente.
     *
     * @throws InscripcionMateriaException
     */
    public function cambiarMateria(InscripcionMateria $inscripcionActual, Curso $cursoNuevo): InscripcionMateria
    {
        $matricula = $inscripcionActual->matricula;

        $this->assertCursoListoParaMatricular($cursoNuevo);
        $this->assertPersonaConCuentas($matricula);
        $this->assertPrerequisitosCumplidos($matricula, $cursoNuevo);
        $this->assertCupoDisponible($cursoNuevo);
        $this->assertNoInscritoAun($matricula, $cursoNuevo);

        $this->darDeBaja($inscripcionActual);

        return $this->inscribirManual($matricula, $cursoNuevo);
    }

    protected function inscripcionPendienteDeAlta(Matricula $matricula, Curso $curso): ?InscripcionMateria
    {
        return InscripcionMateria::query()
            ->where('matricula_id', $matricula->id)
            ->where('curso_id', $curso->id)
            ->where('estado', 'inscrita')
            ->where(fn ($q) => $q->whereNull('canvas_enrollment_at')->orWhereNull('teams_enrollment_at'))
            ->first();
    }

    /**
     * Se valida antes de crear la inscripción para no dejar un registro
     * "inscrita" huérfano si la persona no tiene cuentas.
     *
     * @throws InscripcionMateriaException
     */
    protected function assertPersonaConCuentas(Matricula $matricula): void
    {
        $persona = $matricula->persona;

        if (! $persona->canvas_user_id || ! $persona->azure_user_id) {
            throw new InscripcionMateriaException(
                "{$persona->nombre_completo} todavía no tiene cuenta institucional (Canvas/Teams) creada."
            );
        }
    }

    /**
     * @throws InscripcionMateriaException
     */
    protected function assertCursoListoParaMatricular(Curso $curso): void
    {
        if (! $curso->estaCreadoEnCanvas() || ! $curso->estaCreadoEnTeams()) {
            throw new InscripcionMateriaException(
                "El curso \"{$curso->materia->nombre}\" todavía no fue creado en Canvas y Teams — el Departamento Académico debe asignarlo antes de matricular alumnos."
            );
        }
    }

    /**
     * @throws InscripcionMateriaException
     */
    protected function assertPrerequisitosCumplidos(Matricula $matricula, Curso $curso): void
    {
        $prerequisitoIds = $curso->materia->prerequisitos()->pluck('materias.id');

        if ($prerequisitoIds->isEmpty()) {
            return;
        }

        $aprobadasIds = InscripcionMateria::query()
            ->whereHas('matricula', fn ($q) => $q->where('persona_id', $matricula->persona_id))
            ->where('estado', 'aprobada')
            ->whereHas('curso', fn ($q) => $q->whereIn('materia_id', $prerequisitoIds))
            ->with('curso')
            ->get()
            ->pluck('curso.materia_id');

        $faltantes = $prerequisitoIds->diff($aprobadasIds);

        if ($faltantes->isNotEmpty()) {
            $nombres = Materia::whereIn('id', $faltantes)->pluck('nombre')->implode(', ');

            throw new InscripcionMateriaException(
                "No se puede inscribir en \"{$curso->materia->nombre}\": falta aprobar el/los prerrequisito(s) {$nombres}."
            );
        }
    }

    /**
     * @throws InscripcionMateriaException
     */
    protected function assertCupoDisponible(Curso $curso): void
    {
        $disponible = $curso->cupoDisponible();

        if ($disponible !== null && $disponible <= 0) {
            throw new InscripcionMateriaException(
                "No hay cupo disponible en \"{$curso->materia->nombre}\"."
            );
        }
    }

    /**
     * @throws InscripcionMateriaException
     */
    protected function assertNoInscritoAun(Matricula $matricula, Curso $curso): void
    {
        $yaInscrito = InscripcionMateria::query()
            ->where('matricula_id', $matricula->id)
            ->where('curso_id', $curso->id)
            ->where('estado', '!=', 'retirada')
            ->exists();

        if ($yaInscrito) {
            throw new InscripcionMateriaException(
                "El alumno ya está inscrito en \"{$curso->materia->nombre}\" en este período."
            );
        }
    }

    /**
     * @throws InscripcionMateriaException
     */
    protected function altaEnPlataformas(Matricula $matricula, Curso $curso, InscripcionMateria $inscripcion): void
    {
        $persona = $matricula->persona;

        if (! $persona->canvas_user_id || ! $persona->azure_user_id) {
            throw new InscripcionMateriaException(
                "{$persona->nombre_completo} todavía no tiene cuenta institucional (Canvas/Teams) creada."
            );
        }

        $resultadosPorPlataforma = [];

        $this->sincronizacion->ejecutar(
            'matricula', 'inscripcion_alta_plataformas', "InscripcionMateria#{$inscripcion->id}",
            function () use ($curso, $persona, $inscripcion, &$resultadosPorPlataforma) {
                try {
                    if (! $inscripcion->estaAlDiaEnCanvas()) {
                        $this->canvas->enrollUser($curso->canvas_course_id, $persona->canvas_user_id);
                        $inscripcion->update(['canvas_enrollment_at' => now()]);
                        $resultadosPorPlataforma['canvas'] = ['estado' => 'completado', 'resultado' => 'alta_ok'];
                    }

                    if (! $inscripcion->estaAlDiaEnTeams()) {
                        $this->teams->addMember($curso->teams_group_id, $persona->azure_user_id);
                        $inscripcion->update(['teams_enrollment_at' => now()]);

                        if ($curso->equipoTeams) {
                            MiembroEquipo::updateOrCreate(
                                ['equipo_id' => $curso->equipoTeams->id, 'persona_id' => $persona->id],
                                ['rol_teams' => 'Member', 'fecha_alta' => now()],
                            );
                        }

                        $resultadosPorPlataforma['teams'] = ['estado' => 'completado', 'resultado' => 'alta_ok'];
                    }
                } catch (\Throwable $e) {
                    $plataforma = isset($resultadosPorPlataforma['canvas']) ? 'teams' : 'canvas';
                    $resultadosPorPlataforma[$plataforma] = ['estado' => 'error', 'error' => $e->getMessage()];

                    throw $e;
                } finally {
                    $this->sincronizacion->registrarAuditoriaPorPlataforma(
                        'inscripcion_alta_plataformas',
                        "InscripcionMateria#{$inscripcion->id}",
                        $resultadosPorPlataforma,
                    );
                }
            },
        );
    }

    protected function bajaEnPlataformas(InscripcionMateria $inscripcion): void
    {
        $curso = $inscripcion->curso;
        $persona = $inscripcion->matricula->persona;

        $resultadosPorPlataforma = [];

        $this->sincronizacion->ejecutar(
            'baja', 'inscripcion_baja_plataformas', "InscripcionMateria#{$inscripcion->id}",
            function () use ($curso, $persona, $inscripcion, &$resultadosPorPlataforma) {
                try {
                    if ($inscripcion->estaAlDiaEnCanvas() && $persona->canvas_user_id) {
                        $this->canvas->unenrollUser($curso->canvas_course_id, $persona->canvas_user_id);
                        $inscripcion->update(['canvas_enrollment_at' => null]);
                        $resultadosPorPlataforma['canvas'] = ['estado' => 'completado', 'resultado' => 'baja_ok'];
                    }

                    if ($inscripcion->estaAlDiaEnTeams() && $persona->azure_user_id) {
                        $this->teams->removeMember($curso->teams_group_id, $persona->azure_user_id);

                        if ($curso->equipoTeams) {
                            MiembroEquipo::where('equipo_id', $curso->equipoTeams->id)
                                ->where('persona_id', $persona->id)
                                ->where('rol_teams', 'Member')
                                ->delete();
                        }

                        $inscripcion->update(['teams_enrollment_at' => null]);
                        $resultadosPorPlataforma['teams'] = ['estado' => 'completado', 'resultado' => 'baja_ok'];
                    }
                } catch (\Throwable $e) {
                    $plataforma = isset($resultadosPorPlataforma['canvas']) ? 'teams' : 'canvas';
                    $resultadosPorPlataforma[$plataforma] = ['estado' => 'error', 'error' => $e->getMessage()];

                    throw $e;
                } finally {
                    $this->sincronizacion->registrarAuditoriaPorPlataforma(
                        'inscripcion_baja_plataformas',
                        "InscripcionMateria#{$inscripcion->id}",
                        $resultadosPorPlataforma,
                    );
                }
            },
        );
    }
}
