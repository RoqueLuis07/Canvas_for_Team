<?php

namespace Tests\Feature;

use App\Contracts\CanvasClient;
use App\Contracts\TeamsClient;
use App\Exceptions\CursoException;
use App\Models\Curso;
use App\Models\JobSincronizacion;
use App\Models\LogAuditoria;
use App\Models\Persona;
use App\Services\CursoService;
use App\Services\SincronizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CursoServiceTest extends TestCase
{
    use RefreshDatabase;

    private CursoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CursoService::class);
    }

    public function test_no_deja_asignar_docente_a_un_curso_no_creado_en_canvas_y_teams(): void
    {
        $curso = Curso::factory()->create(); // sin canvas_course_id/teams_group_id
        $docente = Persona::factory()->docente()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);

        $this->expectException(CursoException::class);
        $this->expectExceptionMessage('todavía no fue creado en Canvas y Teams');

        $this->service->asignarDocente($curso, $docente);
    }

    public function test_no_deja_asignar_a_una_persona_que_no_es_docente(): void
    {
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create();
        $alumno = Persona::factory()->alumno()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);

        $this->expectException(CursoException::class);
        $this->expectExceptionMessage('no está registrado como docente');

        $this->service->asignarDocente($curso, $alumno);
    }

    public function test_no_deja_asignar_docente_sin_cuenta_institucional(): void
    {
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create();
        $docente = Persona::factory()->docente()->create([
            'canvas_user_id' => null,
            'azure_user_id' => null,
        ]);

        $this->expectException(CursoException::class);
        $this->expectExceptionMessage('todavía no tiene cuenta institucional');

        $this->service->asignarDocente($curso, $docente);
    }

    public function test_asigna_docente_y_lo_da_de_alta_en_canvas_y_teams(): void
    {
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create();
        $docente = Persona::factory()->docente()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);

        $curso = $this->service->asignarDocente($curso, $docente);

        $this->assertSame($docente->id, $curso->docente_persona_id);
        $this->assertTrue($curso->tieneDocenteAsignado());
        $this->assertTrue($curso->docenteEstaAlDiaEnCanvas());
        $this->assertTrue($curso->docenteEstaAlDiaEnTeams());
    }

    public function test_reintenta_solo_teams_si_falla_la_asignacion_parcial_del_docente(): void
    {
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create();
        $docente = Persona::factory()->docente()->create([
            'canvas_user_id' => 'canvas-1',
            'azure_user_id' => 'azure-1',
        ]);

        $canvas = new class implements CanvasClient
        {
            public int $asignaciones = 0;

            public function createCourse(string $name, string $sisCourseId): string
            {
                return 'course-1';
            }

            public function enrollUser(string $canvasCourseId, string $canvasUserId, string $role = 'StudentEnrollment'): void
            {
                $this->asignaciones++;
            }

            public function unenrollUser(string $canvasCourseId, string $canvasUserId): void {}

            public function createPage(string $canvasCourseId, string $title, string $body): string
            {
                return 'page-1';
            }
        };
        $teams = new class implements TeamsClient
        {
            public int $asignaciones = 0;

            public bool $fallar = true;

            public function createTeam(string $name): string
            {
                return 'team-1';
            }

            public function addMember(string $teamsGroupId, string $azureUserId, string $role = 'Member'): void
            {
                $this->asignaciones++;

                if ($this->fallar) {
                    throw new \RuntimeException('Graph caído al asignar Owner');
                }
            }

            public function removeMember(string $teamsGroupId, string $azureUserId): void {}
        };

        $service = new CursoService($canvas, $teams, app(SincronizacionService::class));

        try {
            $service->asignarDocente($curso, $docente);
            $this->fail('Debió fallar por Teams.');
        } catch (CursoException $e) {
            $this->assertStringContainsString('Graph caído al asignar Owner', $e->getMessage());
        }

        $curso->refresh();
        $this->assertSame($docente->id, $curso->docente_persona_id);
        $this->assertTrue($curso->docenteEstaAlDiaEnCanvas());
        $this->assertFalse($curso->docenteEstaAlDiaEnTeams());

        $logCanvas = LogAuditoria::where('entidad_afectada', "Curso#{$curso->id}:canvas")->first();
        $detalleCanvas = json_decode($logCanvas->detalle, true);
        $this->assertSame('completado', $detalleCanvas['estado']);
        $this->assertSame($docente->id, $detalleCanvas['registro']['docente_persona_id']);
        $this->assertTrue($detalleCanvas['registro']['canvas_al_dia']);
        $this->assertFalse($detalleCanvas['registro']['teams_al_dia']);

        $logTeams = LogAuditoria::where('entidad_afectada', "Curso#{$curso->id}:teams")->first();
        $detalleTeams = json_decode($logTeams->detalle, true);
        $this->assertSame('error', $detalleTeams['estado']);
        $this->assertSame('Graph caído al asignar Owner', $detalleTeams['error']);

        $teams->fallar = false;
        $curso = $service->asignarDocente($curso, $docente);

        $this->assertSame(1, $canvas->asignaciones);
        $this->assertSame(2, $teams->asignaciones);
        $this->assertTrue($curso->docenteEstaAlDiaEnCanvas());
        $this->assertTrue($curso->docenteEstaAlDiaEnTeams());
        $this->assertSame('error', JobSincronizacion::orderBy('id')->first()->estado);
        $this->assertSame('completado', JobSincronizacion::orderByDesc('id')->first()->estado);
    }
}
