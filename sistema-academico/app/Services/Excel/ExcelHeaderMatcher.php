<?php

namespace App\Services\Excel;

/**
 * Hace flexible la lectura de encabezados de una planilla subida por un
 * usuario: encuentra la columna correcta sin importar tildes, mayúsculas,
 * espacios extra, o si el encabezado está en español o inglés — igual que
 * el sistema FastAPI en producción.
 *
 * Puerto de `_norm`/`_match_col_idx`/`_get` de `Backend/app/routers/excel.py`.
 */
class ExcelHeaderMatcher
{
    /**
     * Normaliza un encabezado: minúsculas, sin tildes, solo alfanumérico
     * (cualquier otra cosa se reemplaza por "_", sin "_" sueltos en las
     * puntas). Ej: "Cédula  (CI)" -> "cedula_ci".
     */
    public function normalize(string $header): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header) ?: $header;
        $lower = strtolower(trim($transliterated));
        $withUnderscores = preg_replace('/[^a-z0-9]+/', '_', $lower) ?? '';

        return trim($withUnderscores, '_');
    }

    /**
     * Busca, entre un mapa de encabezados ya normalizados ([encabezado =>
     * índice de columna]), cuál corresponde a alguna de las `$candidateKeys`
     * (ej. "cedula", "ci", "documento").
     *
     * Prioridad: primero se prueban TODAS las keys por coincidencia EXACTA
     * contra TODOS los encabezados; solo si ninguna coincide exacto, se cae
     * a coincidencia parcial (substring). Este orden es intencional: una
     * key corta como "ci" es abreviatura de Cédula, pero también aparece
     * como substring de palabras comunes en español ("inscripción",
     * "servicio", "comercial"...) — sin priorizar la coincidencia exacta,
     * una columna no relacionada puede "ganarle" a la columna real de
     * Cédula/CI y corromper en silencio la contraseña generada
     * (cedula-Iniciales termina siendo texto_de_otra_columna-Iniciales).
     *
     * @param  array<string, int>  $normalizedHeaders  [encabezado_normalizado => índice_columna]
     */
    public function matchColumn(array $normalizedHeaders, string ...$candidateKeys): ?int
    {
        $normalizedKeys = array_map($this->normalize(...), $candidateKeys);

        foreach ($normalizedKeys as $key) {
            foreach ($normalizedHeaders as $header => $index) {
                if ($header === $key) {
                    return $index;
                }
            }
        }

        foreach ($normalizedKeys as $key) {
            if ($key === '') {
                continue;
            }

            foreach ($normalizedHeaders as $header => $index) {
                if (str_contains($header, $key)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * Como matchColumn(), pero devuelve el nombre del encabezado normalizado
     * que matcheó en vez de su índice — para cuando se va a usar ese nombre
     * para buscar el valor en filas que ya vienen indexadas por encabezado
     * (ver XlsxRowReader, que arma cada fila como [encabezado => valor]).
     *
     * Resolver el encabezado real UNA sola vez con este método (en vez de
     * volver a probar los candidatos contra cada fila con firstNonEmpty) es
     * lo que hace que el fallback por substring de matchColumn() realmente
     * se aplique: si se vuelve a buscar "cedula"/"ci"/"documento" fila por
     * fila como claves literales del diccionario, un encabezado real que
     * sólo CONTIENE una de esas palabras (ej. "Nro. de Documento") nunca
     * matchea, porque la búsqueda por clave de diccionario es siempre
     * exacta, nunca por substring.
     *
     * @param  array<string, int>  $normalizedHeaders
     */
    public function matchHeaderName(array $normalizedHeaders, string ...$candidateKeys): ?string
    {
        $index = $this->matchColumn($normalizedHeaders, ...$candidateKeys);

        if ($index === null) {
            return null;
        }

        foreach ($normalizedHeaders as $header => $headerIndex) {
            if ($headerIndex === $index) {
                return $header;
            }
        }

        return null;
    }

    /**
     * Prueba varias variantes de una misma columna (español + inglés,
     * encabezado crudo o ya normalizado) contra una fila, y devuelve el
     * primer valor no vacío encontrado (recortado). Puerto de `_get()`.
     *
     * @param  array<string, mixed>  $row  [encabezado => valor] — tanto con
     *                                      el encabezado crudo como el
     *                                      normalizado como clave.
     */
    public function firstNonEmpty(array $row, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? $row[$this->normalize($key)] ?? null;

            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
