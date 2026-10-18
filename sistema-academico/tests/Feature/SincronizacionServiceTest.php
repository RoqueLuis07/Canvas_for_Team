<?php

namespace Tests\Feature;

use App\Models\JobSincronizacion;
use App\Models\LogAuditoria;
use App\Models\User;
use App\Services\SincronizacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SincronizacionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SincronizacionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SincronizacionService::class);
    }

    public function test_registra_job_y_log_al_completar_con_exito(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $resultado = $this->service->ejecutar('crear_curso', 'curso_creado_canvas', 'Curso#1', fn () => 'ok');

        $this->assertSame('ok', $resultado);

        $job = JobSincronizacion::first();
        $this->assertSame('crear_curso', $job->tipo);
        $this->assertSame('completado', $job->estado);
        $this->assertSame($usuario->id, $job->usuario_solicitante_id);
        $this->assertNotNull($job->fecha_fin);

        $log = LogAuditoria::first();
        $this->assertSame('curso_creado_canvas', $log->accion);
        $this->assertSame('Curso#1', $log->entidad_afectada);
        $this->assertSame($usuario->id, $log->usuario_id);
    }

    public function test_registra_job_y_log_con_error_y_relanza_la_excepcion(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('falló la sincronización');

        try {
            $this->service->ejecutar('crear_team', 'team_creado_teams', 'Curso#2', function () {
                throw new RuntimeException('falló la sincronización');
            });
        } finally {
            $job = JobSincronizacion::first();
            $this->assertSame('error', $job->estado);
            $this->assertSame('falló la sincronización', $job->resultado);

            $log = LogAuditoria::first();
            $this->assertStringContainsString('falló la sincronización', $log->detalle);
        }
    }

    public function test_registra_logs_individuales_por_plataforma(): void
    {
        $usuario = User::factory()->create();
        $this->actingAs($usuario);

        $this->service->registrarAuditoriaPorPlataforma('inscripcion_alta_plataformas', 'InscripcionMateria#10', [
            'canvas' => ['estado' => 'completado', 'resultado' => 'alta_ok'],
            'teams' => ['estado' => 'error', 'error' => 'Graph caído'],
        ]);

        $logs = LogAuditoria::orderBy('id')->get();

        $this->assertCount(2, $logs);
        $this->assertSame('InscripcionMateria#10:canvas', $logs[0]->entidad_afectada);
        $this->assertStringContainsString('"plataforma":"canvas"', $logs[0]->detalle);
        $this->assertStringContainsString('"plataforma":"teams"', $logs[1]->detalle);
        $this->assertStringContainsString('Graph caído', $logs[1]->detalle);
    }
}
