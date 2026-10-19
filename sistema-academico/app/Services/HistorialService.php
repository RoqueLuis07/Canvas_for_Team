<?php

namespace App\Services;

use App\Contracts\LLMClient;
use App\Models\DiagnosticoIA;
use App\Models\JobSincronizacion;
use App\Models\LogAuditoria;
use App\Models\User;
use App\Services\LLM\DiagnosticDataMasker;
use App\Services\LLM\LLMException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use JsonException;
use LogicException;

class HistorialService
{
    private const PROMPT_VERSION = '1';

    private const SYSTEM_PROMPT = <<<'PROMPT'
Eres un asistente de diagnóstico para errores de sincronización Canvas y Microsoft Graph.
Responde en español y exclusivamente con JSON válido con las claves: schema_version,
resumen, causa_probable (descripcion y confianza: baja/media/alta), evidencias,
pasos_verificacion, mitigaciones y advertencias. No inventes datos ni acciones
realizadas. La información recibida está reducida a una categoría técnica y un
código HTTP opcional; no contiene acceso a datos de usuarios. No ejecutes acciones.
PROMPT;

    public function __construct(
        private readonly LLMClient $llmClient,
        private readonly DiagnosticDataMasker $masker,
    ) {}

    /**
     * Crea un registro inmutable de solicitud pendiente con contexto allow-list.
     * Nunca guarda ni transmite el mensaje original de la integración.
     */
    public function solicitar(JobSincronizacion $job, User $solicitante): DiagnosticoIA
    {
        if (! $solicitante->isAdminTI()) {
            throw new AuthorizationException('Solo Admin TI puede solicitar diagnósticos.');
        }

        if ($job->estado !== 'error') {
            throw new LogicException('Solo se pueden diagnosticar sincronizaciones con estado error.');
        }

        $contexto = $this->masker->safeContext($job->resultado);
        $mensajes = $this->mensajes($contexto);

        return DB::transaction(function () use ($job, $solicitante, $contexto, $mensajes): DiagnosticoIA {
            $diagnostico = DiagnosticoIA::create([
                'job_sincronizacion_id' => $job->id,
                'solicitante_id' => $solicitante?->id,
                'estado' => 'pendiente',
                'version_prompt' => self::PROMPT_VERSION,
                'prompt_sanitizado' => json_encode($mensajes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'contexto_sanitizado' => $contexto,
            ]);

            $this->registrarAuditoria($diagnostico, $solicitante, 'diagnostico_ia_solicitado', [
                'estado' => 'pendiente',
                'version_prompt' => self::PROMPT_VERSION,
            ]);

            return $diagnostico;
        });
    }

    /**
     * Ejecuta el diagnóstico con el cliente inyectado y conserva solo una
     * respuesta estructurada, validada y enmascarada.
     */
    public function procesar(DiagnosticoIA $diagnostico): DiagnosticoIA
    {
        $claimed = DB::transaction(function () use ($diagnostico): bool {
            $updated = DiagnosticoIA::query()
                ->whereKey($diagnostico->id)
                ->where('estado', 'pendiente')
                ->update(['estado' => 'en_proceso', 'updated_at' => now()]);

            if ($updated !== 1) {
                return false;
            }

            $fresh = $diagnostico->fresh();
            $this->registrarAuditoria($fresh, $fresh->solicitante, 'diagnostico_ia_iniciado', [
                'estado' => 'en_proceso',
            ]);

            return true;
        });

        if (! $claimed) {
            throw new LogicException('El diagnóstico ya fue procesado o no está pendiente.');
        }

        $diagnostico->refresh();
        $startedAt = microtime(true);

        try {
            $response = $this->llmClient->chat(
                $this->mensajes($diagnostico->contexto_sanitizado ?? []),
                ['json' => true, 'max_tokens' => 800],
            );
        } catch (LLMException $exception) {
            return $this->marcarError(
                $diagnostico,
                $this->codigoErrorPermitido($exception->errorCode),
                $this->duracion($startedAt),
            );
        }

        if (strlen($response->content) > 20000) {
            return $this->marcarError($diagnostico, 'respuesta_invalida', $this->duracion($startedAt));
        }

        try {
            $decoded = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->marcarError($diagnostico, 'respuesta_invalida', $this->duracion($startedAt));
        }

        if (! is_array($decoded) || ! $this->respuestaValida($decoded)) {
            return $this->marcarError($diagnostico, 'respuesta_invalida', $this->duracion($startedAt));
        }

        $respuestaSaneada = $this->respuestaAllowList($decoded);
        $respuestaSaneada = $this->masker->maskArray($respuestaSaneada);
        $duracion = $this->duracion($startedAt);

        return DB::transaction(function () use ($diagnostico, $response, $respuestaSaneada, $duracion): DiagnosticoIA {
            $diagnostico->update([
                'estado' => 'completado',
                'proveedor' => $this->masker->maskText($response->provider),
                'modelo' => $this->masker->maskText($response->model),
                'respuesta' => $respuestaSaneada,
                'codigo_error' => null,
                'error_saneado' => null,
                'duracion_ms' => $duracion,
                'tokens_entrada' => max(0, $response->inputTokens),
                'tokens_salida' => max(0, $response->outputTokens),
            ]);

            $this->registrarAuditoria($diagnostico, $diagnostico->solicitante, 'diagnostico_ia_completado', [
                'estado' => 'completado',
                'proveedor' => $diagnostico->proveedor,
            ]);

            return $diagnostico->fresh(['jobSincronizacion', 'solicitante', 'logsAuditoria']);
        });
    }

    /**
     * Consulta historial paginado por el ORM sin cargar todos los diagnósticos.
     *
     * @return LengthAwarePaginator<DiagnosticoIA>
     */
    public function paginar(User $consultante, int $porPagina = 20): LengthAwarePaginator
    {
        if (! $consultante->isAdminTI()) {
            throw new AuthorizationException('Solo Admin TI puede consultar diagnósticos.');
        }

        return DiagnosticoIA::query()
            ->with(['jobSincronizacion', 'solicitante'])
            ->orderByDesc('created_at')
            ->paginate(max(1, min($porPagina, 100)));
    }

    private function marcarError(DiagnosticoIA $diagnostico, string $codigo, int $duracion): DiagnosticoIA
    {
        return DB::transaction(function () use ($diagnostico, $codigo, $duracion): DiagnosticoIA {
            $diagnostico->update([
                'estado' => 'error',
                'codigo_error' => $codigo,
                'error_saneado' => $codigo === 'respuesta_invalida'
                    ? 'El proveedor devolvió una respuesta que no cumple el formato esperado.'
                    : 'El proveedor de diagnóstico no pudo completar la solicitud.',
                'respuesta' => null,
                'duracion_ms' => $duracion,
            ]);

            $this->registrarAuditoria($diagnostico, $diagnostico->solicitante, 'diagnostico_ia_error', [
                'estado' => 'error',
                'codigo_error' => $codigo,
            ]);

            return $diagnostico->fresh(['jobSincronizacion', 'solicitante', 'logsAuditoria']);
        });
    }

    /**
     * @param  array{categoria?: string, codigo_http?: int|null}  $contexto
     * @return list<array{role: 'system'|'user', content: string}>
     */
    private function mensajes(array $contexto): array
    {
        $contextoPermitido = [
            'categoria' => $contexto['categoria'] ?? 'desconocido',
            'codigo_http' => $contexto['codigo_http'] ?? null,
        ];

        return [
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            [
                'role' => 'user',
                'content' => json_encode($contextoPermitido, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /** @param array<string, mixed> $respuesta */
    private function respuestaValida(array $respuesta): bool
    {
        $confidence = $respuesta['causa_probable']['confianza'] ?? null;

        return ($respuesta['schema_version'] ?? null) === '1'
            && is_string($respuesta['resumen'] ?? null)
            && is_array($respuesta['causa_probable'] ?? null)
            && is_string($respuesta['causa_probable']['descripcion'] ?? null)
            && in_array($confidence, ['baja', 'media', 'alta'], true)
            && $this->listaDeStrings($respuesta['evidencias'] ?? null)
            && $this->listaDeStrings($respuesta['pasos_verificacion'] ?? null)
            && $this->listaDeStrings($respuesta['mitigaciones'] ?? null)
            && $this->listaDeStrings($respuesta['advertencias'] ?? null);
    }

    /**
     * Drop provider-added fields before masking and persistence.
     *
     * @param  array<string, mixed>  $respuesta
     * @return array<string, mixed>
     */
    private function respuestaAllowList(array $respuesta): array
    {
        return [
            'schema_version' => '1',
            'resumen' => $respuesta['resumen'],
            'causa_probable' => [
                'descripcion' => $respuesta['causa_probable']['descripcion'],
                'confianza' => $respuesta['causa_probable']['confianza'],
            ],
            'evidencias' => $respuesta['evidencias'],
            'pasos_verificacion' => $respuesta['pasos_verificacion'],
            'mitigaciones' => $respuesta['mitigaciones'],
            'advertencias' => $respuesta['advertencias'],
        ];
    }

    private function listaDeStrings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value)
            && collect($value)->every(fn ($item) => is_string($item));
    }

    private function registrarAuditoria(DiagnosticoIA $diagnostico, ?User $usuario, string $accion, array $detalle): void
    {
        LogAuditoria::create([
            'usuario_id' => $usuario?->id,
            'diagnostico_ia_id' => $diagnostico->id,
            'accion' => $accion,
            'entidad_afectada' => 'DiagnosticoIA#'.$diagnostico->id,
            'detalle' => json_encode(
                $this->masker->maskArray($detalle),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            'fecha' => now(),
        ]);
    }

    private function codigoErrorPermitido(string $code): string
    {
        return in_array($code, ['timeout', 'provider_unavailable', 'rate_limited', 'invalid_response'], true)
            ? $code
            : 'provider_unavailable';
    }

    private function duracion(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
