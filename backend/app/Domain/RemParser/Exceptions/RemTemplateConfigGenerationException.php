<?php

namespace App\Domain\RemParser\Exceptions;

use RuntimeException;

/**
 * BM-3.5 (2026-09-14): se lanza cuando
 * RemTemplateConfigGeneratorService::buildConfig() no puede derivar de
 * forma segura y determinista el config grueso de una hoja -- nunca elige
 * un valor arbitrario en su lugar. Se lanza SIEMPRE antes de cualquier
 * escritura (buildConfig() es puro, sin efectos secundarios), por lo que
 * nunca deja config/enlace parcial en BD.
 */
class RemTemplateConfigGenerationException extends RuntimeException
{
}
