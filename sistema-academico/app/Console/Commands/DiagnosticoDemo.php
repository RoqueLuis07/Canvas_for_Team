<?php

namespace App\Console\Commands;

use App\Models\JobSincronizacion;
use App\Services\LLM\DiagnosticDataMasker;
use App\Services\LLM\NullLLMClient;
use Illuminate\Console\Command;

class DiagnosticoDemo extends Command
{
    protected $signature = 'diagnostico:demo {mensaje? : Mensaje de error a simular} {--sembrar : Crea jobs en error de ejemplo} {--listar : Muestra errores y claves reconocidos}';

    protected $description = 'Prueba el LLM simulado sin clave API ni cuota';

    private const EJEMPLOS = [
        '401 Unauthorized: invalid access token',
        'OAuth invalid_grant: token_expired',
        '403 Forbidden: insufficient privileges',
        '403 Authorization_RequestDenied',
        '404 Not Found: user not found',
        '404 Request_ResourceNotFound',
        '409 Conflict: user already exists',
        '400 Bad Request: invalid property',
        '405 Method Not Allowed',
        '410 Gone: resource deleted',
        '422 Validation failed: invalid field format',
        '429 Too Many Requests',
        '408 Request Timeout',
        '500 Internal Server Error',
        '502 Bad Gateway',
        '503 Service Unavailable',
        '504 Gateway Timeout',
        'Connection refused: DNS lookup failed',
        'Error raro sin código',
    ];

    public function handle(DiagnosticDataMasker $masker): int
    {
        if ($this->option('listar')) {
            foreach (self::EJEMPLOS as $mensaje) {
                $contexto = $masker->safeContext($mensaje);
                $this->line(sprintf(
                    '%-30s %s',
                    $mensaje,
                    json_encode($contexto, JSON_UNESCAPED_UNICODE),
                ));
            }

            return self::SUCCESS;
        }

        if ($this->option('sembrar')) {
            foreach (self::EJEMPLOS as $mensaje) {
                JobSincronizacion::factory()->create(['estado' => 'error', 'resultado' => $mensaje]);
            }
            $this->info(count(self::EJEMPLOS).' jobs en error creados. Véalos en /admin → Jobs de sincronización.');

            return self::SUCCESS;
        }

        $mensaje = $this->argument('mensaje') ?? self::EJEMPLOS[4];
        $contexto = $masker->safeContext($mensaje);
        $this->line('Contexto enviado al LLM (enmascarado): '.json_encode($contexto, JSON_UNESCAPED_UNICODE));

        $respuesta = (new NullLLMClient)->chat([
            ['role' => 'user', 'content' => json_encode($contexto, JSON_UNESCAPED_UNICODE)],
        ]);
        $this->line($respuesta->content);

        return self::SUCCESS;
    }
}
