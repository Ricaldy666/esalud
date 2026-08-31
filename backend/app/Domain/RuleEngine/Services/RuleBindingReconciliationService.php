<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use Illuminate\Support\Collection;

/**
 * Clasifica reglas activas de rem_rules segun si un binding structure->
 * $targetStructure seria seguro (SAFE_1_TO_1), necesita revision humana
 * (REQUIRES_REMAP/DUPLICATE), no tiene destino (ORPHAN), esta bloqueada
 * por una deuda del evaluador independiente del binding
 * (BLOCKED_BY_ENGINE_GAP), o ya funciona sin depender de la estructura
 * (ALREADY_STRUCTURE_AGNOSTIC). Ver auditoria Fase 3 (2026-08-11).
 *
 * Solo lectura -- no persiste nada. `findSafeCandidatesForStructure()` es
 * el metodo consumido tanto por el dry-run como por el paso de escritura
 * de `rule:rebind-safe-to-structure`, para garantizar que ambos evaluan
 * exactamente la misma logica contra el estado real de la base de datos
 * en el momento en que se llaman.
 */
class RuleBindingReconciliationService
{
    public const SAFE_1_TO_1 = 'SAFE_1_TO_1';
    public const REQUIRES_REMAP = 'REQUIRES_REMAP';
    public const DUPLICATE = 'DUPLICATE';
    public const ORPHAN = 'ORPHAN';
    public const BLOCKED_BY_ENGINE_GAP = 'BLOCKED_BY_ENGINE_GAP';
    public const ALREADY_STRUCTURE_AGNOSTIC = 'ALREADY_STRUCTURE_AGNOSTIC';

    public function __construct(
        private ?RemSheetUsageStatusService $usageStatus = null,
        private ?SectionCalibrationMatrixService $calibrationMatrixService = null,
        private ?CellDataStorageService $cellDataStorage = null,
    ) {
        $this->usageStatus = $usageStatus ?? new RemSheetUsageStatusService();
        $this->calibrationMatrixService = $calibrationMatrixService ?? app(SectionCalibrationMatrixService::class);
        $this->cellDataStorage = $cellDataStorage ?? app(CellDataStorageService::class);
    }

    /**
     * Reglas SAFE_1_TO_1 para $targetStructure, excluyendo explicitamente
     * las hojas marcadas no_utilizada aunque estructuralmente calificaran.
     * Recalcula todo en vivo contra el estado actual -- nunca acepta una
     * lista de rule_id precomputada de una corrida anterior.
     *
     * @return Collection<int, array>
     */
    public function findSafeCandidatesForStructure(RemTemplateStructure $targetStructure): Collection
    {
        $classified = $this->classifyAllActiveRules($targetStructure);

        return $classified
            ->filter(fn (array $row) => $row['clasificacion'] === self::SAFE_1_TO_1 && !$row['hoja_no_utilizada'])
            ->values();
    }

    /**
     * Reclasifica una unica regla contra el estado actual de la BD --
     * usado como segunda verificacion inmediatamente antes de persistir
     * cada binding (protege contra un cambio concurrente de estructura o
     * de la regla entre el calculo de candidatos y la escritura real).
     */
    public function isStillSafe(Rule $rule, RemTemplateStructure $targetStructure): bool
    {
        $rule->refresh();
        $current = $this->classifyRule(
            $rule,
            $this->buildSectionIndex($targetStructure),
            $this->buildSectionsBySheet($targetStructure),
            $this->buildDuplicateKeySet(),
            $this->buildSerieOrGlobalRuleIds(),
            $targetStructure,
        );

        return $current['clasificacion'] === self::SAFE_1_TO_1 && !$current['hoja_no_utilizada'];
    }

    /**
     * Reclasifica UNA regla puntual contra el estado actual de la BD,
     * exponiendo el resultado COMPLETO (clasificacion, destino, columnas,
     * row_range, motivo) -- a diferencia de isStillSafe() que solo devuelve
     * un booleano. Usado por comandos que necesitan inspeccionar una regla
     * individual (ej. rule:remap-section, 2026-08-27) sin duplicar la logica
     * interna de classifyRule(). Acepta la regla tal como se le pase --
     * incluye instancias clonadas/no persistidas con config modificado en
     * memoria, para simular "que pasaria si" sin escribir nada.
     */
    public function classifySingleRule(Rule $rule, RemTemplateStructure $targetStructure): array
    {
        return $this->classifyRule(
            $rule,
            $this->buildSectionIndex($targetStructure),
            $this->buildSectionsBySheet($targetStructure),
            $this->buildDuplicateKeySet(),
            $this->buildSerieOrGlobalRuleIds(),
            $targetStructure,
        );
    }

    /**
     * @return Collection<int, array> una fila por regla activa, con su
     * clasificacion completa (misma logica para las 6 categorias).
     */
    public function classifyAllActiveRules(RemTemplateStructure $targetStructure): Collection
    {
        $sectionIndex = $this->buildSectionIndex($targetStructure);
        $sectionsBySheet = $this->buildSectionsBySheet($targetStructure);
        $dupKeySet = $this->buildDuplicateKeySet();
        $serieOrGlobalRuleIds = $this->buildSerieOrGlobalRuleIds();

        return Rule::where('status', 'active')->orderBy('id')->get()
            ->map(fn (Rule $rule) => $this->classifyRule($rule, $sectionIndex, $sectionsBySheet, $dupKeySet, $serieOrGlobalRuleIds, $targetStructure));
    }

    private function classifyRule(
        Rule $rule,
        array $sectionIndex,
        array $sectionsBySheet,
        array $dupKeySet,
        Collection $serieOrGlobalRuleIds,
        ?RemTemplateStructure $targetStructure = null,
    ): array {
        $config = $rule->config;
        $sheet = strtoupper($config['sheet'] ?? '');
        $section = strtoupper($config['section'] ?? '');

        $base = [
            'rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'tipo' => $rule->rule_type,
            'hoja' => $sheet,
            'seccion_antigua' => $section,
            'hoja_no_utilizada' => $this->isNoUtilizada($sheet, $targetStructure),
            // Diagnostico de Fase 1 (2026-08-27, ver CLAUDE.md punto 16.10) --
            // NUNCA se usan estos 3 campos para decidir 'clasificacion'.
            // Default null en todas las ramas; solo se calculan para reglas
            // verticales sin total_row con row_range real (ver mas abajo).
            'total_row_candidate' => null,
            'total_row_position' => null,
            'total_row_excluded' => null,
        ];

        if ($serieOrGlobalRuleIds->has($rule->id)) {
            return $base + [
                'clasificacion' => self::ALREADY_STRUCTURE_AGNOSTIC,
                'destino' => 'N/A', 'alcance' => $config['scope'] ?? null,
                'row_range' => $config['row_range'] ?? null, 'columnas' => null,
                'motivo' => 'Ya tiene binding serie/global activo.',
            ];
        }

        // $dupKeySet ahora es un mapa rule_id => true (gate full-signature
        // con verificacion de compatibilidad, ver buildDuplicateKeySet()) --
        // ya NO es un conjunto de claves planas sheet+section+columna+tipo.
        $isDuplicate = isset($dupKeySet[$rule->id]);

        $key = "{$sheet}|{$section}";
        $current = $sectionIndex[$key] ?? null;

        $sourceLetters = $this->deriveSourceLetters($config);
        $targetColumn = strtoupper($config['column'] ?? $config['target_column'] ?? '');
        $columnas = array_values(array_unique(array_filter(array_merge($sourceLetters, [$targetColumn]))));
        $rowRange = $config['row_range'] ?? null;

        if ($current === null) {
            $splits = array_values(array_filter($sectionsBySheet[$sheet] ?? [], fn ($c) => str_starts_with($c, $section) && $c !== $section));
            return $base + [
                'clasificacion' => self::REQUIRES_REMAP,
                'destino' => !empty($splits) ? implode('/', $splits) : null,
                'alcance' => $config['scope'] ?? null, 'row_range' => $rowRange, 'columnas' => $columnas,
                'motivo' => !empty($splits)
                    ? "Seccion dividida -- candidatos: " . implode(',', $splits)
                    : "Seccion '{$section}' no existe en la estructura destino.",
            ];
        }

        $missingColumns = array_values(array_diff($columnas, $current['fields']));
        $rowsOk = true;
        $rowsNote = '';
        // {"from":0,"to":0} es el placeholder que usan las reglas sum_equals
        // horizontales (formula dentro de la misma fila, ej. "Suma(E+G+I+K) =
        // Columna C") -- estas reglas nunca tuvieron un rango vertical real,
        // asi que {0,0} equivale a row_range ausente/null, no a un rango
        // invalido. Auditado contra las 753 reglas activas (2026-08-26): el
        // unico patron fuera de "normal" (from>0) es exactamente {0,0} --
        // no existen {0,N}/{N,0}/negativos/invertidos en los datos reales,
        // asi que esta excepcion no abre la puerta a ningun rango
        // parcialmente invalido.
        $hasRealRowRange = $rowRange !== null
            && !((int) ($rowRange['from'] ?? -1) === 0 && (int) ($rowRange['to'] ?? -1) === 0);
        if ($hasRealRowRange) {
            $from = (int) ($rowRange['from'] ?? -1);
            $to = (int) ($rowRange['to'] ?? -1);
            if ($current['inicio'] !== null && $current['fin'] !== null) {
                if ($from < $current['inicio'] || $to > $current['fin'] || $from <= 0) {
                    $rowsOk = false;
                    $rowsNote = "rango [{$from}:{$to}] fuera de o invalido contra [{$current['inicio']}:{$current['fin']}]";
                }
            }
        }

        $isVerticalPattern = count($sourceLetters) === 1 && $targetColumn !== '' && $sourceLetters[0] === $targetColumn && $rowRange !== null;
        $engineGap = null;
        $totalRowDiscovery = null;
        if ($isVerticalPattern) {
            if (!isset($config['total_row'])) {
                $engineGap = 'invalid_row_range_configuration: falta total_row en config.';

                // Fase 1 (2026-08-27, ver CLAUDE.md punto 16.10): diagnostico
                // de solo lectura, NUNCA cambia $engineGap/$clasificacion.
                // Solo aplica con row_range real (excluye el placeholder
                // {0,0} de las formulas horizontales/A09-I, que no codifican
                // ningun rango vertical real en row_range).
                if ($hasRealRowRange && $targetStructure !== null) {
                    $totalRowDiscovery = $this->discoverTotalRowCandidate(
                        $targetStructure,
                        $sheet,
                        $section,
                        $targetColumn,
                        (int) ($rowRange['from'] ?? -1),
                        (int) ($rowRange['to'] ?? -1),
                    );
                }
            } else {
                $totalRow = (int) $config['total_row'];
                if ($current['fin'] !== null && ($totalRow > $current['fin'] || $totalRow < ($current['inicio'] ?? 0))) {
                    // Fase 3C-1B (CLAUDE.md punto 17.14/17.15): excepcion
                    // AISLADA, exclusiva para TOTAL tecnico trailing
                    // excluido en fin+1 -- la condicion generica de arriba
                    // NO se modifica (sigue evaluando exactamente lo mismo,
                    // para leading y para cualquier otra distancia). Esta
                    // consulta adicional solo se activa cuando esa condicion
                    // YA decidio rechazar, y exige evidencia estricta e
                    // independiente antes de revertir el rechazo -- ver
                    // isLegitimateTrailingTotalBeyondBounds(). CLAUDE.md
                    // punto 17.49: mismo patron para la direccion leading
                    // (isLegitimateLeadingTotalBeyondBounds(), mecanismo
                    // hermano, nunca modifica esta condicion generica).
                    if (!$this->isLegitimateTrailingTotalBeyondBounds($sheet, $section, $targetColumn, $rowRange, $totalRow, $current, $targetStructure)
                        && !$this->isLegitimateLeadingTotalBeyondBounds($sheet, $section, $targetColumn, $rowRange, $totalRow, $current, $targetStructure)
                    ) {
                        $engineGap = "missing_total_row probable: total_row={$totalRow} fuera de [{$current['inicio']}:{$current['fin']}].";
                    }
                }
            }
        }

        if ($totalRowDiscovery !== null) {
            $base = array_merge($base, $totalRowDiscovery);
        }

        $destino = "{$current['sheetName']}/{$current['codigo']}";

        if ($isDuplicate) {
            $clasificacion = self::DUPLICATE;
            $motivo = 'Parte de un grupo de reglas equivalentes (mismo sheet+seccion+columna+tipo).';
        } elseif (!empty($missingColumns)) {
            $clasificacion = self::REQUIRES_REMAP;
            $motivo = 'Columnas [' . implode(',', $missingColumns) . '] ya no existen en la seccion actual.';
        } elseif (!$rowsOk) {
            $clasificacion = self::REQUIRES_REMAP;
            $motivo = "Rango de filas desactualizado: {$rowsNote}.";
        } elseif ($engineGap !== null) {
            $clasificacion = self::BLOCKED_BY_ENGINE_GAP;
            $motivo = $engineGap;
        } else {
            $clasificacion = self::SAFE_1_TO_1;
            $motivo = 'Misma hoja, seccion, columnas y rango de filas vigentes.';
        }

        return $base + [
            'clasificacion' => $clasificacion, 'destino' => $destino,
            'alcance' => $config['scope'] ?? null, 'row_range' => $rowRange, 'columnas' => $columnas,
            'motivo' => $motivo,
        ];
    }

    /**
     * Fase 1 del diseno de auto-discovery de total_row (2026-08-27, ver
     * CLAUDE.md punto 16.10) -- PURAMENTE DIAGNOSTICO, nunca escribe nada y
     * nunca influye en 'clasificacion'. Busca un candidato a total_row para
     * una regla vertical (sum_equals con columna origen == columna destino)
     * que no tiene total_row en su config, probando DOS posiciones:
     *  - trailing: row_to + 1 (la unica que el motor de reglas asumia hasta
     *    ahora, ver mecanismo #8/#12/#11 -- Familia A, 235 reglas).
     *  - leading: row_from - 1 (hallazgo 2026-08-27, Familias B/C/D/E, 41
     *    reglas -- nunca antes considerada por este clasificador).
     *
     * Un candidato solo se acepta si la formula real en cell_data de esa
     * celda (columna destino + fila candidata) referencia EXCLUSIVAMENTE
     * filas dentro de [from:to] y toca ambos extremos -- exige coincidencia
     * exacta con el row_range de ESTA regla, no solo "alguna formula hacia
     * atras/adelante". Esto es deliberado: evita el falso positivo
     * encontrado en la auditoria del 2026-08-27 (A19B/A fila 52, un total
     * real pero de un bloque de negocio completamente distinto y no
     * relacionado con el row_range de las reglas 278-282/277, cuyo total
     * real es la fila 11).
     *
     * Si ambas posiciones producen una coincidencia valida simultaneamente
     * (ambiguedad), o ninguna lo hace, no se resuelve nada -- se devuelven
     * los 3 campos en null, exactamente igual que si nunca se hubiera
     * intentado.
     *
     * @return array{total_row_candidate: int|null, total_row_position: string|null, total_row_excluded: bool|null}
     */
    private function discoverTotalRowCandidate(
        RemTemplateStructure $structure,
        string $sheet,
        string $section,
        string $column,
        int $from,
        int $to,
    ): array {
        $empty = ['total_row_candidate' => null, 'total_row_position' => null, 'total_row_excluded' => null];

        if ($from <= 0 || $to <= 0 || $to < $from || $column === '') {
            return $empty;
        }

        $rawSection = $this->findRawSectionData($structure, $sheet, $section);
        if ($rawSection === null || !$this->cellDataStorage->hasCellData($sheet, $section)) {
            return $empty;
        }

        $candidateRows = [
            'leading' => $from - 1,
            'trailing' => $to + 1,
        ];

        $matches = [];
        foreach ($candidateRows as $position => $row) {
            if ($row <= 0) {
                continue;
            }

            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $row);
            if ($cell === null || ($cell['es_formula'] ?? false) !== true) {
                continue;
            }

            $formula = (string) ($cell['formula'] ?? '');
            if ($formula === '' || !preg_match_all('/[A-Z]{1,3}(\d+)/', $formula, $m)) {
                continue;
            }

            $referencedRows = array_map('intval', $m[1]);
            if (empty($referencedRows)) {
                continue;
            }

            $withinRange = true;
            foreach ($referencedRows as $referencedRow) {
                if ($referencedRow < $from || $referencedRow > $to) {
                    $withinRange = false;
                    break;
                }
            }
            $touchesFrom = in_array($from, $referencedRows, true);
            $touchesTo = in_array($to, $referencedRows, true);

            if ($withinRange && $touchesFrom && $touchesTo) {
                $matches[$position] = $row;
            }
        }

        if (count($matches) !== 1) {
            // Sin candidato, o ambiguo (coincidencia valida en ambas
            // posiciones a la vez) -- ninguno de los dos casos se resuelve
            // automaticamente en Fase 1.
            return $empty;
        }

        $position = array_key_first($matches);
        $row = $matches[$position];

        $excluded = $position === 'leading'
            ? $this->calibrationMatrixService->isEmbeddedLeadingTotalRow($sheet, $section, $row, $rawSection)
            : $this->calibrationMatrixService->isEmbeddedBackwardSubtotalRow($sheet, $section, $row, $rawSection);

        return [
            'total_row_candidate' => $row,
            'total_row_position' => $position,
            'total_row_excluded' => $excluded,
        ];
    }

    /**
     * Fase 3C-1B (CLAUDE.md punto 17.14/17.15). Excepcion AISLADA -- nunca
     * consultada para 'leading' (461 no llega aqui por construccion: el
     * unico punto de llamada es la rama de bounds-check TRAILING de
     * classifyRule(), nunca la de leading). No redefine ni relaja el
     * bounds-check generico -- solo decide, con evidencia estricta e
     * independiente, si UN candidato especifico (exactamente fin+1) debe
     * tratarse como legitimo pese a caer fuera de [inicio:fin]. Reutiliza
     * findRawSectionData()/isEmbeddedBackwardSubtotalRow()/
     * FormulaRangeCoverageAnalyzer ya existentes -- no duplica ningun
     * heuristico de deteccion.
     *
     * Exige, TODAS a la vez:
     *  1. total_row === filaFinDatos + 1 exacto (nunca +2 ni mas).
     *  2. La fila candidata no esta reclamada por NINGUNA otra seccion
     *     declarada de la misma hoja (no pertenece a la seccion siguiente).
     *  3. Mecanismo #12 (isEmbeddedBackwardSubtotalRow) confirma la
     *     exclusion real -- misma fuente que discoverTotalRowCandidate(),
     *     nunca redefinida aqui.
     *  4. Formula real en cell_data, completa y contigua para EXACTAMENTE
     *     [row_range.from : row_range.to] de la propia regla, sin
     *     referencias a otra columna -- verificado de forma independiente,
     *     no solo confiado al punto 3.
     */
    private function isLegitimateTrailingTotalBeyondBounds(
        string $sheet,
        string $section,
        string $column,
        ?array $rowRange,
        int $totalRow,
        array $current,
        ?RemTemplateStructure $targetStructure,
    ): bool {
        if ($targetStructure === null || $rowRange === null) {
            return false;
        }

        if ($current['fin'] === null || $totalRow !== ((int) $current['fin']) + 1) {
            return false;
        }

        $from = (int) ($rowRange['from'] ?? -1);
        $to = (int) ($rowRange['to'] ?? -1);
        if ($from <= 0 || $to <= 0 || $to < $from) {
            return false;
        }

        $est = $this->parseEstructura($targetStructure);
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                $ini = $sec['filaInicioDatos'] ?? null;
                $fin = $sec['filaFinDatos'] ?? null;
                if ($ini !== null && $fin !== null && $totalRow >= (int) $ini && $totalRow <= (int) $fin) {
                    // La fila candidata pertenece a OTRA seccion declarada
                    // (o, si coincidiera con la propia, ya habria pasado el
                    // bounds-check generico) -- no es un caso de esta
                    // excepcion.
                    return false;
                }
            }
        }

        $rawSection = $this->findRawSectionData($targetStructure, $sheet, $section);
        if ($rawSection === null) {
            return false;
        }

        if (!$this->calibrationMatrixService->isEmbeddedBackwardSubtotalRow($sheet, $section, $totalRow, $rawSection)) {
            return false;
        }

        $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $totalRow);
        if ($cell === null || ($cell['es_formula'] ?? false) !== true) {
            return false;
        }

        $formula = (string) ($cell['formula'] ?? '');

        return FormulaRangeCoverageAnalyzer::isCompleteContiguous($formula, $column, $from, $to);
    }

    /**
     * CLAUDE.md punto 17.49. Mirror-guard AISLADO de isLegitimateTrailingTotalBeyondBounds(),
     * para la direccion opuesta (leading, inicio-1) -- misma filosofia
     * exacta: la condicion generica del bounds-check (arriba, sin
     * modificar) sigue rechazando por defecto; esta consulta adicional solo
     * revierte el rechazo con evidencia estricta e independiente. A
     * diferencia del mirror trailing (que delega en el mecanismo #12 ya
     * existente, isEmbeddedBackwardSubtotalRow), este delega en el
     * mecanismo NUEVO isLeadingFormulaBasedTotalBeyondBounds() (17.46/17.49)
     * -- nunca en #6 (isEmbeddedLeadingTotalRow), que exige etiqueta
     * textual y por eso nunca confirmaria candidatos como el de la regla
     * 461 (sin etiqueta).
     */
    private function isLegitimateLeadingTotalBeyondBounds(
        string $sheet,
        string $section,
        string $column,
        ?array $rowRange,
        int $totalRow,
        array $current,
        ?RemTemplateStructure $targetStructure,
    ): bool {
        if ($targetStructure === null || $rowRange === null) {
            return false;
        }

        if (($current['inicio'] ?? null) === null || $totalRow !== ((int) $current['inicio']) - 1) {
            return false;
        }

        $from = (int) ($rowRange['from'] ?? -1);
        $to = (int) ($rowRange['to'] ?? -1);
        if ($from <= 0 || $to <= 0 || $to < $from) {
            return false;
        }

        $est = $this->parseEstructura($targetStructure);
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                $ini = $sec['filaInicioDatos'] ?? null;
                $fin = $sec['filaFinDatos'] ?? null;
                if ($ini !== null && $fin !== null && $totalRow >= (int) $ini && $totalRow <= (int) $fin) {
                    // La fila candidata pertenece a OTRA seccion declarada
                    // -- no es un caso de esta excepcion.
                    return false;
                }
            }
        }

        $rawSection = $this->findRawSectionData($targetStructure, $sheet, $section);
        if ($rawSection === null) {
            return false;
        }

        if (!$this->calibrationMatrixService->isLeadingFormulaBasedTotalBeyondBounds($sheet, $section, $totalRow, $rawSection)) {
            return false;
        }

        $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $totalRow);
        if ($cell === null || ($cell['es_formula'] ?? false) !== true) {
            return false;
        }

        $formula = (string) ($cell['formula'] ?? '');

        return FormulaRangeCoverageAnalyzer::isCompleteContiguous($formula, $column, $from, $to);
    }

    /**
     * Busca la seccion tal como esta declarada en estructura->estructura
     * (formato crudo: 'filaInicioDatos', 'fields' con 'letra', etc.) -- a
     * diferencia de buildSectionIndex(), que transforma esas claves para
     * uso interno de classifyRule(). Los metodos de SectionCalibrationMatrixService
     * (isEmbeddedLeadingTotalRow/isEmbeddedBackwardSubtotalRow) exigen el
     * formato crudo, no el transformado.
     */
    private function findRawSectionData(RemTemplateStructure $structure, string $sheet, string $section): ?array
    {
        $est = $this->parseEstructura($structure);
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== strtoupper($sheet)) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtoupper((string) ($sec['codigo'] ?? '')) === strtoupper($section)) {
                    return $sec;
                }
            }
        }

        return null;
    }

    private function isNoUtilizada(string $sheetUpper, ?RemTemplateStructure $structure): bool
    {
        if ($structure === null) return false;

        $sheetNameOriginal = null;
        $est = $this->parseEstructura($structure);
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper($form['sheetName']) === $sheetUpper) { $sheetNameOriginal = $form['sheetName']; break; }
        }
        if ($sheetNameOriginal === null) return false;

        return $this->usageStatus->getStatusFor((int) $structure->anio, $structure->serie, $sheetNameOriginal)
            === RemSheetUsageStatusService::STATUS_NO_UTILIZADA;
    }

    private function buildSectionIndex(RemTemplateStructure $structure): array
    {
        $est = $this->parseEstructura($structure);
        $index = [];
        foreach ($est['forms'] ?? [] as $form) {
            $sheet = strtoupper($form['sheetName']);
            foreach ($form['sections'] ?? [] as $section) {
                $codigoUpper = strtoupper($section['codigo']);
                $index["{$sheet}|{$codigoUpper}"] = [
                    'fields' => array_map(fn ($f) => strtoupper($f['letra']), $section['fields'] ?? []),
                    'inicio' => $section['filaInicioDatos'] ?? null,
                    'fin' => $section['filaFinDatos'] ?? null,
                    'sheetName' => $form['sheetName'],
                    'codigo' => $section['codigo'],
                ];
            }
        }
        return $index;
    }

    private function buildSectionsBySheet(RemTemplateStructure $structure): array
    {
        $est = $this->parseEstructura($structure);
        $bySheet = [];
        foreach ($est['forms'] ?? [] as $form) {
            $sheet = strtoupper($form['sheetName']);
            foreach ($form['sections'] ?? [] as $section) {
                $bySheet[$sheet][] = strtoupper($section['codigo']);
            }
        }
        return $bySheet;
    }

    /**
     * FASE 4 -- gate de identidad full-signature con verificacion de
     * compatibilidad/ambiguedad (2026-08-28, ver CLAUDE.md punto 17.37).
     * Reemplaza la deteccion plana anterior (marcar TODA regla que
     * comparta sheet+section+columna+tipo con otra) por un analisis par a
     * par dentro de cada grupo de identidad simple: una regla solo deja de
     * considerarse duplicada si, frente a CADA UNO de sus companeros de
     * grupo, la relacion es de "coexistencia legitima" segun
     * isLegitimateCoexistence() -- agregaciones con conjuntos de filas
     * disjuntos, o mismo conjunto de filas pero apuntando a totales
     * distintos con formula funcional distinta. Nunca decide legitimidad
     * a partir de un solo campo (row_range o total_row) aislado.
     *
     * Deliberadamente conservador: si una regla tiene 2+ companeros y solo
     * 1 es problematico (duplicado exacto, subset/supersede, solape
     * ambiguo, o full-signature identica con formula distinta), la regla
     * SIGUE marcada como duplicada -- no se libera selectivamente un
     * miembro de un grupo mixto sin resolver el resto (ver Grupos 7/11 del
     * punto 17.36, ambos permanecen bloqueados en su totalidad).
     *
     * @return array<int, true> rule_id => true para cada regla que debe
     * seguir clasificando DUPLICATE.
     */
    private function buildDuplicateKeySet(): array
    {
        $rows = Rule::where('status', 'active')
            ->get(['id', 'config', 'rule_type'])
            ->map(function (Rule $r) {
                $config = $r->config ?? [];
                return [
                    'id' => $r->id,
                    'group_key' => strtoupper($config['sheet'] ?? '') . '|'
                        . strtoupper($config['section'] ?? '') . '|'
                        . strtoupper((string) ($config['column'] ?? '')) . '|'
                        . $r->rule_type,
                    'total_row' => isset($config['total_row']) ? (int) $config['total_row'] : null,
                    'func_sig' => $this->buildFunctionalSignature($config),
                    'component_set' => $this->buildComponentSet($config),
                ];
            })
            ->groupBy('group_key')
            ->filter(fn ($group) => $group->count() > 1);

        $duplicateRuleIds = [];

        foreach ($rows as $group) {
            // Reglas cuyo conjunto de filas no es determinable (row_range
            // null, o el placeholder {"from":0,"to":0} de formulas
            // horizontales, ver linea ~181 de este archivo) quedan
            // EXCLUIDAS por completo de este mecanismo -- ni se marcan
            // duplicadas por el, ni contaminan a sus companeros. Su
            // clasificacion final sigue dependiendo exclusivamente de
            // missingColumns/rowsOk/engineGap, exactamente igual que antes
            // de este cambio (ver la regla original de B2, `total_row=null`
            // + `row_range={0,0}`, que debe permanecer BLOCKED_BY_ENGINE_GAP,
            // nunca DUPLICATE, mientras coexista con sus futuras reglas
            // hermanas de agregacion real).
            $members = $group->filter(fn ($m) => $m['component_set'] !== null)->values();

            if ($members->count() < 2) {
                continue;
            }

            foreach ($members as $i => $a) {
                if (isset($duplicateRuleIds[$a['id']])) {
                    continue;
                }
                foreach ($members as $j => $b) {
                    if ($i === $j) {
                        continue;
                    }
                    if (!$this->isLegitimateCoexistence($a, $b)) {
                        $duplicateRuleIds[$a['id']] = true;
                        break;
                    }
                }
            }
        }

        return $duplicateRuleIds;
    }

    /**
     * Determina si 2 reglas del MISMO grupo de identidad simple
     * (sheet+section+columna+tipo) pueden coexistir legitimamente como
     * agregaciones distintas, o si deben seguir tratandose como
     * duplicado/ambiguo. Ver CLAUDE.md punto 17.37 para la matriz completa
     * de casos auditados (19 grupos reales, 2026-08-28) que respalda esta
     * logica.
     */
    private function isLegitimateCoexistence(array $a, array $b): bool
    {
        $setA = $a['component_set'];
        $setB = $b['component_set'];

        // Sin evidencia suficiente para determinar el conjunto de filas de
        // alguna de las 2 -- conservador por diseno, nunca se declara
        // coexistencia sin poder comparar filas.
        if ($setA === null || $setB === null) {
            return false;
        }

        $sameComponentSet = $setA === $setB;
        $sameTotalRow = $a['total_row'] === $b['total_row'];

        if ($sameComponentSet && $sameTotalRow) {
            // full-signature identica -- o son la misma regla duplicada
            // exacta (misma formula) o un artefacto degenerado/roto que
            // comparte forma con la formula real (ver 786/130, 787/133,
            // CLAUDE.md 17.36 punto 15/16) -- ninguno de los 2 casos es
            // coexistencia legitima.
            return false;
        }

        if ($sameComponentSet && !$sameTotalRow) {
            // Mismas filas fisicas, pero apuntan a totales distintos (uno
            // puede no tener total_row -- validacion horizontal por fila,
            // el otro un total vertical propio de la columna). Legitimo
            // SOLO si ademas la formula funcional difiere -- evita el
            // caso hipotetico de 2 reglas "Suma(X)=X" apuntando a 2
            // total_row distintos para las MISMAS filas, que seria
            // ambiguo (¿cual total_row es el correcto?), no legitimo.
            return $a['func_sig'] !== $b['func_sig'];
        }

        // Conjuntos de filas distintos -- determinar relacion geometrica
        // por interseccion real (nunca solo por el intervalo row_range).
        $intersection = array_values(array_intersect($setA, $setB));

        if (empty($intersection)) {
            // Disjuntas -- ninguna fila en comun, agregaciones
            // genuinamente independientes por construccion (patron B2 y
            // los grupos 4/5/8/9/10/12/13/14/17/18/19 del punto 17.36).
            return true;
        }

        // Interseccion no vacia con conjuntos distintos => subset/superset
        // o solape parcial genuino -- nunca legitimo sin evidencia
        // adicional (patron 24/553, 29/580, 126/698, 127/714, 558/559,
        // 585/602 del punto 17.36).
        return false;
    }

    /**
     * Firma funcional normalizada (columnas origen ordenadas + columna
     * destino) -- unifica la representacion `rule_logic` (texto) y
     * `source_letters`/`column` (array), reutilizando deriveSourceLetters()
     * ya existente, para que 2 reglas con la misma formula real pero
     * distinta forma de config produzcan la misma firma.
     */
    private function buildFunctionalSignature(array $config): string
    {
        $letters = $this->deriveSourceLetters($config);
        sort($letters);
        $target = strtoupper((string) ($config['column'] ?? $config['target_column'] ?? ''));
        return implode(',', $letters) . '|' . $target;
    }

    /**
     * Conjunto de numeros de fila de los que depende realmente la
     * agregacion de una regla -- `source_rows` explicito si esta presente
     * (Fase 3C-3A/3C-3B), o el intervalo `row_range` completo como
     * conjunto implicito cuando no lo esta (mismo criterio que
     * SumEqualsEvaluator/RuleEngineService ya usan en produccion). El
     * placeholder `{"from":0,"to":0}` (formulas horizontales sin rango
     * vertical real, ver punto 5 de este mismo archivo) se trata como
     * "sin conjunto determinable", null -- nunca como el conjunto {0}.
     */
    private function buildComponentSet(array $config): ?array
    {
        if (!empty($config['source_rows']) && is_array($config['source_rows'])) {
            $set = array_map('intval', $config['source_rows']);
            sort($set);
            return array_values($set);
        }

        $rowRange = $config['row_range'] ?? null;
        if ($rowRange === null) {
            return null;
        }

        $from = (int) ($rowRange['from'] ?? -1);
        $to = (int) ($rowRange['to'] ?? -1);

        if ($from === 0 && $to === 0) {
            return null;
        }
        if ($from <= 0 || $to < $from) {
            return null;
        }

        return range($from, $to);
    }

    private function buildSerieOrGlobalRuleIds(): Collection
    {
        return RuleBinding::whereIn('bindable_type', ['serie', 'global'])
            ->where('active', true)
            ->pluck('rule_id')
            ->unique()
            ->flip();
    }

    private function deriveSourceLetters(array $config): array
    {
        if (!empty($config['source_letters'])) return array_map('strtoupper', $config['source_letters']);
        if (isset($config['rule_logic']) && preg_match('/\(([^)]+)\)/', $config['rule_logic'], $m)) {
            $parts = explode('+', str_replace(' ', '', $m[1]));
            return array_map('strtoupper', array_filter($parts));
        }
        return [];
    }

    private array $structureCache = [];

    private function parseEstructura(RemTemplateStructure $structure): array
    {
        if (isset($this->structureCache[$structure->id])) {
            return $this->structureCache[$structure->id];
        }
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        return $this->structureCache[$structure->id] = ($est ?? []);
    }
}
