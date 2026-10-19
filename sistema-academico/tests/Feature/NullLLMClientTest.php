<?php

namespace Tests\Feature;

use App\Contracts\LLMClient;
use App\Services\LLM\DiagnosticDataMasker;
use App\Services\LLM\LLMException;
use App\Services\LLM\NullLLMClient;
use Tests\TestCase;

class NullLLMClientTest extends TestCase
{
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

    public function test_el_contenedor_resuelve_el_cliente_simulado_sin_clave_api(): void
    {
        $this->assertInstanceOf(NullLLMClient::class, app(LLMClient::class));
    }

    public function test_responde_json_estructurado_en_espanol_segun_el_error(): void
    {
        $respuesta = app(LLMClient::class)->chat([
            ['role' => 'system', 'content' => 'Diagnostica errores.'],
            ['role' => 'user', 'content' => '429 Too Many Requests'],
        ], ['json' => true]);

        $datos = json_decode($respuesta->content, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('1', $datos['schema_version']);
        $this->assertSame('alta', $datos['causa_probable']['confianza']);
        $this->assertStringContainsString('limitó', $datos['resumen']);
        $this->assertSame('simulado', $respuesta->provider);
        $this->assertGreaterThan(0, $respuesta->outputTokens);
    }

    public function test_error_desconocido_devuelve_diagnostico_de_baja_confianza(): void
    {
        $respuesta = app(LLMClient::class)->chat([['role' => 'user', 'content' => 'xyz raro']]);

        $datos = json_decode($respuesta->content, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('baja', $datos['causa_probable']['confianza']);
    }

    public function test_diagnostica_deterministicamente_categorias_adicionales(): void
    {
        $casos = [
            'OAuth invalid_grant: token_expired' => 'autenticacion',
            '403 Authorization_RequestDenied' => 'permisos',
            '404 ErrorItemNotFound' => 'recurso_no_encontrado',
            '410 Gone: resource deleted' => 'recurso_eliminado',
            '400 Bad Request: invalid property' => 'solicitud_invalida',
            '405 Method Not Allowed' => 'metodo_no_permitido',
            '422 Validation failed' => 'solicitud_invalida',
            '408 Request Timeout' => 'tiempo_agotado',
            '502 Bad Gateway' => 'servicio_no_disponible',
            '504 Gateway Timeout' => 'tiempo_agotado',
            'Connection refused: DNS lookup failed' => 'error_conexion',
        ];
        $masker = app(DiagnosticDataMasker::class);
        $cliente = app(LLMClient::class);

        foreach ($casos as $mensaje => $categoriaEsperada) {
            $contexto = $masker->safeContext($mensaje);
            $this->assertSame($categoriaEsperada, $contexto['categoria'], $mensaje);

            $mensajes = [['role' => 'user', 'content' => json_encode($contexto, JSON_THROW_ON_ERROR)]];
            $primera = $cliente->chat($mensajes)->content;
            $segunda = $cliente->chat($mensajes)->content;

            $this->assertSame($primera, $segunda, $mensaje);
            $this->assertNotSame('', json_decode($primera, true, flags: JSON_THROW_ON_ERROR)['resumen'], $mensaje);
        }
    }

    public function test_fake_devuelve_respuestas_encoladas_en_orden_y_registra_llamadas(): void
    {
        NullLLMClient::fake('primera', fn (array $mensajes) => 'eco: '.$mensajes[0]['content']);

        $cliente = app(LLMClient::class);

        $this->assertSame('primera', $cliente->chat([['role' => 'user', 'content' => 'a']])->content);
        $this->assertSame('eco: b', $cliente->chat([['role' => 'user', 'content' => 'b']])->content);
        $this->assertCount(2, NullLLMClient::calls());
    }

    public function test_fail_with_lanza_excepcion_con_codigo_estable(): void
    {
        NullLLMClient::failWith('Tiempo agotado', 'timeout');

        try {
            app(LLMClient::class)->chat([['role' => 'user', 'content' => 'x']]);
            $this->fail('Debía lanzar LLMException.');
        } catch (LLMException $e) {
            $this->assertSame('timeout', $e->errorCode);
        }
    }

    public function test_formato_openai(): void
    {
        $formato = app(LLMClient::class)->chat([['role' => 'user', 'content' => '404']])->toOpenAiFormat();

        $this->assertSame('assistant', $formato['choices'][0]['message']['role']);
        $this->assertSame(
            $formato['usage']['prompt_tokens'] + $formato['usage']['completion_tokens'],
            $formato['usage']['total_tokens'],
        );
    }
}
