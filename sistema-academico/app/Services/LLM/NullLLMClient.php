<?php

namespace App\Services\LLM;

use App\Contracts\LLMClient;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Simula un LLM estilo OpenAI sin red, clave API ni consumo de cuota. Se usa
 * automáticamente cuando no hay un proveedor real configurado.
 *
 * Por defecto responde de forma determinista con un diagnóstico en español
 * según palabras clave del error (códigos HTTP y de Microsoft Graph/Canvas).
 * Los tests pueden forzar respuestas o fallos con `fake()` y `failWith()`.
 */
class NullLLMClient implements LLMClient
{
    public const PROVIDER = 'simulado';

    public const MODEL = 'mock-gpt';

    /** @var list<string|Closure(list<array<string, string>>): string> */
    protected static array $queue = [];

    protected static ?LLMException $failure = null;

    /** @var list<list<array<string, string>>> */
    protected static array $calls = [];

    /**
     * Encola respuestas que se devolverán en orden en lugar de la heurística.
     *
     * @param  string|Closure(list<array<string, string>>): string  ...$responses
     */
    public static function fake(string|Closure ...$responses): void
    {
        self::reset();
        self::$queue = array_values($responses);
    }

    /** Hace que toda llamada lance la excepción indicada. */
    public static function failWith(string $message = 'Proveedor simulado no disponible', string $errorCode = 'provider_unavailable'): void
    {
        self::reset();
        self::$failure = new LLMException($message, $errorCode);
    }

    public static function reset(): void
    {
        self::$queue = [];
        self::$failure = null;
        self::$calls = [];
    }

    /** @return list<list<array<string, string>>> Conversaciones recibidas, para aserciones. */
    public static function calls(): array
    {
        return self::$calls;
    }

    public function chat(array $messages, array $options = []): LLMResponse
    {
        self::$calls[] = $messages;
        Log::info('[LLM simulado] Solicitud recibida ('.count($messages).' mensajes)');

        if (self::$failure !== null) {
            throw self::$failure;
        }

        $next = array_shift(self::$queue);
        $content = match (true) {
            $next instanceof Closure => $next($messages),
            is_string($next) => $next,
            default => $this->respuestaPorDefecto($messages),
        };

        $input = $this->estimarTokens(implode(' ', array_column($messages, 'content')));

        return new LLMResponse($content, self::PROVIDER, self::MODEL, $input, $this->estimarTokens($content));
    }

    /**
     * @param  list<array<string, string>>  $messages
     */
    protected function respuestaPorDefecto(array $messages): string
    {
        $error = '';
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') === 'user') {
                $error = $message['content'];
                break;
            }
        }

        [$resumen, $causa, $confianza, $pasos, $mitigaciones] = $this->clasificar(mb_strtolower($error));

        return json_encode([
            'schema_version' => '1',
            'resumen' => $resumen,
            'causa_probable' => ['descripcion' => $causa, 'confianza' => $confianza],
            'evidencias' => ['Mensaje de error recibido de la integración (análisis simulado).'],
            'pasos_verificacion' => $pasos,
            'mitigaciones' => $mitigaciones,
            'advertencias' => ['Diagnóstico generado por un LLM simulado; es solo una sugerencia.'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: list<string>, 4: list<string>}
     */
    protected function clasificar(string $error): array
    {
        $reglas = [
            [['401', 'unauthorized', 'invalidauthenticationtoken', 'invalid access token', 'autenticacion'],
                'La integración fue rechazada por credenciales inválidas o vencidas.',
                'El token de acceso de Canvas o Microsoft Graph expiró o es incorrecto.', 'media',
                ['Revisar CANVAS_ACCESS_TOKEN y las credenciales de Azure.', 'Comprobar la fecha de vencimiento del secreto.'],
                ['Renovar el token o secreto y reintentar la operación manualmente.']],
            [['invalid_grant', 'token_expired', 'expired token'],
                'El proveedor rechazó una credencial vencida o revocada.',
                'El token de acceso o de actualización expiró, fue revocado o no corresponde al cliente configurado.', 'media',
                ['Revisar la hora del servidor, el estado del consentimiento y el flujo de renovación de tokens.'],
                ['Renovar las credenciales mediante el flujo autorizado y reintentar manualmente.']],
            [['403', 'forbidden', 'authorization_requestdenied', 'insufficient privileges', 'permisos'],
                'La cuenta de integración no tiene permisos para esta operación.',
                'Faltan permisos de aplicación en Canvas o Microsoft Graph.', 'media',
                ['Verificar los permisos de la aplicación en Azure AD y el rol de la cuenta en Canvas.'],
                ['Solicitar el permiso faltante y otorgar el consentimiento de administrador.']],
            [['404', 'not found', 'resource_notfound', 'request_resourcenotfound', 'recurso_no_encontrado'],
                'El recurso solicitado no existe en la plataforma externa.',
                'El usuario, curso o equipo aún no fue creado o se eliminó.', 'media',
                ['Confirmar que el usuario/curso existe en Canvas o en el directorio de Azure.', 'Revisar que los identificadores locales estén completos.'],
                ['Crear el recurso faltante y volver a ejecutar la sincronización.']],
            [['409', 'already exists', 'objectconflict', 'duplicate', 'conflicto'],
                'El recurso ya existe en la plataforma externa.',
                'Se intentó crear un registro duplicado (por ejemplo, un usuario o equipo ya creado).', 'media',
                ['Buscar el registro existente por correo o identificador SIS.'],
                ['Vincular el registro existente en lugar de crear uno nuevo.']],
            [['solicitud_invalida', '400', '422', 'bad request', 'invalid_request', 'validation', 'invalid property'],
                'La solicitud enviada a la plataforma externa no pasó la validación.',
                'Falta un campo obligatorio, hay un formato inválido o los datos no cumplen las reglas del servicio.', 'media',
                ['Revisar los campos requeridos y el formato de los datos de la solicitud sin exponer información personal.'],
                ['Corregir el mapeo o los datos de origen antes de reintentar la sincronización.']],
            [['metodo_no_permitido', '405', 'method not allowed'],
                'La plataforma externa no permite ese método para el recurso solicitado.',
                'La integración pudo usar un verbo HTTP o una ruta no compatible con el endpoint.', 'media',
                ['Confirmar la versión del endpoint y el método HTTP documentado para la operación.'],
                ['Corregir la configuración de la integración antes de reintentar.']],
            [['recurso_eliminado', '410', 'resource_gone'],
                'El recurso existió, pero fue eliminado o dejó de estar disponible.',
                'La sincronización intenta operar sobre un recurso retirado de la plataforma externa.', 'media',
                ['Verificar el estado del recurso y si existe un reemplazo vigente.'],
                ['Actualizar la referencia local o recrear el recurso mediante el flujo operativo aprobado.']],
            [['429', 'too many requests', 'throttl', 'rate limit', 'limite_solicitudes'],
                'La plataforma externa limitó la cantidad de solicitudes.',
                'Se superó el límite de peticiones permitido en un lapso corto.', 'alta',
                ['Revisar el encabezado Retry-After de la respuesta.'],
                ['Esperar unos minutos y reintentar; evitar sincronizaciones masivas simultáneas.']],
            [['tiempo_agotado', '408', '504', 'timeout', 'timed out', 'gateway timeout'],
                'La solicitud excedió el tiempo de espera.',
                'La red o el servicio externo no respondió dentro del tiempo permitido.', 'media',
                ['Revisar latencia, duración de la operación y estado del servicio externo.'],
                ['Reintentar más tarde con una política de espera controlada; evitar reintentos repetidos inmediatos.']],
            [['error_conexion', 'connection refused', 'could not resolve host', 'ssl certificate'],
                'No se pudo establecer una conexión segura con la plataforma externa.',
                'Puede haber un problema de red, DNS, firewall o validación del certificado TLS.', 'media',
                ['Comprobar conectividad saliente, resolución DNS y vigencia de certificados desde el servidor.'],
                ['Restablecer la conectividad o corregir el certificado y luego reintentar manualmente.']],
            [['500', '501', '502', '503', 'service unavailable', 'servicio_no_disponible'],
                'La plataforma externa no respondió correctamente.',
                'Falla temporal de red o indisponibilidad del servicio de Canvas/Microsoft.', 'media',
                ['Consultar el estado del servicio de Canvas y Microsoft 365.'],
                ['Reintentar más tarde; si persiste, escalar al proveedor.']],
        ];

        foreach ($reglas as [$claves, $resumen, $causa, $confianza, $pasos, $mitigaciones]) {
            foreach ($claves as $clave) {
                if (str_contains($error, $clave)) {
                    return [$resumen, $causa, $confianza, $pasos, $mitigaciones];
                }
            }
        }

        return [
            'No se pudo identificar con certeza la causa del error.',
            'El mensaje no coincide con ningún patrón conocido de Canvas o Microsoft Graph.', 'baja',
            ['Revisar el mensaje original y el registro de auditoría del job.'],
            ['Reintentar manualmente y, si persiste, escalar al equipo técnico.'],
        ];
    }

    protected function estimarTokens(string $texto): int
    {
        return max(1, (int) ceil(mb_strlen($texto) / 4));
    }
}
