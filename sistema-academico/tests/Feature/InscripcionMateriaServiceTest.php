<?php

namespace Tests\Feature;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\InscripcionMateriaException;
use App\Models\Curso;
use App\Models\InscripcionMateria;
use App\Models\JobSincronizacion;
use App\Models\LogAuditoria;
use App\Models\Materia;
use App\Models\Matricula;
use App\Models\PeriodoAcademico;
use App\Models\Persona;
use App\Models\PlanEstudio;
use App\Models\Postulacion;
use App\Services\InscripcionMateriaService;
use App\Services\SincronizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InscripcionMateriaServiceTest extends TestCase
{
    use RefreshDatabase;

    private InscripcionMateriaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InscripcionMateriaService::class);
    }

    private function personaConCuentas(): Persona
    {
        return Persona::factory()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);
    }

    public function test_no_deja_matricular_en_un_curso_no_creado_en_canvas_y_teams(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->create(['materia_id' => $materia->id]); // sin canvas_course_id/teams_group_id

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('todavía no fue creado en Canvas y Teams');

        $this->service->inscribirManual($matricula, $curso);
    }

    public function test_no_deja_inscribir_sin_prerequisito_aprobado(): void
    {
        $plan = PlanEstudio::factory()->create();
        $prerequisito = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia->prerequisitos()->attach($prerequisito);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('falta aprobar');

        $this->service->inscribirManual($matricula, $curso);
    }

    public function test_deja_inscribir_con_prerequisito_aprobado_y_da_de_alta_en_canvas_y_teams(): void
    {
        $plan = PlanEstudio::factory()->create();
        $prerequisito = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materia->prerequisitos()->attach($prerequisito);
        $cursoPrerequisito = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $prerequisito->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);
        $matricula->inscripciones()->create([
            'curso_id' => $cursoPrerequisito->id,
            'origen' => 'manual',
            'estado' => 'aprobada',
            'fecha_inscripcion' => now(),
        ]);

        $inscripcion = $this->service->inscribirManual($matricula, $curso);

        $this->assertSame('inscrita', $inscripcion->estado);
        $this->assertSame('manual', $inscripcion->origen);
        $this->assertTrue($inscripcion->estaAlDiaEnCanvas());
        $this->assertTrue($inscripcion->estaAlDiaEnTeams());
    }

    public function test_no_deja_inscribir_sin_cupo_disponible(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => 1]);

        $primeraMatricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);
        $this->service->inscribirManual($primeraMatricula, $curso);

        $segundaMatricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('No hay cupo disponible');

        $this->service->inscribirManual($segundaMatricula, $curso);
    }

    public function test_no_deja_inscribir_dos_veces_en_el_mismo_curso(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $this->service->inscribirManual($matricula, $curso);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('ya está inscrito');

        $this->service->inscribirManual($matricula, $curso);
    }

    public function test_reintenta_el_alta_si_teams_fallo_en_la_primera_inscripcion(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => 1]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $teamsCaido = new class implements TeamsClient
        {
            public function createTeam(string $name): string
            {
                return 'x';
            }

            public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void
            {
                throw new \RuntimeException('Graph caído');
            }

            public function removeMember(string $teamsGroupId, string $azureUserId): void {}
        };

        $servicioConFalla = new InscripcionMateriaService(
            app(CanvasClient::class), $teamsCaido, app(SincronizacionService::class)
        );

        try {
            $servicioConFalla->inscribirManual($matricula, $curso);
            $this->fail('Debió fallar por Teams.');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(1, InscripcionMateria::count());
        $this->assertSame('error', JobSincronizacion::latest('id')->value('estado'));

        $logsPorPlataforma = LogAuditoria::query()
            ->where('entidad_afectada', 'like', 'InscripcionMateria#%:%')
            ->pluck('detalle', 'entidad_afectada');

        $this->assertStringContainsString('"estado":"completado"', $logsPorPlataforma->first(
            fn (string $detalle, string $entidad) => str_ends_with($entidad, ':canvas'),
        ));
        $this->assertStringContainsString('Graph caído', $logsPorPlataforma->first(
            fn (string $detalle, string $entidad) => str_ends_with($entidad, ':teams'),
        ));

        $inscripcion = $this->service->inscribirManual($matricula, $curso);

        $this->assertSame(1, InscripcionMateria::count());
        $this->assertTrue($inscripcion->estaAlDiaEnCanvas());
        $this->assertTrue($inscripcion->estaAlDiaEnTeams());
    }

    public function test_no_deja_inscripcion_huerfana_si_la_persona_no_tiene_cuentas(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => Persona::factory()->create(['canvas_user_id' => null, 'azure_user_id' => null])->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        try {
            $this->service->inscribirManual($matricula, $curso);
            $this->fail('Debió fallar por falta de cuentas.');
        } catch (InscripcionMateriaException) {
            // esperado
        }

        $this->assertSame(0, InscripcionMateria::count());
    }

    public function test_alumno_nuevo_recibe_cursos_de_primer_semestre_predefinidos(): void
    {
        $plan = PlanEstudio::factory()->create();
        $periodo = PeriodoAcademico::factory()->create();

        $materia1 = Materia::factory()->primerSemestre()->create(['plan_estudio_id' => $plan->id]);
        $materia2 = Materia::factory()->primerSemestre()->create(['plan_estudio_id' => $plan->id]);
        $materia3 = Materia::factory()->create(['plan_estudio_id' => $plan->id, 'semestre_sugerido' => 3]);

        Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia1->id, 'periodo_academico_id' => $periodo->id]);
        Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia2->id, 'periodo_academico_id' => $periodo->id]);
        Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia3->id, 'periodo_academico_id' => $periodo->id]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'periodo_academico_id' => $periodo->id,
            'postulacion_id' => Postulacion::factory()->admitido()->create()->id,
        ]);

        $inscripciones = $this->service->inscribirPredefinidasAlumnoNuevo($matricula);

        $this->assertCount(2, $inscripciones);
        $this->assertTrue($inscripciones->every(fn ($i) => $i->origen === 'predefinida'));
        $this->assertTrue($inscripciones->every(fn ($i) => $i->estaAlDiaEnCanvas() && $i->estaAlDiaEnTeams()));
    }

    public function test_predefinidas_reactiva_una_materia_retirada_del_alumno_nuevo(): void
    {
        $plan = PlanEstudio::factory()->create();
        $periodo = PeriodoAcademico::factory()->create();
        $materia = Materia::factory()->primerSemestre()->create(['plan_estudio_id' => $plan->id]);
        Curso::factory()->creadoEnCanvasYTeams()->create([
            'materia_id' => $materia->id,
            'periodo_academico_id' => $periodo->id,
        ]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'periodo_academico_id' => $periodo->id,
            'postulacion_id' => Postulacion::factory()->admitido()->create()->id,
        ]);

        $primera = $this->service->inscribirPredefinidasAlumnoNuevo($matricula)->sole();
        $this->service->darDeBaja($primera);

        $segunda = $this->service->inscribirPredefinidasAlumnoNuevo($matricula)->sole();

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame('inscrita', $segunda->estado);
        $this->assertTrue($segunda->estaAlDiaEnCanvas());
        $this->assertTrue($segunda->estaAlDiaEnTeams());
        $this->assertSame(1, InscripcionMateria::count());
    }

    public function test_predefinidas_no_pisa_una_inscripcion_ya_aprobada(): void
    {
        $plan = PlanEstudio::factory()->create();
        $periodo = PeriodoAcademico::factory()->create();
        $materia = Materia::factory()->primerSemestre()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create([
            'materia_id' => $materia->id,
            'periodo_academico_id' => $periodo->id,
        ]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'periodo_academico_id' => $periodo->id,
            'postulacion_id' => Postulacion::factory()->admitido()->create()->id,
        ]);
        $aprobada = $matricula->inscripciones()->create([
            'curso_id' => $curso->id,
            'origen' => 'predefinida',
            'estado' => 'aprobada',
            'fecha_inscripcion' => now()->subMonth(),
            'canvas_enrollment_at' => now()->subMonth(),
            'teams_enrollment_at' => now()->subMonth(),
        ]);

        $this->service->inscribirPredefinidasAlumnoNuevo($matricula);

        $aprobada->refresh();
        $this->assertSame('aprobada', $aprobada->estado);
        $this->assertTrue($aprobada->estaAlDiaEnCanvas());
        $this->assertTrue($aprobada->estaAlDiaEnTeams());
    }

    public function test_alumno_que_continua_no_puede_recibir_cursos_predefinidos(): void
    {
        $plan = PlanEstudio::factory()->create();
        $matricula = Matricula::factory()->create(['plan_estudio_id' => $plan->id, 'postulacion_id' => null]);

        $this->expectException(InscripcionMateriaException::class);

        $this->service->inscribirPredefinidasAlumnoNuevo($matricula);
    }

    public function test_dar_de_baja_retira_academicamente_y_de_canvas_teams(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $inscripcion = $this->service->inscribirManual($matricula, $curso);
        $inscripcion = $this->service->darDeBaja($inscripcion);

        $this->assertSame('retirada', $inscripcion->estado);
        $this->assertFalse($inscripcion->estaAlDiaEnCanvas());
        $this->assertFalse($inscripcion->estaAlDiaEnTeams());
    }

    public function test_reinscribir_tras_una_baja_reactiva_la_misma_fila_y_vuelve_a_dar_de_alta_en_plataformas(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => 1]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $inscripcion = $this->service->inscribirManual($matricula, $curso);
        $this->service->darDeBaja($inscripcion);

        // El cupo se libera al retirarse y la fila única se reactiva: no se
        // inserta una segunda inscripción para la misma materia.
        $reactivada = $this->service->inscribirManual($matricula, $curso);

        $this->assertSame($inscripcion->id, $reactivada->id);
        $this->assertSame('inscrita', $reactivada->estado);
        $this->assertSame('manual', $reactivada->origen);
        $this->assertTrue($reactivada->estaAlDiaEnCanvas());
        $this->assertTrue($reactivada->estaAlDiaEnTeams());
        $this->assertSame(1, InscripcionMateria::count());
    }

    public function test_reintenta_solo_la_baja_pendiente_y_audita_el_fallo_por_plataforma(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);
        $inscripcion = $this->service->inscribirManual($matricula, $curso);

        $canvas = new class implements CanvasClient
        {
            public int $bajas = 0;

            public function createCourse(string $name, string $sisCourseId): string
            {
                return 'x';
            }

            public function enrollUser(string $canvasCourseId, string $canvasUserId, string $role = 'StudentEnrollment'): void {}

            public function unenrollUser(string $canvasCourseId, string $canvasUserId): void
            {
                $this->bajas++;
            }

            public function createPage(string $canvasCourseId, string $title, string $body): string
            {
                return 'x';
            }
        };
        $teamsCaido = new class implements TeamsClient
        {
            public function createTeam(string $name): string
            {
                return 'x';
            }

            public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void {}

            public function removeMember(string $teamsGroupId, string $azureUserId): void
            {
                throw new \RuntimeException('Graph caído al dar de baja');
            }
        };
        $servicioConFalla = new InscripcionMateriaService($canvas, $teamsCaido, app(SincronizacionService::class));

        try {
            $servicioConFalla->darDeBaja($inscripcion);
            $this->fail('Debió fallar por Teams.');
        } catch (\RuntimeException) {
            // esperado
        }

        $inscripcion->refresh();
        $this->assertFalse($inscripcion->estaAlDiaEnCanvas());
        $this->assertTrue($inscripcion->estaAlDiaEnTeams());
        $this->assertSame(1, $canvas->bajas);
        $this->assertSame('error', JobSincronizacion::latest('id')->value('estado'));
        $this->assertStringContainsString(
            'Graph caído al dar de baja',
            LogAuditoria::where('entidad_afectada', "InscripcionMateria#{$inscripcion->id}:teams")
                ->latest('id')
                ->value('detalle'),
        );

        $inscripcion = $this->service->darDeBaja($inscripcion);

        $this->assertSame('retirada', $inscripcion->estado);
        $this->assertFalse($inscripcion->estaAlDiaEnCanvas());
        $this->assertFalse($inscripcion->estaAlDiaEnTeams());
        $this->assertSame(1, $canvas->bajas);
    }

    public function test_cambiar_materia_da_de_baja_la_actual_e_inscribe_en_la_nueva(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materiaActual = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materiaNueva = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $cursoActual = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materiaActual->id, 'cupo_maximo' => null]);
        $cursoNuevo = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materiaNueva->id, 'cupo_maximo' => null]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $inscripcionActual = $this->service->inscribirManual($matricula, $cursoActual);
        $inscripcionNueva = $this->service->cambiarMateria($inscripcionActual, $cursoNuevo);

        $this->assertSame('retirada', $inscripcionActual->refresh()->estado);
        $this->assertSame('inscrita', $inscripcionNueva->estado);
        $this->assertSame($cursoNuevo->id, $inscripcionNueva->curso_id);
        $this->assertTrue($inscripcionNueva->estaAlDiaEnCanvas());
        $this->assertTrue($inscripcionNueva->estaAlDiaEnTeams());
    }

    public function test_cambiar_materia_no_retiro_la_inscripcion_actual_si_el_curso_nuevo_no_es_valido(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materiaActual = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $materiaNueva = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $cursoActual = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materiaActual->id, 'cupo_maximo' => null]);
        $cursoNuevoInvalido = Curso::factory()->create(['materia_id' => $materiaNueva->id]);

        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);

        $inscripcionActual = $this->service->inscribirManual($matricula, $cursoActual);

        $this->expectException(InscripcionMateriaException::class);
        $this->expectExceptionMessage('todavía no fue creado en Canvas y Teams');

        $this->service->cambiarMateria($inscripcionActual, $cursoNuevoInvalido);

        $this->assertSame('inscrita', $inscripcionActual->refresh()->estado);
        $this->assertSame($cursoActual->id, $inscripcionActual->curso_id);
    }

    public function test_auditoria_atribuye_el_error_a_teams_cuando_canvas_ya_estaba_al_dia(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $this->personaConCuentas()->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);
        $inscripcion = $matricula->inscripciones()->create([
            'curso_id' => $curso->id,
            'origen' => 'manual',
            'estado' => 'inscrita',
            'fecha_inscripcion' => now(),
            'canvas_enrollment_at' => now(),
        ]);

        $teamsCaido = new class implements TeamsClient
        {
            public function createTeam(string $name): string
            {
                return 'x';
            }

            public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void
            {
                throw new \RuntimeException('Graph sigue caído');
            }

            public function removeMember(string $teamsGroupId, string $azureUserId): void {}
        };
        $servicio = new InscripcionMateriaService(app(CanvasClient::class), $teamsCaido, app(SincronizacionService::class));

        try {
            $servicio->inscribirManual($matricula, $curso);
            $this->fail('Debió fallar por Teams.');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(0, LogAuditoria::where('entidad_afectada', "InscripcionMateria#{$inscripcion->id}:canvas")->count());

        $detalleTeams = json_decode(
            LogAuditoria::where('entidad_afectada', "InscripcionMateria#{$inscripcion->id}:teams")->value('detalle'),
            true,
        );

        $this->assertSame('error', $detalleTeams['estado']);
        $this->assertSame('Graph sigue caído', $detalleTeams['error']);
        $this->assertSame('inscrita', $detalleTeams['registro']['estado']);
        $this->assertTrue($detalleTeams['registro']['canvas_al_dia']);
        $this->assertFalse($detalleTeams['registro']['teams_al_dia']);
    }
}
