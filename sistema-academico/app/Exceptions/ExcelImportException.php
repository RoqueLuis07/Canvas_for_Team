<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando una importación masiva desde Excel no puede ni siquiera
 * empezar (archivo inválido, sin filas, excede el límite de seguridad de
 * filas). Un error en UNA fila durante la importación no usa esta
 * excepción — se captura por fila y se agrega al listado de fallidos del
 * resultado, sin abortar el resto del lote.
 */
class ExcelImportException extends RuntimeException {}
