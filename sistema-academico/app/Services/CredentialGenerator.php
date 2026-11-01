<?php

namespace App\Services;

/**
 * Genera credenciales institucionales (correo, login_id, contraseña) a
 * partir del nombre completo y la cédula de una persona.
 *
 * Mismo esquema que usa el sistema FastAPI en producción (Canvas_for_Teams),
 * para que una persona dada de alta desde cualquiera de los dos sistemas
 * reciba las mismas credenciales:
 *
 *   Karen Gonzalez + 6868066 ->
 *     login_id: karen.gonzalez
 *     email:    karen.gonzalez@usil.edu.py
 *     password: 6868066-Kg
 */
class CredentialGenerator
{
    /**
     * Separa un nombre completo en (nombre, apellido principal, palabras
     * extra) usando heurísticas para nombres en español.
     *
     * @return array{0: string, 1: string, 2: list<string>}
     */
    public function parseName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === [] || $parts === false) {
            return ['user', 'user', []];
        }

        $count = count($parts);

        if ($count === 1) {
            return [$parts[0], $parts[0], []];
        }

        if ($count === 2) {
            return [$parts[0], $parts[1], []];
        }

        if ($count === 3) {
            // Ej: Juan Perez Gomez -> Juan, Perez, [Gomez]
            return [$parts[0], $parts[1], [$parts[2]]];
        }

        if ($count === 4) {
            // Ej: Roque Luis Esteche Cantero -> Roque, Esteche, [Cantero, Luis]
            return [$parts[0], $parts[2], [$parts[3], $parts[1]]];
        }

        // 5+ palabras (ej. Maria de los Angeles Perez Gomez)
        // Heurística: la primera palabra es el nombre, la anteúltima es el
        // apellido principal.
        return [$parts[0], $parts[$count - 2], [$parts[$count - 1]]];
    }

    /**
     * Normaliza texto para usarlo en un login/email: sin tildes, sin
     * mayúsculas, solo alfanumérico.
     */
    protected function normalize(string $text): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;

        return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $transliterated) ?? '');
    }

    /**
     * Genera las credenciales institucionales base para una persona.
     *
     * @return array{
     *     full_name: string, cedula: string, login_id: string,
     *     email: string, password: string, display_name: string
     * }
     */
    public function generateCredentials(string $fullName, string $cedula, string $domain, string $collisionSuffix = ''): array
    {
        [$first, $last] = $this->parseName($fullName);

        $firstNorm = $this->normalize($first) ?: 'user';
        $lastNorm = $this->normalize($last) ?: 'user';
        $suffix = $this->normalize($collisionSuffix);

        $loginId = "{$firstNorm}.{$lastNorm}{$suffix}";
        $email = "{$loginId}@{$domain}";

        $password = $this->generatePassword($cedula, $fullName);

        return [
            'full_name' => $fullName,
            'cedula' => $cedula,
            'login_id' => $loginId,
            'email' => $email,
            'password' => $password,
            'display_name' => $fullName,
        ];
    }

    /**
     * Contraseña estándar: cédula + guion + inicial de nombre (mayúscula) +
     * inicial de apellido (minúscula). Ej: "6868066-Kg".
     */
    public function generatePassword(string $cedula, string $fullName): string
    {
        [$first, $last] = $this->parseName($fullName);

        $firstNorm = $this->normalize($first);
        $lastNorm = $this->normalize($last);

        $firstInitial = $firstNorm !== '' ? strtoupper($firstNorm[0]) : 'U';
        $lastInitial = $lastNorm !== '' ? strtolower($lastNorm[0]) : 's';

        return "{$cedula}-{$firstInitial}{$lastInitial}";
    }
}
