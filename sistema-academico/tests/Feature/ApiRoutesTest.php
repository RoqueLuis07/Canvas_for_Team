<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\LogAuditoria;
use App\Models\Materia;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\PlanEstudio;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ApiRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdminUser(): User
    {
        $usuario = User::factory()->create();
        $rol = Rol::factory()->create(['nombre' => 'Admin TI']);

        Persona::factory()->create([
            'user_id' => $usuario->id,
            'rol_id' => $rol->id,
            'nombre_completo' => 'Admin TI',
            'tipo' => 'administrativo',
            'estado' => 'activo',
        ]);

        return $usuario;
    }

    public function test_api_health_endpoint_returns_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'connected');
    }

    public function test_api_health_responde_503_cuando_la_base_de_datos_no_responde(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->andThrow(new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('database', 'unavailable');

        $this->assertNotNull($response->json('timestamp'));
    }

    public function test_api_cursos_requiere_autenticacion_y_permisos(): void
    {
        $response = $this->getJson('/api/cursos');
        $response->assertUnauthorized();

        $usuarioAdmin = $this->makeAdminUser();
        $this->actingAs($usuarioAdmin);

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

    public function test_api_matriculas_incluye_inscripciones_para_admin_o_propietario(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

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
        $response->assertForbidden();

        $usuarioAdmin = $this->makeAdminUser();
        $this->actingAs($usuarioAdmin);
        $response = $this->getJson('/api/matriculas/'.$matricula->id);

        $response->assertOk();
        $this->assertSame($matricula->id, $response->json('id'));
        $this->assertSame('inscrita', $response->json('inscripciones.0.estado'));
    }

    public function test_api_auditoria_requiere_admin_ti(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $response = $this->getJson('/api/auditoria');
        $response->assertForbidden();

        $usuarioAdmin = $this->makeAdminUser();
        $this->actingAs($usuarioAdmin);
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
