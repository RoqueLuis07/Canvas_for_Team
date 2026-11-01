<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando el alta de cuentas institucionales (Canvas/Teams) de una
 * persona falla o viola una regla de negocio (datos inválidos, cuenta ya
 * existente sin poder resolverla, error de plataforma).
 */
class IngresoException extends RuntimeException {}
