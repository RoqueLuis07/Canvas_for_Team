<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando una alta en materias viola una regla de negocio
 * (prerrequisito incumplido, cupo agotado, materia ya inscrita, etc.).
 */
class InscripcionMateriaException extends RuntimeException {}
