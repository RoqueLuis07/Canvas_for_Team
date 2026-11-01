<?php

namespace App\Services\Excel;

use App\Exceptions\ExcelImportException;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Lee un archivo .xlsx subido y lo convierte en filas de datos indexadas
 * por encabezado normalizado — base común para los importadores masivos
 * (Personas, Cursos).
 *
 * Simplificación deliberada vs. producción: asume que la fila 1 es siempre
 * la fila de encabezados (producción escanea hasta 15 filas buscando
 * encabezados reconocibles, para planillas con un título arriba) y solo
 * lee la primera hoja del archivo.
 */
class XlsxRowReader
{
    /** Mismo límite de seguridad que /excel/* en producción. */
    public const MAX_ROWS = 10000;

    /**
     * @return array{headers: array<string, int>, rows: list<array<string, mixed>>}
     *
     * @throws ExcelImportException
     */
    public function read(string $path, ExcelHeaderMatcher $matcher): array
    {
        if (! is_file($path)) {
            throw new ExcelImportException("No se encontró el archivo subido en \"{$path}\".");
        }

        $reader = new Reader();

        try {
            $reader->open($path);
        } catch (Throwable $e) {
            throw new ExcelImportException('El archivo no es una planilla .xlsx válida.', previous: $e);
        }

        try {
            return $this->readFirstSheet($reader, $matcher);
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array{headers: array<string, int>, rows: list<array<string, mixed>>}
     */
    protected function readFirstSheet(Reader $reader, ExcelHeaderMatcher $matcher): array
    {
        $headers = [];
        $rows = [];
        $rowNumber = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowNumber++;
                $values = $row->toArray();

                if ($rowNumber === 1) {
                    foreach ($values as $index => $value) {
                        $normalized = $matcher->normalize((string) $value);

                        if ($normalized !== '') {
                            $headers[$normalized] = $index;
                        }
                    }

                    continue;
                }

                if (($rowNumber - 1) > self::MAX_ROWS) {
                    throw new ExcelImportException('Máximo '.self::MAX_ROWS.' filas permitidas por archivo.');
                }

                if ($this->esFilaVacia($values)) {
                    continue;
                }

                $fila = [];

                foreach ($headers as $header => $index) {
                    $fila[$header] = $values[$index] ?? null;
                }

                $rows[] = $fila;
            }

            break; // solo la primera hoja.
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function esFilaVacia(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
