<?php

namespace Tests\Feature;

use App\Models\JobSincronizacion;
use App\Models\LogAuditoria;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\User;
use App\Services\HistorialService;
use App\Services\LLM\NullLLMClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class HistorialServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        NullLLMClient::reset();
    }

    protected function tearDown(): void
    {
        NullLLMClient::reset();
        parent::tearDown();
    }

    public function test_solicitar_persiste_solo_contexto_allow_list_y_correlaciona_auditoria(): void
    {
        $mensajePrivado = '401 Unauthorized alumno.real@example.com CI 123456789 Bearer abcdefghijklmnopqrstuvwxyz';
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => $mensajePrivado]);
        $usuario = $this->adminUser();

        $diagnostico = app(HistorialService::class)->solicitar($job, $usuario);

        $this->assertSame('pendiente', $diagnostico->estado);
        $this->assertSame(['categoria' => 'autenticacion', 'codigo_http' => 401], $diagnostico->contexto_sanitizado);
        $this->assertSame($job->id, $diagnostico->jobSincronizacion->id);
        $this->assertSame($usuario->id, $diagnostico->solicitante->id);
        $this->assertStringNotContainsString('alumno.real@example.com', $diagnostico->prompt_sanitizado);
        $this->assertStringNotContainsString('123456789', $diagnostico->prompt_sanitizado);
        $this->assertStringNotContainsString('abcdefghijklmnopqrst', $diagnostico->prompt_sanitizado);

        $log = LogAuditoria::query()->firstOrFail();
        $this->assertSame($diagnostico->id, $log->diagnostico_ia_id);
        $this->assertSame($diagnostico->id, $log->diagnosticoIA->id);
        $this->assertStringNotContainsString('alumno.real@example.com', $log->detalle);
    }

    public function test_procesar_no_envia_datos_originales_y_no_modifica_el_job(): void
    {
        $mensajePrivado = '429 Too Many Requests para alumno.real@example.com phone 595981123456 CI 123456789';
        $job = JobSincronizacion::factory()->create([
            'estado' => 'error',
            'resultado' => $mensajePrivado,
        ]);
        $service = app(HistorialService::class);

        $diagnostico = $service->solicitar($job, $this->adminUser());
        $resultado = $service->procesar($diagnostico);

        $promptEnviado = json_encode(NullLLMClient::calls(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('alumno.real@example.com', $promptEnviado);
        $this->assertStringNotContainsString('595981123456', $promptEnviado);
        $this->assertStringNotContainsString('123456789', $promptEnviado);
        $this->assertStringNotContainsString($mensajePrivado, $promptEnviado);
        $this->assertSame('completado', $resultado->estado);
        $this->assertSame('limite_solicitudes', $resultado->contexto_sanitizado['categoria']);
        $this->assertSame('error', $job->fresh()->estado);
        $this->assertSame($mensajePrivado, $job->fresh()->resultado);
        $this->assertGreaterThanOrEqual(2, $resultado->logsAuditoria()->count());
    }

    public function test_rechaza_jobs_no_fallidos_sin_crear_registros(): void
    {
        $job = JobSincronizacion::factory()->create(['estado' => 'completado']);

        try {
            app(HistorialService::class)->solicitar($job, $this->adminUser());
            $this->fail('Se esperaba LogicException.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('estado error', $exception->getMessage());
        }

        $this->assertDatabaseCount('diagnosticos_ia', 0);
        $this->assertDatabaseCount('log_auditorias', 0);
    }

    public function test_marca_fallo_del_proveedor_sin_persistir_mensaje_de_excepcion(): void
    {
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => '503']);
        $service = app(HistorialService::class);
        $diagnostico = $service->solicitar($job, $this->adminUser());
        NullLLMClient::failWith('falló para alumno.real@example.com token=secreto', 'timeout');

        $resultado = $service->procesar($diagnostico);

        $this->assertSame('error', $resultado->estado);
        $this->assertSame('timeout', $resultado->codigo_error);
        $this->assertStringNotContainsString('alumno.real@example.com', $resultado->error_saneado);
        $this->assertStringNotContainsString('secreto', json_encode($resultado->logsAuditoria));
        $this->assertNull($resultado->respuesta);
    }

    public function test_rechaza_respuesta_invalida_y_no_la_persiste(): void
    {
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => '404 Not Found']);
        $service = app(HistorialService::class);
        $diagnostico = $service->solicitar($job, $this->adminUser());
        NullLLMClient::fake('no es JSON');

        $resultado = $service->procesar($diagnostico);

        $this->assertSame('error', $resultado->estado);
        $this->assertSame('respuesta_invalida', $resultado->codigo_error);
        $this->assertNull($resultado->respuesta);
    }

    public function test_enmascara_identificadores_en_la_respuesta_antes_de_persistirla(): void
    {
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => '403 Forbidden']);
        $service = app(HistorialService::class);
        $diagnostico = $service->solicitar($job, $this->adminUser());
        NullLLMClient::fake(json_encode([
            'schema_version' => '1',
            'resumen' => 'Revisar alumno.real@example.com con identificador 123456789.',
            'causa_probable' => ['descripcion' => 'Permiso faltante', 'confianza' => 'media'],
            'evidencias' => ['correo alumno.real@example.com'],
            'pasos_verificacion' => ['validar'],
            'mitigaciones' => ['solicitar permiso'],
            'advertencias' => [],
        ], JSON_THROW_ON_ERROR));

        $resultado = $service->procesar($diagnostico);
        $json = json_encode($resultado->respuesta, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('alumno.real@example.com', $json);
        $this->assertStringNotContainsString('123456789', $json);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $json);
        $this->assertSame('completado', $resultado->estado);
    }

    public function test_rechaza_procesamiento_duplicado(): void
    {
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => '404']);
        $service = app(HistorialService::class);
        $diagnostico = $service->solicitar($job, $this->adminUser());

        $service->procesar($diagnostico);

        $this->expectException(LogicException::class);
        $service->procesar($diagnostico);
    }

    public function test_historial_pagina_resultados_y_limita_tamano_de_pagina(): void
    {
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => '500']);
        $service = app(HistorialService::class);

        foreach (range(1, 3) as $_) {
            $service->solicitar($job, $this->adminUser());
        }

        $admin = $this->adminUser();
        $page = $service->paginar($admin, 2);
        $this->assertCount(2, $page->items());
        $this->assertSame(3, $page->total());
        $this->assertSame(100, $service->paginar($admin, 500)->perPage());
    }

    public function test_rechaza_solicitantes_que_no_son_admin_ti(): void
    {
        $job = JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => '401']);
        $usuario = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(HistorialService::class)->solicitar($job, $usuario);
    }

    public function test_rechaza_consultantes_que_no_son_admin_ti(): void
    {
        $this->expectException(AuthorizationException::class);
        app(HistorialService::class)->paginar(User::factory()->create());
    }

    private function adminUser(): User
    {
        $usuario = User::factory()->create();
        $rol = Rol::query()->firstOrCreate(
            ['nombre' => 'Admin TI'],
            ['descripcion' => 'Administrador de tecnologías de información'],
        );

        Persona::factory()->create([
            'user_id' => $usuario->id,
            'rol_id' => $rol->id,
            'estado' => 'activo',
            'tipo' => 'administrativo',
        ]);

        return $usuario;
    }
}
