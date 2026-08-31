<?php

namespace App\Domain\RuleEngine\Services;

/**
 * Resuelve la celda ANCLA de una fusion VERTICAL de Excel para una celda
 * subordinada cuyo valor_bruto propio esta vacio -- reutiliza exclusivamente
 * metadata ya persistida en cell_data (es_combinada/rango_combinado, en
 * TODAS las celdas del rango fusionado, no solo la ancla -- confirmado en la
 * auditoria del punto 17.26 de CLAUDE.md), sin necesitar ningun campo nuevo
 * de escaneo ni volver a leer el Excel original.
 *
 * Extraido como servicio compartido (2026-08-28, implementacion del fix de
 * merge auditado en los puntos 17.26-17.29) para que
 * RemParserService::isEmbeddedBackwardSubtotalRow() (pipeline de
 * persistencia) y SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()
 * (capa de calibracion/patrones) reutilicen exactamente la misma logica de
 * resolucion de ancla, en vez de duplicarla -- a diferencia de otros
 * mecanismos de esta campaña (ej. isEmbeddedLeadingTotalRow), que SI se
 * duplican deliberadamente entre ambas clases porque RemParserService evita
 * depender de SectionCalibrationMatrixService (que arrastra
 * CertificationService/FunctionalRuleService/ColumnRoleResolverService) --
 * este helper es intencionalmente liviano (solo depende de
 * CellDataStorageService, ya usado por ambas clases) y no reintroduce ese
 * acoplamiento pesado.
 *
 * NO decide si la etiqueta de la ancla "parece TOTAL" -- eso sigue siendo
 * responsabilidad de cada llamador (pareceEtiquetaTotal()/pareceEtiquetaTotalMatrix(),
 * cada una ya duplicada e identica en ambas clases, sin tocar). Este
 * servicio solo resuelve QUE celda es la ancla, nunca interpreta su
 * contenido.
 */
class MergeAnchorResolver
{
    public function __construct(private CellDataStorageService $cellDataStorage)
    {
    }

    /**
     * Si $cell tiene valor_bruto vacio pero pertenece a una fusion VERTICAL
     * real (es_combinada=true, rango_combinado abarca mas de una fila en la
     * MISMA columna), devuelve la celda ANCLA (fila superior del rango),
     * resuelta directamente desde el propio rango_combinado -- sin inferir
     * nada por posicion, sin asumir ninguna fila fija. Devuelve null si:
     * $cell es null, ya tiene texto propio (no necesita resolucion), no esta
     * combinada, la fusion es horizontal o de una sola fila (encabezados
     * multi-columna no verticales quedan fuera por diseño), el
     * rango_combinado no tiene el formato esperado, o la celda ancla no
     * existe en cell_data.
     */
    public function resolveVerticalMergeAnchor(string $sheet, string $section, ?array $cell): ?array
    {
        if ($cell === null) {
            return null;
        }

        $valorBruto = $cell['valor_bruto'] ?? null;
        if ($valorBruto !== null && trim((string) $valorBruto) !== '') {
            return null;
        }

        if (($cell['es_combinada'] ?? false) !== true) {
            return null;
        }

        $rango = $cell['rango_combinado'] ?? null;
        if (!is_string($rango) || !preg_match('/^([A-Z]+)(\d+):([A-Z]+)(\d+)$/', $rango, $m)) {
            return null;
        }

        [, $colStart, $rowStart, $colEnd, $rowEnd] = $m;
        if ($colStart !== $colEnd || (int) $rowEnd <= (int) $rowStart) {
            return null;
        }

        return $this->cellDataStorage->getCellForCoordinate($sheet, $section, $colStart . $rowStart);
    }
}
