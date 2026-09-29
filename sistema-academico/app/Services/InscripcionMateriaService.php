<?php

namespace App\Services;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\InscripcionMateriaException;
use App\Models\Curso;
use App\Models\InscripcionMateria;
use App\Models\Materia;
use App\Models\Matricula;
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
     * Cambia a un alumno de un curso a otro: da de baja la inscripción
     * actual (académica y en las plataformas) y lo inscribe manualmente en
     * el nuevo curso.
     *
     * @throws InscripcionMateriaException
     */
    public function cambiarMateria(InscripcionMateria $inscripcionActual, Curso $cursoNuevo): InscripcionMateria
    {
        $matricula = $inscripcionActual->matricula;

        $this->darDeBaja($inscripcionActual);

        return $this->inscribirManual($matricula, $cursoNuevo);
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

        $this->sincronizacion->ejecutar(
            'matricula', 'inscripcion_alta_plataformas', "InscripcionMateria#{$inscripcion->id}",
            function () use ($curso, $persona, $inscripcion) {
                if (! $inscripcion->estaAlDiaEnCanvas()) {
                    $this->canvas->enrollUser($curso->canvas_course_id, $persona->canvas_user_id);
                    $inscripcion->update(['canvas_enrollment_at' => now()]);
                }

                if (! $inscripcion->estaAlDiaEnTeams()) {
                    $this->teams->addMember($curso->teams_group_id, $persona->azure_user_id);
                    $inscripcion->update(['teams_enrollment_at' => now()]);
                }
            },
        );
    }

    protected function bajaEnPlataformas(InscripcionMateria $inscripcion): void
    {
        $curso = $inscripcion->curso;
        $persona = $inscripcion->matricula->persona;

        $this->sincronizacion->ejecutar(
            'baja', 'inscripcion_baja_plataformas', "InscripcionMateria#{$inscripcion->id}",
            function () use ($curso, $persona, $inscripcion) {
                if ($inscripcion->estaAlDiaEnCanvas() && $persona->canvas_user_id) {
                    $this->canvas->unenrollUser($curso->canvas_course_id, $persona->canvas_user_id);
                }

                if ($inscripcion->estaAlDiaEnTeams() && $persona->azure_user_id) {
                    $this->teams->removeMember($curso->teams_group_id, $persona->azure_user_id);
                }

                $inscripcion->update(['canvas_enrollment_at' => null, 'teams_enrollment_at' => null]);
            },
        );
    }
}
