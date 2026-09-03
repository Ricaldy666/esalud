<?php

namespace App\Domain\RemParser\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando CertifiedStructurePromotionService::commit() detecta un
 * conflicto no previsto (ej. el hash certificado ya existe en el entorno
 * destino, o el paquete referencia una rule_key que no incluye) -- en
 * cualquiera de esos casos la promocion se aborta por completo antes de
 * abrir la transaccion, nunca escribe nada parcial.
 */
class PromotionAbortedException extends RuntimeException
{
}
