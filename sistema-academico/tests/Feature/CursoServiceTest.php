<?php

namespace Tests\Feature;

use App\Exceptions\CursoException;
use App\Models\Curso;
use App\Models\Persona;
use App\Services\CursoService;
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
}
