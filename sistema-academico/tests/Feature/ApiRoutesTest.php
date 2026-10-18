<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\LogAuditoria;
use App\Models\Materia;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\PlanEstudio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_health_endpoint_returns_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'connected');
    }

    public function test_api_cursos_returns_estructura_json(): void
    {
        $plan = PlanEstudio::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        Curso::factory()->creadoEnCanvasYTeams()->create([
            'materia_id' => $materia->id,
            'cupo_maximo' => 5,
        ]);

        $response = $this->getJson('/api/cursos');

        $response->assertOk();
        $this->assertSame(1, $response->json('0.id'));
        $this->assertSame($materia->nombre, $response->json('0.nombre'));
        $this->assertSame(5, $response->json('0.cupo_maximo'));
    }

    public function test_api_matriculas_incluye_inscripciones(): void
    {
        $plan = PlanEstudio::factory()->create();
        $persona = Persona::factory()->create();
        $materia = Materia::factory()->create(['plan_estudio_id' => $plan->id]);
        $curso = Curso::factory()->creadoEnCanvasYTeams()->create(['materia_id' => $materia->id, 'cupo_maximo' => null]);
        $matricula = Matricula::factory()->create([
            'persona_id' => $persona->id,
            'plan_estudio_id' => $plan->id,
            'postulacion_id' => null,
        ]);
        $matricula->inscripciones()->create([
            'curso_id' => $curso->id,
            'origen' => 'manual',
            'estado' => 'inscrita',
            'fecha_inscripcion' => now(),
        ]);

        $response = $this->getJson('/api/matriculas/'.$matricula->id);

        $response->assertOk();
        $this->assertSame($matricula->id, $response->json('id'));
        $this->assertSame('inscrita', $response->json('inscripciones.0.estado'));
    }

    public function test_api_auditoria_returns_last_entries(): void
    {
        $usuario = User::factory()->create();
        LogAuditoria::create([
            'usuario_id' => $usuario->id,
            'accion' => 'curso_creado_canvas',
            'entidad_afectada' => 'Curso#1',
            'detalle' => json_encode(['ok' => true]),
            'fecha' => now(),
        ]);

        $response = $this->getJson('/api/auditoria');

        $response->assertOk();
        $this->assertSame('curso_creado_canvas', $response->json('0.accion'));
    }
}
