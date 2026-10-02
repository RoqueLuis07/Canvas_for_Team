<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando falla la creación/gestión de un Curso en Canvas o de un
 * Equipo en Teams.
 */
class CursoException extends RuntimeException {}
