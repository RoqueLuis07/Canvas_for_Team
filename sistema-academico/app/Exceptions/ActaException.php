<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando falla la generación/conversión del documento de una Acta
 * (plantilla, conversión a PDF, o el propio flujo de alta/edición).
 */
class ActaException extends RuntimeException {}
