<?php

namespace App\Domain\RuleEngine\Exceptions;

use RuntimeException;

/**
 * Lanzada por RemRuleManifestImporterService cuando el manifiesto o el
 * estado del entorno no cumplen alguna de las validaciones fail-closed
 * (JSON invalido, conteo distinto al declarado, rule_key duplicada dentro
 * del manifiesto, config no normalizable, serie/anio invalidos, estructura
 * activa ausente o ambigua, hoja/seccion inexistente en la estructura, o
 * una rule_key ya existente en BD con contenido incompatible). Nunca se
 * lanza despues de haber escrito ninguna fila -- toda validacion ocurre
 * antes de abrir la transaccion de escritura.
 */
class RuleManifestImportException extends RuntimeException
{
}
