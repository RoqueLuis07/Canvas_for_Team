<?php

use App\Models\Curso;
use App\Models\LogAuditoria;
use App\Models\Matricula;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $dbOk = false;

    try {
        DB::select('SELECT 1');
        $dbOk = true;
    } catch (\Throwable) {
        $dbOk = false;
    }

    return response()->json([
        'status' => 'ok',
        'database' => $dbOk ? 'connected' : 'unavailable',
        'timestamp' => now()->toISOString(),
    ]);
});

Route::middleware('auth')->group(function () {
    Route::get('/cursos', function () {
        $usuario = auth()->user();

        abort_unless($usuario && ($usuario->isAdminTI() || $usuario->isDocente() || $usuario->isEstudiante()), 403, 'No autorizado para consultar cursos.');

        $query = Curso::query()->with(['materia', 'periodoAcademico']);

        if ($usuario->isDocente()) {
            $query->where('docente_persona_id', $usuario->activePersonaIdForRole('Docente'));
        }

        if ($usuario->isEstudiante()) {
            $query->whereHas('inscripciones.matricula', fn ($q) => $q->where('persona_id', $usuario->activePersonaIdForRole('Estudiante')));
        }

        return response()->json(
            $query->get()->map(fn (Curso $curso) => [
                'id' => $curso->id,
                'nombre' => $curso->materia?->nombre,
                'codigo' => $curso->materia?->codigo,
                'periodo' => $curso->periodoAcademico?->nombre,
                'cupo_maximo' => $curso->cupo_maximo,
                'cupo_disponible' => $curso->cupoDisponible(),
                'canvas_creado' => $curso->estaCreadoEnCanvas(),
                'teams_creado' => $curso->estaCreadoEnTeams(),
            ])->values()
        );
    });

    Route::get('/matriculas/{matricula}', function (Matricula $matricula) {
        $usuario = auth()->user();

        abort_unless($usuario, 403, 'Debe iniciar sesión para consultar matrículas.');

        $esAdmin = $usuario->isAdminTI();
        $esEstudiantePropio = $usuario->isEstudiante() && $matricula->persona_id === $usuario->activePersonaIdForRole('Estudiante');

        abort_unless($esAdmin || $esEstudiantePropio, 403, 'No autorizado para consultar esta matrícula.');

        return response()->json([
            'id' => $matricula->id,
            'estado' => $matricula->estado,
            'persona' => $matricula->persona?->nombre_completo,
            'plan_estudio_id' => $matricula->plan_estudio_id,
            'periodo_academico_id' => $matricula->periodo_academico_id,
            'inscripciones' => $matricula->inscripciones()->with('curso.materia')->get()->map(fn ($inscripcion) => [
                'id' => $inscripcion->id,
                'curso_id' => $inscripcion->curso_id,
                'curso' => $inscripcion->curso?->materia?->nombre,
                'estado' => $inscripcion->estado,
                'canvas_al_dia' => $inscripcion->estaAlDiaEnCanvas(),
                'teams_al_dia' => $inscripcion->estaAlDiaEnTeams(),
            ])->values(),
        ]);
    });

    Route::get('/auditoria', function () {
        abort_unless(auth()->user()?->isAdminTI(), 403, 'La auditoría solo está disponible para Admin TI.');

        return response()->json(
            LogAuditoria::query()
                ->with('usuario')
                ->orderByDesc('fecha')
                ->limit(20)
                ->get()
                ->map(fn (LogAuditoria $log) => [
                    'id' => $log->id,
                    'accion' => $log->accion,
                    'entidad_afectada' => $log->entidad_afectada,
                    'detalle' => $log->detalle,
                    'fecha' => $log->fecha?->toISOString(),
                    'usuario' => $log->usuario?->email,
                ])
                ->values()
        );
    });
});
