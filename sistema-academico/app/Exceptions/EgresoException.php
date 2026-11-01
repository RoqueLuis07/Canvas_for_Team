<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando la baja de cuentas institucionales (Canvas/Teams) de una
 * persona falla o viola una regla de negocio.
 */
class EgresoException extends RuntimeException {}
