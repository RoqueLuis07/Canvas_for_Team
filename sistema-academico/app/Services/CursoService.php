<?php

namespace App\Services;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\CursoException;
use App\Models\Curso;
use App\Models\Persona;

/**
 * "Asignación de curso": convierte la oferta de una Materia en un Período
 * (un Curso, todavía solo un registro académico) en un curso real de Canvas
 * y un equipo real de Teams. Es el paso que el Departamento Académico
 * dispara antes de poder matricular alumnos en él.
 */
class CursoService
{
    public function __construct(
        protected CanvasClient $canvas,
        protected TeamsClient $teams,
    ) {}

    /**
     * @throws CursoException
     */
    public function crearEnCanvas(Curso $curso): Curso
    {
        if ($curso->estaCreadoEnCanvas()) {
            return $curso;
        }

        $materia = $curso->materia;
        $sisCourseId = "{$materia->codigo}-{$curso->periodo_academico_id}";

        try {
            $canvasCourseId = $this->canvas->createCourse($materia->nombre, $sisCourseId);
        } catch (\Throwable $e) {
            throw new CursoException("No se pudo crear el curso en Canvas: {$e->getMessage()}", previous: $e);
        }

        $curso->update(['canvas_course_id' => $canvasCourseId]);

        return $curso->refresh();
    }

    /**
     * @throws CursoException
     */
    public function crearEnTeams(Curso $curso): Curso
    {
        if ($curso->estaCreadoEnTeams()) {
            return $curso;
        }

        try {
            $teamsGroupId = $this->teams->createTeam($curso->materia->nombre);
        } catch (\Throwable $e) {
            throw new CursoException("No se pudo crear el equipo en Teams: {$e->getMessage()}", previous: $e);
        }

        $curso->update(['teams_group_id' => $teamsGroupId]);

        return $curso->refresh();
    }

    /**
     * Asigna a un docente como titular del Curso: lo matricula como
     * profesor en Canvas y como propietario del equipo en Teams. El Curso
     * debe estar ya creado en ambas plataformas.
     *
     * @throws CursoException
     */
    public function asignarDocente(Curso $curso, Persona $docente): Curso
    {
        if ($docente->tipo !== 'docente') {
            throw new CursoException("{$docente->nombre_completo} no está registrado como docente.");
        }

        if (! $curso->estaCreadoEnCanvas() || ! $curso->estaCreadoEnTeams()) {
            throw new CursoException(
                "El curso \"{$curso->materia->nombre}\" todavía no fue creado en Canvas y Teams — asignalo antes de designar al docente."
            );
        }

        if (! $docente->canvas_user_id || ! $docente->azure_user_id) {
            throw new CursoException("{$docente->nombre_completo} todavía no tiene cuenta institucional (Canvas/Teams) creada.");
        }

        try {
            $this->canvas->enrollUser($curso->canvas_course_id, $docente->canvas_user_id, 'TeacherEnrollment');
            $this->teams->addMember($curso->teams_group_id, $docente->azure_user_id, 'Owner');
        } catch (\Throwable $e) {
            throw new CursoException("No se pudo asignar al docente en Canvas/Teams: {$e->getMessage()}", previous: $e);
        }

        $curso->update([
            'docente_persona_id' => $docente->id,
            'docente_canvas_at' => now(),
            'docente_teams_at' => now(),
        ]);

        return $curso->refresh();
    }
}
