<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando la importación de Diplomados no puede ni siquiera
 * empezar (URL inválida, archivo no descargable, pestaña inexistente, no
 * se encontró una fila de encabezados reconocible). Un error al procesar
 * UNA fila (alumno) no usa esta excepción — se captura por fila y se
 * agrega al listado de fallidos del resultado, sin abortar el resto del
 * lote (mismo criterio que PersonaImporter/CursoImporter).
 */
class DiplomadoImportException extends RuntimeException {}
