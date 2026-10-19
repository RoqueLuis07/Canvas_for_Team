<?php

namespace App\Services\LLM;

use Illuminate\Support\Str;

class DiagnosticDataMasker
{
    public const MAX_OUTPUT_LENGTH = 4000;

    /**
     * Reduce an untrusted integration error to an allow-listed diagnostic
     * category. The original message is never stored or sent to the LLM.
     *
     * @return array{categoria: string, codigo_http: int|null}
     */
    public function safeContext(?string $error): array
    {
        $error = Str::limit($error ?? '', self::MAX_OUTPUT_LENGTH, '');
        $lower = mb_strtolower($error);
        $status = $this->httpStatus($error);

        return [
            'categoria' => $this->category($lower, $status),
            'codigo_http' => $status,
        ];
    }

    /**
     * Remove common PII, identifiers, and credentials from model output.
     * Input data is not passed through this function to the model; it is
     * reduced to safeContext() first.
     */
    public function maskText(string $value): string
    {
        $patterns = [
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => '[REDACTED_TOKEN]',
            '/\b(?:sk-[A-Za-z0-9_-]{12,}|(?:ya29|eyJ)[A-Za-z0-9._-]{16,})\b/i' => '[REDACTED_SECRET]',
            '/\b(?:access[_-]?token|client[_-]?secret|password|api[_-]?key)\s*[:=]\s*["\']?[^,;\s"\']+/i' => '[REDACTED_SECRET]',
            '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i' => '[REDACTED_EMAIL]',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b/i' => '[REDACTED_ID]',
            '/(?<!\w)\+?\d[\d().\s-]{7,}\d(?!\w)/' => '[REDACTED_PHONE]',
            '/\b\d{5,}\b/' => '[REDACTED_ID]',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $masked = preg_replace($pattern, $replacement, $value);
            if ($masked === null) {
                throw new \RuntimeException('No se pudo enmascarar el contenido del diagnóstico.');
            }
            $value = $masked;
        }

        return Str::limit($value, self::MAX_OUTPUT_LENGTH, '');
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    public function maskArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $value[$key] = $this->maskText($item);
            } elseif (is_array($item)) {
                $value[$key] = $this->maskArray($item);
            }
        }

        return $value;
    }

    private function httpStatus(string $error): ?int
    {
        if (preg_match('/\b(400|401|403|404|405|408|409|410|422|429|500|501|502|503|504)\b/', $error, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function category(string $error, ?int $status): string
    {
        if ($status === 401 || str_contains($error, 'unauthorized') || str_contains($error, 'authentication')
            || str_contains($error, 'invalid_grant') || str_contains($error, 'token_expired')) {
            return 'autenticacion';
        }

        if ($status === 403 || str_contains($error, 'forbidden') || str_contains($error, 'insufficient privileges')) {
            return 'permisos';
        }

        if ($status === 404 || str_contains($error, 'not found') || str_contains($error, 'resourcenotfound')
            || str_contains($error, 'erroritemnotfound')) {
            return 'recurso_no_encontrado';
        }

        if ($status === 410 || str_contains($error, 'resource_gone') || str_contains($error, ' has been deleted')) {
            return 'recurso_eliminado';
        }

        if ($status === 409 || str_contains($error, 'already exists') || str_contains($error, 'duplicate')
            || str_contains($error, 'mailnicknameinuse') || str_contains($error, 'objectconflict')) {
            return 'conflicto';
        }

        if ($status === 400 || $status === 422 || str_contains($error, 'bad request') || str_contains($error, 'request_badrequest')
            || str_contains($error, 'invalid_request') || str_contains($error, 'validation')) {
            return 'solicitud_invalida';
        }

        if ($status === 405 || str_contains($error, 'method not allowed')) {
            return 'metodo_no_permitido';
        }

        if ($status === 429 || str_contains($error, 'too many requests') || str_contains($error, 'throttl')) {
            return 'limite_solicitudes';
        }

        if ($status === 408 || $status === 504
            || str_contains($error, 'timeout')
            || str_contains($error, 'timed out')
            || str_contains($error, 'gateway timeout')) {
            return 'tiempo_agotado';
        }

        if (($status !== null && $status >= 500)
            || str_contains($error, 'service unavailable')) {
            return 'servicio_no_disponible';
        }

        if (str_contains($error, 'connection refused') || str_contains($error, 'could not resolve host')
            || str_contains($error, 'dns') || str_contains($error, 'ssl certificate')) {
            return 'error_conexion';
        }

        return 'desconocido';
    }
}
