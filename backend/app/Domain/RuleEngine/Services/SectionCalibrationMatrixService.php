<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\REM\Services\ColumnRoleResolverService;
use App\Domain\RuleEngine\Models\RemSheetUsageStatus;
use Illuminate\Support\Facades\Cache;

class SectionCalibrationMatrixService
{
    /**
     * Clave de cache del agregado de progreso -- ver
     * buildStructureCalibrationSummary(). Se invalida explicitamente en
     * FunctionalRuleService::saveQuestions() (cada vez que se guarda una
     * respuesta de calibracion) y en StructureApprovalService::activate()
     * (cada vez que cambia cual estructura es la activa). El TTL es solo
     * una red de seguridad para cualquier otro camino de escritura no
     * cubierto por esas dos invalidaciones explicitas.
     */
    public const CALIBRATION_SUMMARY_CACHE_KEY = 'rem:calibration_summary';

    /**
     * TTL alto (1h) a proposito: la frescura real depende de las dos
     * invalidaciones explicitas (saveQuestions/activate), no de este TTL --
     * es solo la red de seguridad. Calculo en frio confirmado en ~60s
     * contra la estructura activa (378 secciones, campaña Serie A completa)
     * -- un TTL corto haria que esa demora se repitiera cada pocos minutos
     * sin necesidad, ya que las dos invalidaciones explicitas ya cubren los
     * unicos dos caminos que realmente cambian el resultado.
     */
    private const CALIBRATION_SUMMARY_CACHE_TTL_SECONDS = 3600;

    private ?array $structureData = null;
    private string $currentSheet = '';

    /**
     * Fallback estatico, usado solo si por algun motivo detectLabelColumns()
     * no llega a ejecutarse antes de que algo consulte las columnas de
     * etiqueta (no deberia pasar en uso normal, ver buildMatrix()).
     */
    private const ROW_LABEL_COLUMNS = ['A', 'B'];

    /**
     * Columnas de etiqueta (concepto/profesional/sub-categoria) de la
     * seccion que se esta procesando actualmente. Se recalcula al inicio de
     * cada buildMatrix() -- ver detectLabelColumns(). Reemplaza el supuesto
     * fijo ROW_LABEL_COLUMNS=['A','B'], que era incorrecto para secciones
     * sin columna de profesional donde B es la unica columna de dato real
     * (ej. A03/B.1).
     */
    private array $currentLabelColumns = self::ROW_LABEL_COLUMNS;

    private const COL_RANGE_DESCRIPTIONS = [
        ['columnas' => ['F','G','H','I','J','K','L','M','N'], 'descripcion' => 'Rango etario 10–54 años'],
        ['columnas' => ['D'], 'descripcion' => 'Menores de 4 años'],
        ['columnas' => ['F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T'], 'descripcion' => 'Rango etario completo 10+ años'],
        ['columnas' => ['L','M','N','O','P'], 'descripcion' => 'Rango etario climaterio 40–64 años'],
    ];

    private const REGLA_FUNCIONAL_LABELS = [
        1 => 'Suma de rango etario estándar = TOTAL',
        2 => 'Conteo directo de RN = TOTAL',
        3 => 'Suma de rango etario completo = TOTAL',
        4 => 'Suma de rango etario climaterio = TOTAL',
    ];

    private const PATRONES_A01_A = [
        [
            'id' => 1,
            'nombre' => 'Estándar (F:N)',
            'descripcion' => 'Controles preconcepcional, prenatal, post parto, post aborto, puérpera',
            'filas' => [11,12,13,14,15,16,17,18,19,20,21,22],
            'formula_template' => 'SUM(F{fila}:N{fila})',
            'columnas_origen' => ['F','G','H','I','J','K','L','M','N'],
            'columna_total' => 'C',
        ],
        [
            'id' => 2,
            'nombre' => 'RN conteo directo (D)',
            'descripcion' => 'Recién nacido — única columna D (menores de 4 años)',
            'filas' => [23,24,25,26],
            'formula_template' => 'D{fila}',
            'columnas_origen' => ['D'],
            'columna_total' => 'C',
        ],
        [
            'id' => 3,
            'nombre' => 'Ginecológico / Reg. Fecund. (F:T)',
            'descripcion' => 'Ginecológico, regulación de fecundidad — rango etario extendido',
            'filas' => [27,28,31,32],
            'formula_template' => 'SUM(F{fila}:T{fila})',
            'columnas_origen' => ['F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T'],
            'columna_total' => 'C',
        ],
        [
            'id' => 4,
            'nombre' => 'Climaterio (L:P)',
            'descripcion' => 'Climaterio — rango etario 40-64 años',
            'filas' => [29,30],
            'formula_template' => 'SUM(L{fila}:P{fila})',
            'columnas_origen' => ['L','M','N','O','P'],
            'columna_total' => 'C',
        ],
    ];

    private PatternReconciliationService $patternReconciliation;

    private RemSheetUsageStatusService $sheetUsageStatus;

    public function __construct(
        private CertificationService $certificationService,
        private FunctionalRuleService $functionalRuleService,
        private CellDataStorageService $cellDataStorage,
        private ?ColumnRoleResolverService $columnRoleResolver = null,
        ?PatternReconciliationService $patternReconciliation = null,
        ?RemSheetUsageStatusService $sheetUsageStatus = null,
    ) {
        $this->columnRoleResolver = $columnRoleResolver ?? new ColumnRoleResolverService();
        $this->patternReconciliation = $patternReconciliation ?? new PatternReconciliationService();
        $this->sheetUsageStatus = $sheetUsageStatus ?? new RemSheetUsageStatusService();
    }

    /**
     * Libera del cache de la instancia de CellDataStorageService inyectada
     * aqui (independiente de cualquier otra instancia que el llamador tenga
     * la suya propia) el cell_data ya decodificado de una seccion puntual.
     * Pensado para llamadores como ValidateRemUploadJob::evaluateFunctionalRules(),
     * que invoca getPatternsForValidation() una vez por cada seccion del
     * upload y necesita poder liberar esa seccion al terminar de evaluarla
     * -- mismo patron y misma causa raiz que el OOM ya corregido en
     * RemParserService::buildSectionMaps() y en CellDataStorageService.
     */
    public function forgetSectionCache(string $sheet, string $section): void
    {
        $this->cellDataStorage->forgetSection($sheet, $section);
    }

    /**
     * Fuerza el "estructura activa" que parseEstructura() devuelve, sin
     * tocar BD -- pensado exclusivamente para simulaciones read-only en
     * memoria (ej. reconciliacion de fingerprints contra una estructura
     * propuesta que nunca se persiste). getActiveStructure() todavia hace
     * una lectura real (SELECT, sin escritura) solo para obtener un objeto
     * RemTemplateStructure no nulo que buildMatrix() necesita para no
     * abortar con 'not_found' -- su contenido se ignora por completo una
     * vez que $structureData ya esta poblado aqui.
     */
    public function seedStructureData(array $estructura): void
    {
        $this->structureData = $estructura;
    }

    public function buildMatrix(string $sheet, string $section): array
    {
        $this->currentSheet = $sheet;
        $structure = $this->getActiveStructure();
        if (!$structure) {
            return $this->emptyMatrix($sheet, $section, 'not_found', [
                'No se encontro una estructura activa para construir la matriz de calibracion.',
            ]);
        }

        $est = $this->parseEstructura($structure);
        if (!$est) {
            return $this->emptyMatrix($sheet, $section, 'invalid_structure', [
                'La estructura activa existe, pero no tiene un formato JSON compatible.',
            ]);
        }

        $sectionData = $this->findSection($est, $sheet, $section);
        if (!$sectionData) {
            return $this->emptyMatrix($sheet, $section, 'not_found', [
                "No se encontro la hoja {$sheet} o la seccion {$section} en la estructura activa.",
            ]);
        }

        $rules = $this->certificationService->getSectionRules($sheet, $section);
        $certStatus = $this->certificationService->loadCertificationStatus();
        $funcionalByKey = $this->functionalRuleService->getFunctionalRulesBySheetSection($sheet, $section);
        $funcionalByRow = $this->functionalRuleService->getFunctionalRulesByRow($sheet, $section);
        $evidenceRules = $this->extractEvidenceRules($est, $sheet, $section);

        $filaInicio = $sectionData['filaInicioDatos'] ?? 10;
        $filaFin = $sectionData['filaFinDatos'] ?? 32;
        $filaHeader = $sectionData['filaHeader'] ?? 9;

        $columnIndex = $this->buildColumnIndex($sectionData);
        $aggregatedRules = $this->buildAggregatedRules($rules, $evidenceRules, $sectionData, $certStatus);

        $cellDataForRows = [];
        if ($this->cellDataStorage->hasCellData($sheet, $section)) {
            $allCellData = $this->cellDataStorage->getAllCellData($sheet, $section);
            foreach ($allCellData as $coord => $cd) {
                preg_match('/^([A-Z]+)(\d+)$/', $coord, $m);
                if ($m) {
                    $cellDataForRows[(int)$m[2]][$m[1]] = $cd;
                }
            }
        }

        if ($filaFin < $filaInicio && !empty($cellDataForRows)) {
            $filaFin = $this->inferSectionEndRowFromCellData($filaInicio, $cellDataForRows);
        }

        $this->currentLabelColumns = $this->detectLabelColumns($sectionData, $cellDataForRows, $filaInicio, $filaFin, $filaHeader);

        // Precalculado fuera del loop: el resultado depende unicamente de $sectionData
        // y $cellDataForRows (la seccion completa), no de la fila actual. Recalcularlo
        // en cada iteracion es trabajo redundante O(filas) que ademas compone con un
        // escaneo interno propio O(filas) -- para secciones grandes (ej. A05/U, 1404
        // celdas / 35 filas) esto dominaba el tiempo total de construccion de la
        // matriz (~350-450ms/fila, ~12s solo en este calculo repetido).
        $formulaTotalColumns = !empty($cellDataForRows)
            ? $this->getFormulaTotalColumnsFromCellData($sectionData, $cellDataForRows)
            : [];

        // Mismo motivo que $formulaTotalColumns arriba: buildFunctionalRulesForMatrixRow()
        // llamaba a getFunctionalColumns($sectionData, $cellDataForRows) -- invariante por
        // fila -- de nuevo en cada iteracion de este loop.
        $functionalColumns = $this->getFunctionalColumns($sectionData, $cellDataForRows);

        $rows = [];
        for ($row = $filaInicio; $row <= $filaFin; $row++) {
            $rowType = $this->classifyRow($sheet, $section, $row, $filaHeader, $sectionData);
            $rowCells = $cellDataForRows[$row] ?? [];

            if (!empty($rowCells) && $this->isVerticalConsolidationRow($row, $rowCells)) {
                continue;
            }

            if (!empty($rowCells) && $this->isNonCalibrableCellHeaderRow($rowCells, $formulaTotalColumns)) {
                $rowType = 'header';
            }
            if (!empty($rowCells) && $this->isSpecialSummaryRow($rowCells)) {
                $rowType = 'special';
            }

            $matrixFunctionalRules = $this->buildFunctionalRulesForMatrixRow($row, $rowCells, $sectionData, $cellDataForRows, $functionalColumns);
            $directRule = $this->findDirectRule($rules, $row);
            $evidenceDetail = $this->getEvidenceDetail($evidenceRules, $row, $sectionData, $columnIndex);
            if ($rowType !== 'data') {
                $evidenceDetail = [
                    'destino_exacto' => null,
                    'origen_efectivo' => [],
                    'origen_columnas' => [],
                    'formula_efectiva' => null,
                    'formula_candidata' => null,
                    'columna_destino' => null,
                    'columnas_destino' => [],
                    'tipo_evidencia' => 'no_evidence',
                ];
            } elseif (!empty($matrixFunctionalRules)) {
                $primaryFunctionalRule = $matrixFunctionalRules[0];
                $evidenceDetail = [
                    'destino_exacto' => $primaryFunctionalRule['destination'],
                    'origen_efectivo' => $primaryFunctionalRule['origin_coordinates'],
                    'origen_columnas' => $primaryFunctionalRule['origin_columns'],
                    'formula_efectiva' => $primaryFunctionalRule['formula_exacta'],
                    'formula_candidata' => $primaryFunctionalRule['label'],
                    'columna_destino' => $primaryFunctionalRule['total_column'],
                    'columnas_destino' => array_map(fn($rule) => $rule['total_column'], $matrixFunctionalRules),
                    'tipo_evidencia' => 'cell_level_xlsm',
                ];
            }
            $aggregatedMatch = $this->findAggregatedMatch($aggregatedRules, $row, $rowType, $evidenceDetail, $sectionData);
            if ($rowType !== 'data') {
                $directRule = null;
                $aggregatedMatch = [
                    'rule_key' => null,
                    'rule_type' => null,
                    'es_excepcion' => false,
                    'excepcion_razon' => null,
                ];
            }
            $funcRow = $funcionalByRow["{$sheet}_{$section}_{$row}"] ?? null;

            $concepto = $cellDataForRows[$row]['A']['valor_bruto'] ?? null;
            $profesional = $cellDataForRows[$row]['B']['valor_bruto'] ?? null;

            $rows[] = [
                'row' => $row,
                'row_type' => $rowType,
                'concepto' => $concepto,
                'profesional' => $profesional,
                'rule_key' => $directRule['rule_key'] ?? $aggregatedMatch['rule_key'] ?? null,
                'rule_type' => $directRule['rule_type'] ?? $aggregatedMatch['rule_type'] ?? null,
                'aggregated_rule_key' => $aggregatedMatch['rule_key'] ?? null,
                'destino_exacto' => $evidenceDetail['destino_exacto'],
                'origen_efectivo' => $evidenceDetail['origen_efectivo'],
                'origen_columnas' => $evidenceDetail['origen_columnas'],
                'formula_efectiva' => $evidenceDetail['formula_efectiva'],
                'formula_candidata' => $evidenceDetail['formula_candidata'],
                'tipo_evidencia' => $evidenceDetail['tipo_evidencia'],
                'cobertura' => $this->determineCoverage(
                    rowType: $rowType,
                    directRule: $directRule,
                    aggregatedMatch: $aggregatedMatch,
                    evidenceDetail: $evidenceDetail,
                    sectionData: $sectionData,
                ),
                'estado_tecnico' => $this->determineTecnicalStatus($rowType, $directRule, $certStatus),
                'columnas_habilitadas' => $this->getEnabledColumns($sectionData),
                'columnas_sumadas' => $evidenceDetail['origen_columnas'],
                'columnas_fuera_suma' => $this->getColumnsOutsideSumRange($sectionData, $evidenceDetail['origen_columnas']),
                'columna_total' => $evidenceDetail['columna_destino'] ?? 'C',
                'destino_funcional' => $evidenceDetail['columna_destino']
                    ? 'TOTAL (' . ($evidenceDetail['columna_destino'] ?? 'C') . $row . ')'
                    : null,
                'descripcion_funcional_origen' => !empty($evidenceDetail['origen_columnas'] ?? [])
                    ? $this->getFunctionalOriginDescription($evidenceDetail['origen_columnas'] ?? [])
                    : null,
                'regla_funcional_label' => $rowType === 'data'
                    ? ($matrixFunctionalRules[0]['label'] ?? 'Suma de columnas = TOTAL')
                    : null,
                'functional_rules' => $matrixFunctionalRules,
                'es_excepcion' => $aggregatedMatch['es_excepcion'] ?? false,
                'excepcion_razon' => $aggregatedMatch['excepcion_razon'] ?? null,
                'funcional_por_fila' => $funcRow,
                'funcional_por_regla' => $directRule ? ($funcionalByKey[$directRule['rule_key']] ?? null) : null,
            ];
        }

        $summary = $this->buildSummary($rows);
        $questions = $this->generateQuestions($rows, $sectionData);

        $headerLabels = [];
        $headerRowNum = $filaHeader + 1;
        foreach ($sectionData['fields'] ?? [] as $f) {
            $letra = $f['letra'] ?? '';
            $cell = $cellDataForRows[$headerRowNum][$letra] ?? null;
            $headerLabels[$letra] = $cell['valor_bruto'] ?? $f['label'] ?? '';
        }

        return [
            'section' => [
                'codigo' => $sectionData['codigo'] ?? $section,
                'titulo' => $sectionData['titulo'] ?? '',
                'status' => 'ok',
                'fila_header' => $filaHeader,
                'fila_inicio' => $filaInicio,
                'fila_fin' => $filaFin,
                'total_filas_fisicas' => max(0, $filaFin - $filaHeader + 1),
                'total_campos' => count($sectionData['fields'] ?? []),
            ],
            'rows' => $rows,
            'summary' => $summary,
            'columnas' => $columnIndex,
            'aggregated_rules' => $aggregatedRules,
            'questions' => $questions,
            'header_labels' => $headerLabels,
            'warnings' => [],
        ];
    }

    /**
     * Version liviana de buildPatternMatrix(), exclusiva para validacion masiva
     * (ValidateRemUploadJob::evaluateFunctionalRules()). Ese flujo solo consume
     * $patternMatrix['patterns'], y de cada patron unicamente lee
     * id/key/pattern_key y rows[]['fila'|'row'] (ver
     * FunctionalRuleService::getPatternFunctionalRulesForRows()) -- nunca
     * column_groups, ni el detalle enriquecido por fila (conceptos, editables,
     * bloqueadas, especiales) que arma buildPatternMatrix() para la interfaz
     * de calibracion.
     *
     * Reutiliza buildMatrix() y buildDynamicPatternDefinitions() TAL CUAL,
     * sin duplicar su logica, para garantizar exactamente la misma
     * clasificacion de filas y agrupacion de patrones que produce
     * buildPatternMatrix() (misma cantidad de reglas, mismas filas por
     * patron). Lo unico que se omite es buildColumnGroups() y el
     * enriquecimiento por fila -- ambos ausentes en el resultado porque
     * evaluateFunctionalRules() nunca los lee.
     */
    public function getPatternsForValidation(string $sheet, string $section): array
    {
        $matrix = $this->buildMatrix($sheet, $section);
        if (($matrix['section']['status'] ?? 'ok') !== 'ok') {
            return [];
        }

        $isLegacyA01A = strtoupper($sheet) === 'A01' && strtoupper($section) === 'A';
        if ($isLegacyA01A) {
            return array_map(
                fn(array $patron) => $patron + ['rows' => array_map(fn($fila) => ['fila' => $fila], $patron['filas'])],
                self::PATRONES_A01_A
            );
        }

        $structure = $this->getActiveStructure();
        $est = $structure ? $this->parseEstructura($structure) : null;
        $sectionData = $est ? $this->findSection($est, $sheet, $section) : null;

        $allRows = $matrix['rows'] ?? [];

        $cellDataRows = [];
        if ($this->cellDataStorage->hasCellData($sheet, $section)) {
            $cellData = $this->cellDataStorage->getAllCellData($sheet, $section);
            foreach ($cellData as $coord => $cd) {
                preg_match('/^([A-Z]+)(\d+)$/', $coord, $m);
                if ($m) {
                    $col = $m[1]; $row = (int)$m[2];
                    $cellDataRows[$row][$col] = $cd;
                }
            }
        }

        $patterns = $this->buildDynamicPatternDefinitions($allRows, $sectionData ?? [], $cellDataRows);

        return array_map(
            fn(array $patron) => $patron + ['rows' => array_map(fn($fila) => ['fila' => $fila], $patron['filas'])],
            $patterns
        );
    }

    /**
     * Huella estable de un patron, basada en su conjunto ordenado de filas
     * -- no en pattern_id (secuencial, depende del orden de deteccion y
     * puede reasignarse cuando cambia el cell_data, ver hallazgo de
     * incompatibilidad A09/F-G del 2026-08-06). Dos patrones con exactamente
     * las mismas filas producen siempre la misma huella, sin importar en
     * que orden fueron detectados ni que id numerico les toco.
     *
     * Canonicalizacion: cast a int, se descartan valores <= 0 (filas
     * invalidas/vacias nunca aportan a la huella), se deduplican (una fila
     * repetida no deberia ocurrir dado como agrupa buildDynamicPatternDefinitions(),
     * pero se tolera sin duplicar su peso en el hash) y se ordenan
     * ascendente. Un patron sin ninguna fila valida tras filtrar recibe la
     * huella reservada 'fp_empty', que nunca debe tratarse como coincidencia
     * entre dos patrones distintos (dos patrones vacios no son "el mismo
     * patron").
     *
     * SHA-256 (truncado a 16 hex = 64 bits) en vez del hash de 32 bits que
     * ya usa el frontend para technical_signature -- a la escala de toda la
     * Serie A (miles de combinaciones posibles de filas por patron), un
     * hash de 32 bits tiene probabilidad de colision no despreciable.
     */
    public function computeRowFingerprint(array $filas): string
    {
        $normalizadas = array_values(array_unique(array_filter(
            array_map(fn ($f) => (int) $f, $filas),
            fn (int $f) => $f > 0
        )));

        if (empty($normalizadas)) {
            return 'fp_empty';
        }

        sort($normalizadas, SORT_NUMERIC);

        $canonico = implode(',', $normalizadas);

        return 'rowset_' . substr(hash('sha256', $canonico), 0, 16);
    }

    /**
     * Firma canonica v2 de un patron (deuda tecnica #1 de la campana de
     * calibracion Serie A, cerrada 2026-08-12): computeRowFingerprint()
     * (v1, "rowset_...") solo depende del conjunto de filas -- una seccion
     * cuyas columnas, formulas o editabilidad cambiaron bajo el mismo
     * conjunto de filas (o el mismo pattern_id reasignado a un patron
     * distinto) seguia reportandose "revisada" sin evidencia real. Esta es
     * la UNICA funcion que calcula la huella canonica -- todo consumidor
     * (reconciliacion en vivo, backfill/migracion, simulacion de
     * migracion) debe llamarla en vez de reimplementar el criterio.
     *
     * Insumos, todos ya calculados por buildDynamicPatternDefinitions()
     * para agrupar filas en patrones (no se recalculan aqui, se reciben
     * normalizados desde el patron ya construido):
     *   - filas: conjunto de filas del patron (orden/duplicados no afectan
     *     la huella).
     *   - totalColumns/originColumns: columnas funcionales del patron
     *     (orden no afecta la huella).
     *   - formulaTemplates: formula normalizada por columna total (fila ya
     *     reemplazada por {fila} via normalizeFormulaTemplate()).
     *   - editabilitySignature: string ya producido por
     *     buildEditabilitySignature() ("A:B|B:E|...") -- bloqueada/editable
     *     por columna funcional, misma para todas las filas de un patron
     *     por construccion del agrupamiento.
     *   - mode: 'formula' vs 'direct_input' -- distincion funcional real
     *     (una fila sin formula de TOTAL se valida distinto que una con
     *     formula), se incluye por eso.
     *
     * Deliberadamente NO incluye: structure_id/version (bakearlo
     * invalidaria toda seccion cada vez que cambia CUALQUIER hoja de la
     * estructura, no solo la afectada -- sobre-invalidacion), warnings,
     * conceptos/nombres de profesionales (texto visual, no cambia la regla
     * funcional), ni ningun otro campo de presentacion.
     *
     * Serializacion deterministica antes del hash: filas ordenadas
     * numericamente y deduplicadas: columnas ordenadas alfabeticamente
     * (mayusculas) y deduplicadas; formula_templates ordenadas por clave de
     * columna (ksort) con formula normalizada a mayusculas. Un patron sin
     * ninguna fila valida recibe la huella reservada 'fpv2_empty' (nunca
     * coincide entre si, igual que 'fp_empty' en v1).
     */
    public function computeCanonicalPatternFingerprint(
        array $filas,
        array $totalColumns,
        array $originColumns,
        array $formulaTemplates,
        string $editabilitySignature,
        string $mode,
    ): string {
        $normalizedRows = array_values(array_unique(array_filter(
            array_map(fn ($f) => (int) $f, $filas),
            fn (int $f) => $f > 0
        )));

        if (empty($normalizedRows)) {
            return 'fpv2_empty';
        }

        sort($normalizedRows, SORT_NUMERIC);

        $normalizeColumnList = function (array $columns): array {
            $normalized = array_values(array_unique(array_map(
                fn ($c) => strtoupper(trim((string) $c)),
                array_filter($columns, fn ($c) => trim((string) $c) !== '')
            )));
            sort($normalized, SORT_STRING);

            return $normalized;
        };

        $normalizedTotals = $normalizeColumnList($totalColumns);
        $normalizedOrigins = $normalizeColumnList($originColumns);

        $normalizedFormulas = [];
        foreach ($formulaTemplates as $column => $template) {
            $normalizedFormulas[strtoupper((string) $column)] = strtoupper((string) $template);
        }
        ksort($normalizedFormulas, SORT_STRING);

        $canonico = implode(',', $normalizedRows)
            . '|' . implode(',', $normalizedTotals)
            . '|' . implode(',', $normalizedOrigins)
            . '|' . json_encode($normalizedFormulas)
            . '|' . $editabilitySignature
            . '|' . strtolower(trim($mode));

        return 'fpv2_' . substr(hash('sha256', $canonico), 0, 16);
    }

    public function buildPatternMatrix(string $sheet, string $section): array
    {
        $matrix = $this->buildMatrix($sheet, $section);
        if (($matrix['section']['status'] ?? 'ok') !== 'ok') {
            return [
                'section' => $matrix['section'],
                'rows' => $matrix['rows'] ?? [],
                'patterns' => [],
                'columnas_especiales' => [],
                'column_groups' => [],
                'summary' => $matrix['summary'],
                'questions' => $matrix['questions'] ?? [],
                'all_rows' => $matrix['rows'] ?? [],
                'header_labels' => $matrix['header_labels'] ?? [],
                'aggregated_rules' => $matrix['aggregated_rules'] ?? [],
                'warnings' => $matrix['warnings'] ?? [],
                'reconciliation' => [
                    'effective_section_reviewed' => false,
                    'historical_section_reviewed' => false,
                ],
                'calibration_applicability' => [
                    'status' => 'requires_calibration',
                    'reason' => 'La estructura no está disponible o es inconsistente para esta sección.',
                    'criteria' => [
                        'structure_available_and_consistent' => false,
                        'cell_data_available' => false,
                        'no_pending_warnings' => false,
                        'no_editable_cells' => false,
                        'no_functional_formulas' => false,
                        'no_calibratable_patterns' => false,
                    ],
                ],
            ];
        }

        $structure = $this->getActiveStructure();
        $est = $structure ? $this->parseEstructura($structure) : null;
        $sectionData = $est ? $this->findSection($est, $sheet, $section) : null;

        $allRows = $matrix['rows'] ?? [];
        $usedRows = [];

        $cellDataRows = [];
        if ($this->cellDataStorage->hasCellData($sheet, $section)) {
            $cellData = $this->cellDataStorage->getAllCellData($sheet, $section);
            foreach ($cellData as $coord => $cd) {
                preg_match('/^([A-Z]+)(\d+)$/', $coord, $m);
                if ($m) {
                    $col = $m[1]; $row = (int)$m[2];
                    $cellDataRows[$row][$col] = $cd;
                }
            }
        }

        $isLegacyA01A = strtoupper($sheet) === 'A01' && strtoupper($section) === 'A';
        $hasCellData = !empty($cellDataRows);
        $columnGroups = $this->buildColumnGroups($sheet, $section, $sectionData ?? [], $cellDataRows, $allRows);
        $columnsEspeciales = $isLegacyA01A
            ? array_map(fn($column) => $column['letter'], $columnGroups[0]['columns'] ?? [])
            : $this->legacySpecialColumnsFromGroups($columnGroups);
        $patterns = $isLegacyA01A
            ? self::PATRONES_A01_A
            : $this->buildDynamicPatternDefinitions($allRows, $sectionData ?? [], $cellDataRows);

        $warnings = $matrix['warnings'] ?? [];
        if (!$isLegacyA01A && !$hasCellData) {
            $warnings[] = 'No existe evidencia de celdas escaneadas para esta sección. Los patrones mostrados son preliminares y no pueden certificarse todavía.';
        }

        $enriched = [];
        foreach ($patterns as $pi => $patron) {
            $patronRows = [];
            $conceptos = [];
            $profesionales = [];

            foreach ($patron['filas'] as $rowNum) {
                $rowData = null;
                foreach ($allRows as $r) {
                    if ((int)$r['row'] === $rowNum) {
                        $rowData = $r;
                        break;
                    }
                }
                if (!$rowData) continue;

                $usedRows[] = $rowNum;

                $concepto = $cellDataRows[$rowNum]['A']['valor_bruto'] ?? '';
                $profesional = $cellDataRows[$rowNum]['B']['valor_bruto'] ?? '';

                if ($concepto) $conceptos[$concepto] = true;
                if ($profesional) $profesionales[$profesional] = true;

                $cellInfo = $cellDataRows[$rowNum] ?? [];
                $editables = [];
                $bloqueadas = [];
                $especiales = [];
                $patternTotalColumns = $patron['total_columns'] ?? [$patron['columna_total']];

                foreach ($sectionData['fields'] ?? [] as $f) {
                    $letra = strtoupper($f['letra'] ?? '');
                    $cd = $cellInfo[$letra] ?? [];
                    $isBlocked = $cd['esta_bloqueada'] ?? false;
                    $color = $cd['color_fondo']['nombre_inferido'] ?? 'blanco';
                    $rgb = $cd['color_fondo']['rgb'] ?? 'FFFFFFFF';

                    $info = [
                        'letra' => $letra,
                        'color' => $color,
                        'rgb' => $rgb,
                        'bloqueada' => $isBlocked,
                        'editable' => !$isBlocked,
                        'contenido' => $cd['valor_bruto'] ?? '',
                        'es_formula' => $cd['es_formula'] ?? false,
                        'formula' => $cd['formula'] ?? '',
                        'tipo_celda' => $cd['tipo_celda'] ?? '',
                    ];

                    if (in_array($letra, $columnsEspeciales)) {
                        $especiales[] = $info;
                    } elseif (in_array($letra, $patternTotalColumns, true)) {
                        // total column
                    } elseif (!in_array($letra, $this->currentLabelColumns)) {
                        if ($isBlocked) {
                            $bloqueadas[] = $info;
                        } else {
                            $editables[] = $info;
                        }
                    }
                }

                $evidenceDetail = $rowData['tipo_evidencia'] ?? 'no_evidence';
                $cobertura = $rowData['cobertura'] ?? 'sin_regla';

                $coberturaPatron = match (true) {
                    $cobertura === 'direct_rule' || str_contains($evidenceDetail, 'cell_level') => 'evidencia directa',
                    $cobertura === 'aggregated_rule_candidate' || str_contains($evidenceDetail, 'pattern') => 'cubierta por patrón real',
                    $cobertura === 'exception' || $cobertura === 'partial_exception' => 'excepción',
                    $cobertura === 'not_applicable' => 'no aplica',
                    default => 'pendiente de validación funcional',
                };

                $colsOrigenKey = implode(',', $patron['columnas_origen']);
                $descOrigen = $this->getFunctionalOriginDescription($patron['columnas_origen']);
                $descRegla = self::REGLA_FUNCIONAL_LABELS[$patron['id']] ?? 'Suma de columnas = TOTAL';
                $functionalRules = $this->buildFunctionalRulesForPatternRow($patron, $rowNum, $cellDataRows);
                $primaryRule = $functionalRules[0] ?? null;

                $patronRows[] = [
                    'fila' => $rowNum,
                    'concepto' => $concepto ?: ($rowData['concepto'] ?? ''),
                    'profesional' => $profesional ?: ($rowData['profesional'] ?? ''),
                    'formula_exacta' => $primaryRule['formula_exacta'] ?? $rowData['formula_efectiva'] ?? '',
                    'tipo_evidencia' => $evidenceDetail,
                    'cobertura' => $coberturaPatron,
                    'estado_tecnico' => $rowData['estado_tecnico'] ?? 'Pendiente',
                    'destino_funcional' => $primaryRule['destino_funcional'] ?? "TOTAL ({$patron['columna_total']}{$rowNum})",
                    'descripcion_funcional_origen' => $primaryRule['descripcion_funcional_origen'] ?? $descOrigen,
                    'regla_funcional_label' => $primaryRule['label'] ?? $descRegla,
                    'functional_rules' => $functionalRules,
                    'pattern_label' => $patron['nombre'],
                    'funcional_por_fila' => $rowData['funcional_por_fila'] ?? null,
                    'funcional_por_regla' => $rowData['funcional_por_regla'] ?? null,
                    'editables' => $editables,
                    'bloqueadas' => $bloqueadas,
                    'especiales' => $especiales,
                    'total_info' => $cellDataRows[$rowNum][$patron['columna_total']] ?? [],
                    'total_infos' => array_intersect_key($cellDataRows[$rowNum] ?? [], array_flip($patternTotalColumns)),
                    'objetivo' => ($rowNum % 2 === 0) ? 'revisar' : 'revisar',
                ];
            }

            $enrichedOriginColumns = $patron['origin_columns'] ?? $patron['columnas_origen'];
            $enrichedTotalColumns = $patron['total_columns'] ?? [$patron['columna_total']];
            $enrichedFormulaTemplates = $patron['formula_templates'] ?? [$patron['columna_total'] => $patron['formula_template']];
            $enrichedMode = $patron['mode'] ?? 'formula';
            $enrichedEditabilitySignature = $patron['editability_signature'] ?? '';

            $enriched[] = [
                'id' => $patron['id'],
                'row_fingerprint' => $this->computeRowFingerprint($patron['filas']),
                // Firma canonica v2 (deuda tecnica #1) -- unica funcion que la
                // calcula, ver computeCanonicalPatternFingerprint(). No se
                // persiste todavia por si sola: PatternReconciliationService
                // decide, segun fingerprint_version de la respuesta guardada,
                // si corresponde compararla contra esta o contra row_fingerprint.
                'canonical_fingerprint' => $this->computeCanonicalPatternFingerprint(
                    $patron['filas'],
                    $enrichedTotalColumns,
                    $enrichedOriginColumns,
                    $enrichedFormulaTemplates,
                    $enrichedEditabilitySignature,
                    $enrichedMode,
                ),
                'nombre' => $patron['nombre'],
                'descripcion' => $patron['descripcion'],
                'regla_funcional_label' => self::REGLA_FUNCIONAL_LABELS[$patron['id']] ?? 'Suma de columnas = TOTAL',
                'filas' => $patron['filas'],
                'formula_template' => $patron['formula_template'],
                'columnas_origen' => $patron['columnas_origen'],
                'columna_total' => $patron['columna_total'],
                'origin_columns' => $enrichedOriginColumns,
                'total_columns' => $enrichedTotalColumns,
                'formula_templates' => $enrichedFormulaTemplates,
                'source' => $patron['source'] ?? 'legacy_a01_a',
                'mode' => $enrichedMode,
                'editability_signature' => $enrichedEditabilitySignature,
                'cantidad_filas' => count($patronRows),
                'conceptos' => array_keys($conceptos),
                'profesionales' => array_keys($profesionales),
                'rows' => $patronRows,
            ];
        }

        $unusedRows = array_filter($allRows, fn($r) => !in_array((int)$r['row'], $usedRows) && $r['row_type'] === 'data');

        $questions = $this->functionalRuleService->getQuestions($sheet, $section);

        $applicability = $this->sectionHasNoCalibratableContent(
            $sectionData ?? [],
            $cellDataRows,
            $hasCellData,
            $patterns,
            $warnings,
        );
        $noCalibratableClosure = $this->findNoCalibratableClosureQuestion($questions);

        $enriched = $this->applyExceptionClassification($enriched);
        [$enriched, $reconciliation] = $this->applyPatternReconciliation($enriched, $questions);
        $effectiveSectionReviewed = ($applicability['status'] === 'not_calibratable' && $noCalibratableClosure !== null)
            ? true
            : $this->patternReconciliation->computeEffectiveSectionReviewed($reconciliation);
        $historicalSectionReviewed = $this->hasHistoricalSectionReviewed($questions);

        return [
            'section' => $matrix['section'],
            'rows' => $allRows,
            'patterns' => $enriched,
            'columnas_especiales' => $columnsEspeciales,
            'column_groups' => $columnGroups,
            'summary' => $matrix['summary'],
            'questions' => $questions,
            'all_rows' => $allRows,
            'header_labels' => $matrix['header_labels'] ?? [],
            'aggregated_rules' => $matrix['aggregated_rules'] ?? [],
            'warnings' => $warnings,
            'reconciliation' => [
                'effective_section_reviewed' => $effectiveSectionReviewed,
                'historical_section_reviewed' => $historicalSectionReviewed,
            ],
            'calibration_applicability' => $applicability,
        ];
    }

    /**
     * Agregado de progreso de calibracion de TODA la estructura activa,
     * agrupado por hoja -- hallazgo de revision UX (2026-08-11): el
     * Dashboard/Plantilla/Serie no mostraban ningun progreso real ("sin
     * datos suficientes" fijo en las 3 pantallas), obligando al funcionario
     * a entrar seccion por seccion para saber que falta.
     *
     * Reutiliza buildPatternMatrix() por seccion (misma fuente de verdad ya
     * validada durante toda la campaña para 'effective_section_reviewed' y
     * 'calibration_applicability') en vez de duplicar la logica de
     * reconciliacion -- evita divergencia entre lo que muestra la seccion
     * individual y lo que muestra este agregado. El costo (una lectura de
     * cell-data + reconciliacion por seccion) ocurre en un solo request
     * HTTP hacia el backend, no como N requests desde el navegador.
     *
     * Criterio de "completada" (igual al usado en cada seccion individual):
     * `effective_section_reviewed === true`, sin importar si el cierre fue
     * `response='revisada'` (calibrada) o `response='no_calibrable'` -- se
     * cuentan por separado solo para presentacion, nunca se resta una de
     * la otra del total de completadas.
     */
    public function buildStructureCalibrationSummary(): array
    {
        return Cache::remember(
            self::CALIBRATION_SUMMARY_CACHE_KEY,
            self::CALIBRATION_SUMMARY_CACHE_TTL_SECONDS,
            fn () => $this->computeStructureCalibrationSummary(),
        );
    }

    private function computeStructureCalibrationSummary(): array
    {
        $structure = $this->getActiveStructure();
        if (!$structure) {
            return [
                'structure_id' => null,
                'sheets' => [],
                'no_utilizadas' => [],
                'totals' => $this->emptyStructureTotals(),
            ];
        }

        $est = $this->parseEstructura($structure);
        $sheets = [];
        $noUtilizadas = [];
        $totals = $this->emptySheetCounters();
        $totals['sections_no_utilizadas'] = 0;
        $sheetsCompleted = 0;

        foreach ($est['forms'] ?? [] as $form) {
            $sheetName = $form['sheetName'] ?? $form['sheet'] ?? null;
            if ($sheetName === null || $sheetName === '') {
                continue;
            }

            $sectionsInSheet = array_values(array_filter(
                $form['sections'] ?? [],
                fn ($sec) => ($sec['codigo'] ?? '') !== ''
            ));

            // Hoja marcada 'no_utilizada' por Estadistica APS: no se llama
            // a buildPatternMatrix() para ninguna de sus secciones -- no
            // requieren section_review, patrones ni decisiones funcionales
            // (instruccion explicita del usuario, 2026-08-11). Se reporta
            // aparte, nunca como pendiente/calibrada/no_calibrable.
            $usageStatus = $this->sheetUsageStatus->getStatusFor((int) $structure->anio, $structure->serie, $sheetName);
            if ($usageStatus === RemSheetUsageStatusService::STATUS_NO_UTILIZADA) {
                $usageRow = RemSheetUsageStatus::where('anio', $structure->anio)
                    ->where('serie', $structure->serie)
                    ->where('sheet_name', $sheetName)
                    ->first();

                $noUtilizadas[] = [
                    'sheet_name' => $sheetName,
                    'sections_total' => count($sectionsInSheet),
                    'reason' => $usageRow->reason ?? null,
                    'decided_by' => $usageRow->decided_by ?? null,
                    'decided_at' => $usageRow->decided_at?->toIso8601String(),
                    'structure_id' => $usageRow->structure_id ?? null,
                ];
                $totals['sections_no_utilizadas'] += count($sectionsInSheet);

                continue;
            }

            $counters = $this->emptySheetCounters();
            $anyProgress = false;

            foreach ($sectionsInSheet as $sec) {
                $codigo = $sec['codigo'];

                $matrix = $this->buildPatternMatrix($sheetName, $codigo);
                $counters['sections_total']++;

                $completed = $matrix['reconciliation']['effective_section_reviewed'] ?? false;
                if ($completed) {
                    $counters['sections_completed']++;
                    if ($this->findNoCalibratableClosureQuestion($matrix['questions'] ?? []) !== null) {
                        $counters['sections_not_calibratable']++;
                    } else {
                        $counters['sections_calibrated']++;
                    }
                } else {
                    $counters['sections_pending']++;
                    foreach ($matrix['questions'] ?? [] as $q) {
                        if (trim((string) ($q['response'] ?? '')) !== '') {
                            $anyProgress = true;
                            break;
                        }
                    }
                }
            }

            $progressPct = $counters['sections_total'] > 0
                ? (int) round(($counters['sections_completed'] / $counters['sections_total']) * 100)
                : 0;

            $status = match (true) {
                $counters['sections_total'] > 0 && $counters['sections_completed'] === $counters['sections_total'] => 'completada',
                $counters['sections_completed'] > 0 || $anyProgress => 'en_revision',
                default => 'pendiente',
            };

            $sheets[] = array_merge(
                ['sheet_name' => $sheetName],
                $counters,
                ['progress_pct' => $progressPct, 'status' => $status]
            );

            if ($status === 'completada') {
                $sheetsCompleted++;
            }
            foreach ($counters as $key => $value) {
                $totals[$key] += $value;
            }
        }

        // sections_aplicables = todo lo que NO es 'no_utilizada'. progress_pct
        // se calcula EXCLUSIVAMENTE sobre aplicables -- una hoja no utilizada
        // nunca infla ni desinfla el porcentaje mostrado al funcionario.
        $totals['sections_aplicables'] = $totals['sections_total'];
        $totals['sections_total_estructura'] = $totals['sections_total'] + $totals['sections_no_utilizadas'];
        unset($totals['sections_total']);

        $totals['progress_pct'] = $totals['sections_aplicables'] > 0
            ? (int) round(($totals['sections_completed'] / $totals['sections_aplicables']) * 100)
            : 0;
        $totals['sheets_total'] = count($sheets);
        $totals['sheets_completed'] = $sheetsCompleted;
        $totals['sheets_no_utilizadas'] = count($noUtilizadas);

        return [
            'structure_id' => $structure->id,
            'sheets' => $sheets,
            'no_utilizadas' => $noUtilizadas,
            'totals' => $totals,
        ];
    }

    /** @return array<string,int> */
    private function emptyStructureTotals(): array
    {
        return [
            'sections_total_estructura' => 0,
            'sections_no_utilizadas' => 0,
            'sections_aplicables' => 0,
            'sections_completed' => 0,
            'sections_calibrated' => 0,
            'sections_not_calibratable' => 0,
            'sections_pending' => 0,
            'progress_pct' => 0,
            'sheets_total' => 0,
            'sheets_completed' => 0,
            'sheets_no_utilizadas' => 0,
        ];
    }

    /** @return array<string,int> */
    private function emptySheetCounters(): array
    {
        return [
            'sections_total' => 0,
            'sections_completed' => 0,
            'sections_calibrated' => 0,
            'sections_not_calibratable' => 0,
            'sections_pending' => 0,
        ];
    }

    /**
     * Determina si una seccion NO tiene ningun contenido calibrable --
     * hallazgo A32/E1 (2026-08-11): seccion estructuralmente valida (filas
     * reales, encabezados correctos) pero con TODAS sus celdas funcionales
     * bloqueadas y sin formulas, por lo que nunca puede generar un patron
     * (ver buildDynamicPatternDefinitions()/isNonCalibrableCellHeaderRow()).
     * Antes de este mecanismo, la UI de calibracion forzaba 6 decisiones
     * funcionales genericas sin evidencia real sobre la cual basarlas.
     *
     * Generico: no nombra ninguna hoja/seccion, se recalcula siempre en vivo
     * a partir del cell-data vigente -- si una estructura futura le agrega
     * celdas editables o formulas a esta seccion, este metodo vuelve a dar
     * 'requires_calibration' automaticamente en la proxima carga.
     *
     * Cualquier duda tecnica bloquea 'not_calibratable' (nunca lo habilita
     * por omision/ausencia de evidencia): sin cell-data, con escaneo
     * incompleto (discrepancia estructura vs cell-data: una celda funcional
     * esperada que no aparece en cell-data), o con warnings pendientes, la
     * seccion se queda en 'requires_calibration' aunque parezca vacia.
     *
     * Nota importante: escanea el rango FISICO completo [filaInicioDatos,
     * filaFinDatos] directamente, sin filtrar por row_type==='data'. Motivo:
     * buildMatrix() ya reclasifica a 'header' cualquier fila sin formula ni
     * celda editable (isNonCalibrableCellHeaderRow(), linea ~184) -- que es
     * EXACTAMENTE el tipo de fila que este metodo busca. Filtrar por
     * row_type==='data' dejaria la lista de filas vacia y el metodo nunca
     * podria detectar el caso real que motiva este mecanismo (A32/E1).
     */
    private function sectionHasNoCalibratableContent(
        array $sectionData,
        array $cellDataRows,
        bool $hasCellData,
        array $patterns,
        array $warnings,
    ): array {
        $filaInicio = (int) ($sectionData['filaInicioDatos'] ?? 0);
        $filaFin = (int) ($sectionData['filaFinDatos'] ?? 0);

        $validRange = !empty($sectionData) && $filaInicio > 0 && $filaFin >= $filaInicio;
        $functionalColumns = $validRange ? $this->getFunctionalColumns($sectionData, $cellDataRows) : [];

        $scanComplete = $validRange && $hasCellData && !empty($functionalColumns);
        $missingScanDetail = null;
        if ($scanComplete) {
            for ($row = $filaInicio; $row <= $filaFin; $row++) {
                $rowCells = $cellDataRows[$row] ?? [];
                foreach ($functionalColumns as $col) {
                    if (!array_key_exists($col, $rowCells)) {
                        $scanComplete = false;
                        $missingScanDetail = "{$col}{$row}";
                        break 2;
                    }
                }
            }
        }

        $hasEditableCell = false;
        $editableDetail = null;
        $hasFunctionalFormula = false;
        $formulaDetail = null;
        if ($scanComplete) {
            for ($row = $filaInicio; $row <= $filaFin; $row++) {
                $rowCells = $cellDataRows[$row] ?? [];
                foreach ($functionalColumns as $col) {
                    $cell = $rowCells[$col] ?? [];
                    if (!$hasEditableCell && !($cell['esta_bloqueada'] ?? true)) {
                        $hasEditableCell = true;
                        $editableDetail = "{$col}{$row}";
                    }
                    if (!$hasFunctionalFormula && ($cell['es_formula'] ?? false)) {
                        $hasFunctionalFormula = true;
                        $formulaDetail = "{$col}{$row}";
                    }
                }
            }
        }

        $criteria = [
            'structure_available_and_consistent' => $validRange && $scanComplete,
            'cell_data_available' => $hasCellData,
            'no_pending_warnings' => empty($warnings),
            'no_editable_cells' => $scanComplete && !$hasEditableCell,
            'no_functional_formulas' => $scanComplete && !$hasFunctionalFormula,
            'no_calibratable_patterns' => empty($patterns),
        ];

        $eligible = !in_array(false, $criteria, true);

        $reason = match (true) {
            $eligible => 'La sección no contiene celdas capturables ni fórmulas que requieran criterio funcional.',
            !$validRange => 'La sección no tiene un rango de datos válido o no tiene filas de datos.',
            !$hasCellData => 'La sección no tiene cell-data escaneado todavía.',
            !$scanComplete => $missingScanDetail
                ? "Escaneo incompleto o discrepancia entre estructura y cell-data en {$missingScanDetail}."
                : 'Escaneo incompleto o discrepancia entre estructura y cell-data.',
            !empty($warnings) => 'Existen advertencias técnicas pendientes sobre esta sección.',
            $hasEditableCell => "Existen celdas editables (ej. {$editableDetail}) que requieren calibración funcional.",
            $hasFunctionalFormula => "Existen fórmulas (ej. {$formulaDetail}) que requieren criterio funcional.",
            !empty($patterns) => 'La sección tiene patrones calibrables detectados.',
            default => 'La sección requiere calibración.',
        };

        return [
            'status' => $eligible ? 'not_calibratable' : 'requires_calibration',
            'reason' => $reason,
            'criteria' => $criteria,
        ];
    }

    /**
     * Busca la pregunta de cierre "no requiere calibracion" -- distinta de
     * un cierre de calibracion normal (response='revisada') por su
     * response='no_calibrable' explicito. type/review_status se mantienen
     * identicos a un cierre normal a proposito, para que
     * hasHistoricalSectionReviewed() y el resto de la infraestructura ya
     * existente (frontend incluido) sigan contando la seccion como
     * "revisada" sin tocar esos metodos.
     */
    private function findNoCalibratableClosureQuestion(array $questions): ?array
    {
        foreach ($questions as $q) {
            if (($q['type'] ?? '') === 'section_review'
                && ($q['review_status'] ?? '') === 'section_reviewed'
                && ($q['response'] ?? '') === 'no_calibrable'
            ) {
                return $q;
            }
        }

        return null;
    }

    /**
     * Umbral usado por applyExceptionClassification(): un patron con menos
     * filas que este porcentaje del patron mas grande de la seccion se
     * considera "grupo minoritario". Nace de la auditoria de A11
     * (2026-08-06): en las subsecciones A.1-A.6, filas como "Mujeres en
     * control ginecologico" o "Donantes de sangre" quedan solas o en pares
     * porque su formula suma un rango de columnas distinto al patron
     * dominante de 7 filas -- son excepciones de negocio genuinas, no ruido
     * de deteccion, y deben marcarse para que Estadistica nunca les aplique
     * por defecto la configuracion general de la seccion.
     */
    private const EXCEPTION_MINORITY_RATIO = 0.3;

    /**
     * Clasifica cada patron como parte del grupo mayoritario o como posible
     * excepcion de negocio -- puramente estadistico sobre la cantidad de
     * filas de cada patron dentro de la MISMA seccion, sin tocar reglas ni
     * respuestas guardadas. Es una senal para que la interfaz de
     * calibracion nunca asuma herencia automatica de la regla general en
     * patrones de fila unica o grupos claramente minoritarios: la persona
     * que calibra debe confirmar el criterio funcional de cada uno de forma
     * explicita.
     *
     * No marca nada como excepcion si la seccion tiene un unico patron (no
     * hay "mayoria" contra la cual comparar).
     */
    private function applyExceptionClassification(array $enriched): array
    {
        if (count($enriched) <= 1) {
            foreach ($enriched as &$p) {
                $p['pattern_size_class'] = 'majority';
                $p['possible_business_exception'] = false;
                $p['exception_reason'] = null;
            }
            unset($p);

            return $enriched;
        }

        $maxRowCount = max(array_map(fn ($p) => count($p['filas']), $enriched));

        foreach ($enriched as &$p) {
            $count = count($p['filas']);
            $isSingleRow = $count === 1;
            $isMinority = $count < $maxRowCount && $count <= max(1, (int) floor($maxRowCount * self::EXCEPTION_MINORITY_RATIO));
            $isException = $isSingleRow || $isMinority;

            $p['pattern_size_class'] = $isException ? 'minority' : 'majority';
            $p['possible_business_exception'] = $isException;
            $p['exception_reason'] = match (true) {
                $isSingleRow => 'Fila única: no comparte agrupación técnica con ninguna otra fila de la sección.',
                $isMinority => "Grupo minoritario: {$count} de {$maxRowCount} filas del patrón principal de la sección.",
                default => null,
            };
        }
        unset($p);

        return $enriched;
    }

    /**
     * Adjunta a cada patron enriquecido el resultado de PatternReconciliationService::reconcileLive():
     * pattern_rows (alias explicito de filas, para el vocabulario de
     * reconciliacion), reconciliation_status, backfill_status y
     * derived_from_fingerprint/historical_reference cuando corresponda. No
     * modifica filas/id/row_fingerprint ya calculados -- solo agrega campos.
     *
     * @return array{0: array, 1: array} [$enrichedConReconciliacion, $reconciliationResultsPorId]
     */
    private function applyPatternReconciliation(array $enriched, array $questions): array
    {
        $questionsByPatternId = [];
        foreach ($questions as $q) {
            if (in_array($q['type'] ?? '', ['pattern_question', 'pattern_confirmation'], true) && isset($q['pattern_id'])) {
                $questionsByPatternId[$q['pattern_id']][] = $q;
            }
        }

        $currentPatterns = array_map(
            fn ($p) => ['id' => $p['id'], 'row_fingerprint' => $p['row_fingerprint'], 'filas' => $p['filas']],
            $enriched
        );

        $reconciliation = $this->patternReconciliation->reconcileLive($currentPatterns, $questionsByPatternId);

        foreach ($enriched as &$p) {
            $r = $reconciliation[$p['id']] ?? [
                'reconciliation_status' => 'pending',
                'backfill_status' => null,
                'derived_from_fingerprint' => null,
                'historical_reference' => null,
            ];
            $p['pattern_rows'] = $p['filas'];
            $p['reconciliation_status'] = $r['reconciliation_status'];
            $p['backfill_status'] = $r['backfill_status'];
            $p['derived_from_fingerprint'] = $r['derived_from_fingerprint'];
            $p['historical_reference'] = $r['historical_reference'];
        }
        unset($p);

        return [$enriched, $reconciliation];
    }

    private function hasHistoricalSectionReviewed(array $questions): bool
    {
        foreach ($questions as $q) {
            if (($q['type'] ?? '') === 'section_review' && ($q['review_status'] ?? '') === 'section_reviewed') {
                return true;
            }
        }

        return false;
    }

    private function buildDynamicPatternDefinitions(array $rows, array $sectionData, array $cellDataRows): array
    {
        $groups = [];
        $hasCellData = !empty($cellDataRows);
        $totalColumns = $hasCellData
            ? $this->getFormulaTotalColumnsFromCellData($sectionData, $cellDataRows)
            : $this->getTotalColumns($sectionData);
        $structureOriginColumns = $this->getStructureOriginColumns($sectionData, $totalColumns);
        // Invariante por fila (misma razon que en buildMatrix()/getFormulaTotalColumnsFromCellData())
        // -- getEditableInputColumnsForRow() lo recalculaba desde cero por cada fila sin
        // total-columna activa. Precalculado aqui y pasado como parametro.
        $functionalColumns = $hasCellData ? $this->getFunctionalColumns($sectionData, $cellDataRows) : [];

        foreach ($rows as $rowData) {
            if (($rowData['row_type'] ?? '') !== 'data') continue;

            $row = (int) ($rowData['row'] ?? 0);
            if ($row <= 0) continue;

            $rowCells = $cellDataRows[$row] ?? [];
            if ($hasCellData && $this->isNonCalibrableCellHeaderRow($rowCells, $totalColumns)) {
                continue;
            }

            $formulaTemplates = [];
            $originColumns = [];
            $activeTotalColumns = [];
            $directInputColumns = [];

            if (!empty($rowCells)) {
                foreach ($totalColumns as $totalColumn) {
                    $cell = $rowCells[$totalColumn] ?? [];
                    if (!($cell['es_formula'] ?? false)) continue;

                    $deps = $cell['dependencias'] ?? [];
                    $depColumns = $this->extractDependencyColumns($deps);
                    if (!$this->isSameRowFormula($row, $deps) || empty($depColumns) || in_array($totalColumn, $depColumns, true)) {
                        continue;
                    }

                    // Revalida la firma PROPIA de esta fila (su propio conjunto de
                    // dependencias) contra el mismo gate de evidencia usado para
                    // habilitar la columna a nivel de seccion -- $totalColumns ya
                    // paso ese gate para ALGUNA fila, pero eso no garantiza que
                    // ESTA fila comparta una firma valida (hallazgo A07/A,
                    // 2026-08-21: una vez habilitada, cualquier formula de la
                    // misma columna se marcaba activa sin volver a verificarse,
                    // "arrastrando" filas cuya propia forma nunca fue evaluada).
                    if (!$this->isFunctionalHorizontalFormula($totalColumn, $depColumns, $sectionData, $cellDataRows)) {
                        continue;
                    }

                    $activeTotalColumns[] = $totalColumn;
                    $originColumns = array_values(array_unique(array_merge($originColumns, $depColumns)));
                    $formulaTemplates[$totalColumn] = $this->normalizeFormulaTemplate($cell['formula'] ?? '', $row);
                }

                if ($hasCellData && empty($activeTotalColumns)) {
                    $directInputColumns = $this->getEditableInputColumnsForRow($sectionData, $rowCells, $functionalColumns);
                }
            }

            if ($hasCellData && empty($activeTotalColumns) && empty($directInputColumns)) {
                continue;
            }

            $primaryTotalColumn = $activeTotalColumns[0] ?? ($totalColumns[0] ?? '');
            if (!empty($activeTotalColumns)) {
                $activeTotalColumns = array_values(array_unique($activeTotalColumns));
            } elseif (!$hasCellData && !empty($totalColumns)) {
                $activeTotalColumns = $totalColumns;
            }

            if (empty($activeTotalColumns) && empty($directInputColumns)) {
                $activeTotalColumns = $totalColumns ?: array_filter([(string) ($rowData['columna_total'] ?? '')]);
            }

            if (empty($originColumns) && !empty($directInputColumns)) {
                $originColumns = $directInputColumns;
            } elseif (empty($originColumns)) {
                $originColumns = $rowData['origen_columnas'] ?? $structureOriginColumns;
            }

            if (empty($formulaTemplates) && !empty($activeTotalColumns)) {
                foreach ($activeTotalColumns as $totalColumn) {
                    $formulaTemplates[$totalColumn] = $this->buildFormulaTemplateFromColumns($totalColumn, $originColumns);
                }
            }

            $editabilitySignature = $this->buildEditabilitySignature($sectionData, $rowCells);
            $signature = json_encode([
                'totals' => $activeTotalColumns,
                'origins' => $originColumns,
                'formulas' => $formulaTemplates,
                'editability' => $editabilitySignature,
                'evidence' => !empty($activeTotalColumns)
                    ? ($hasCellData ? ($rowData['tipo_evidencia'] ?? '') : 'structure_inferred')
                    : 'cell_data_direct_input',
                'mode' => !empty($activeTotalColumns) ? 'formula' : 'direct_input',
            ]);

            if (!isset($groups[$signature])) {
                $groups[$signature] = [
                    'filas' => [],
                    'total_columns' => $activeTotalColumns,
                    'primary_total_column' => $primaryTotalColumn ?: ($activeTotalColumns[0] ?? ''),
                    'origin_columns' => $originColumns,
                    'formula_templates' => $formulaTemplates,
                    'source' => $hasCellData ? 'cell_data' : 'structure_inferred',
                    'mode' => !empty($activeTotalColumns) ? 'formula' : 'direct_input',
                    // Identica para todas las filas de este grupo por construccion
                    // (es parte de la clave $signature que las agrupo) -- se
                    // captura una sola vez para exponerla en el patron final,
                    // consumida por computeCanonicalPatternFingerprint().
                    'editability_signature' => $editabilitySignature,
                ];
            }

            $groups[$signature]['filas'][] = $row;
        }

        $patterns = [];
        $id = 1;
        foreach ($groups as $group) {
            $totalColumns = $group['total_columns'];
            $originColumns = $group['origin_columns'];
            $formulaTemplates = $group['formula_templates'];
            $primaryTotal = $group['primary_total_column'] ?: ($totalColumns[0] ?? '');
            $formulaTemplate = $primaryTotal !== ''
                ? ($formulaTemplates[$primaryTotal] ?? '')
                : implode(' | ', $formulaTemplates);

            $patterns[] = [
                'id' => $id,
                'nombre' => 'Patrón ' . $id . ': ' . $this->describePatternName($formulaTemplates, $primaryTotal, $originColumns),
                'descripcion' => $group['source'] === 'cell_data'
                    ? 'Patrón detectado desde fórmulas y celdas reales del XLSM.'
                    : 'Patrón preliminar inferido desde la estructura de columnas.',
                'filas' => $group['filas'],
                'formula_template' => $formulaTemplate,
                'columnas_origen' => $originColumns,
                'columna_total' => $primaryTotal,
                'origin_columns' => $originColumns,
                'total_columns' => $totalColumns,
                'formula_templates' => $formulaTemplates,
                'source' => $group['source'],
                'mode' => $group['mode'] ?? 'formula',
                'editability_signature' => $group['editability_signature'] ?? '',
            ];
            $id++;
        }

        return $patterns;
    }

    private function buildColumnGroups(string $sheet, string $section, array $sectionData, array $cellDataRows, array $matrixRows): array
    {
        $isLegacyA01A = strtoupper($sheet) === 'A01' && strtoupper($section) === 'A';
        $source = !empty($cellDataRows) ? 'cell_data' : 'structure_inferred';
        $dataRows = $this->matrixDataRows($matrixRows);

        if ($isLegacyA01A) {
            $columns = $this->columnsBetween('U', 'AH');
            return [[
                'key' => 'legacy_special_columns',
                'label' => 'Columnas especiales U:AH',
                'type' => 'legacy_special',
                'start_column' => 'U',
                'end_column' => 'AH',
                'columns' => $this->buildColumnItems($columns, $sectionData, $cellDataRows, $dataRows),
                'editable_rows' => $this->countCellsByLockState($columns, $cellDataRows, false, $dataRows),
                'blocked_rows' => $this->countCellsByLockState($columns, $cellDataRows, true, $dataRows),
                'source' => 'legacy_a01_a',
                'description' => 'Columnas especiales heredadas de A01 Sección A. Se conserva para compatibilidad con calibraciones ya revisadas.',
            ]];
        }

        $totalColumns = !empty($cellDataRows)
            ? $this->getFormulaTotalColumnsFromCellData($sectionData, $cellDataRows)
            : $this->getTotalColumns($sectionData);
        $mainRules = $this->detectMainRuleGroups($totalColumns, $sectionData, $cellDataRows, $source);
        $mainColumns = [];
        foreach ($mainRules as $mainRule) {
            $mainColumns = array_values(array_unique(array_merge($mainColumns, [$mainRule['total']], $mainRule['components'])));
        }

        $availableColumns = $this->getFunctionalColumns($sectionData, $cellDataRows);
        $remaining = array_values(array_filter(
            $availableColumns,
            fn($column) => !in_array($column, $mainColumns, true)
        ));

        $ageCandidateColumns = array_values(array_filter(
            $remaining,
            fn($column) => $this->looksLikeAgeColumn($this->labelForColumn($column, $sectionData, $cellDataRows))
        ));
        $ageColumns = $this->expandContiguousColumnBlock($remaining, $ageCandidateColumns);
        $complementaryColumns = array_values(array_filter(
            $remaining,
            fn($column) => !in_array($column, $ageColumns, true)
        ));

        $groups = [];
        foreach ($mainRules as $index => $mainRule) {
            $ruleColumns = array_values(array_unique(array_merge([$mainRule['total']], $mainRule['components'])));
            $groups[] = [
                'key' => $index === 0 ? 'main_rule' : 'main_rule_' . ($index + 1),
                'label' => $mainRule['label'] ?? 'Regla principal de sexo',
                'type' => 'main_rule',
                'start_column' => $ruleColumns[0] ?? '',
                'end_column' => $ruleColumns[count($ruleColumns) - 1] ?? '',
                'columns' => $this->buildColumnItems($ruleColumns, $sectionData, $cellDataRows, $dataRows),
                'editable_rows' => $this->countCellsByLockState($ruleColumns, $cellDataRows, false, $dataRows),
                'blocked_rows' => $this->countCellsByLockState($ruleColumns, $cellDataRows, true, $dataRows),
                'source' => $source,
                'description' => $mainRule['description'],
                'total' => $mainRule['total'],
                'components' => $mainRule['components'],
                'formula' => $mainRule['formula'],
                'labels' => $this->labelsForColumns($ruleColumns, $sectionData, $cellDataRows),
            ];
        }

        if (!empty($ageColumns)) {
            $groups[] = [
                'key' => 'age_range',
                'label' => 'Rango etario ' . $this->formatColumnRange($ageColumns),
                'type' => 'age_range',
                'start_column' => $ageColumns[0],
                'end_column' => $ageColumns[count($ageColumns) - 1],
                'columns' => $this->buildColumnItems($ageColumns, $sectionData, $cellDataRows, $dataRows),
                'editable_rows' => $this->countCellsByLockState($ageColumns, $cellDataRows, false, $dataRows),
                'blocked_rows' => $this->countCellsByLockState($ageColumns, $cellDataRows, true, $dataRows),
                'source' => $source,
                'subgroups' => $this->buildSexColumnSubgroups($ageColumns, $sectionData, $cellDataRows, $dataRows, $source),
                'description' => 'Columnas de distribución por edad detectadas desde encabezados reales de la sección.',
            ];
        }

        if (!empty($complementaryColumns)) {
            $groups[] = [
                'key' => 'complementary',
                'label' => 'Variables complementarias ' . $this->formatColumnRange($complementaryColumns),
                'type' => 'complementary',
                'start_column' => $complementaryColumns[0],
                'end_column' => $complementaryColumns[count($complementaryColumns) - 1],
                'columns' => $this->buildColumnItems($complementaryColumns, $sectionData, $cellDataRows, $dataRows),
                'editable_rows' => $this->countCellsByLockState($complementaryColumns, $cellDataRows, false, $dataRows),
                'blocked_rows' => $this->countCellsByLockState($complementaryColumns, $cellDataRows, true, $dataRows),
                'source' => $source,
                'description' => 'Variables complementarias detectadas desde encabezados reales de la sección.',
            ];
        }

        return $groups;
    }

    private function legacySpecialColumnsFromGroups(array $columnGroups): array
    {
        foreach ($columnGroups as $group) {
            if (($group['type'] ?? '') === 'legacy_special') {
                return array_map(fn($column) => $column['letter'], $group['columns'] ?? []);
            }
        }
        return [];
    }

    private function detectMainRuleGroup(array $totalColumns, array $sectionData, array $cellDataRows, string $source): array
    {
        foreach ($cellDataRows as $row => $rowCells) {
            foreach ($totalColumns as $totalColumn) {
                $cell = $rowCells[$totalColumn] ?? [];
                if (!($cell['es_formula'] ?? false)) continue;

                $components = $this->extractDependencyColumns($cell['dependencias'] ?? []);
                $formula = $this->normalizeFormulaTemplate((string) ($cell['formula'] ?? ''), (int) $row);
                return [
                    'total' => $totalColumn,
                    'components' => $components,
                    'formula' => $this->humanFormula($totalColumn, $components, $formula),
                    'description' => 'Relación principal detectada desde la fórmula real del XLSM.',
                ];
            }
        }

        $total = $totalColumns[0] ?? '';
        $components = array_slice($totalColumns, 1);
        return [
            'total' => $total,
            'components' => $components,
            'formula' => $total !== '' && !empty($components) ? "{$total} = " . implode(' + ', $components) : '',
            'description' => $source === 'structure_inferred'
                ? 'Relación principal preliminar inferida desde columnas marcadas como total en la estructura.'
                : 'Relación principal detectada desde la estructura de la sección.',
        ];
    }

    private function detectMainRuleGroups(array $totalColumns, array $sectionData, array $cellDataRows, string $source): array
    {
        $rules = [];

        foreach ($cellDataRows as $row => $rowCells) {
            foreach ($totalColumns as $totalColumn) {
                $cell = $rowCells[$totalColumn] ?? [];
                if (!($cell['es_formula'] ?? false)) continue;

                $dependencies = $cell['dependencias'] ?? [];
                $components = $this->extractDependencyColumns($dependencies);
                if (!$this->isSameRowFormula((int) $row, $dependencies) || empty($components) || in_array($totalColumn, $components, true)) {
                    continue;
                }
                if (!$this->isSexMainRuleFormula($totalColumn, $components, $sectionData, $cellDataRows)
                    && !$this->isGenericTotalMainRuleFormula($totalColumn, $components, $sectionData, $cellDataRows)
                ) {
                    continue;
                }

                $signature = $totalColumn . ':' . implode(',', $components);
                if (isset($rules[$signature])) continue;

                $formula = $this->normalizeFormulaTemplate((string) ($cell['formula'] ?? ''), (int) $row);
                $labels = $this->labelsForColumns(array_merge([$totalColumn], $components), $sectionData, $cellDataRows);
                $rules[$signature] = [
                    'total' => $totalColumn,
                    'components' => $components,
                    'formula' => $this->humanFormula($totalColumn, $components, $formula),
                    'label' => $this->mainRuleLabel($totalColumn, $labels),
                    'description' => 'Relación principal detectada desde fórmula horizontal real del XLSM.',
                ];
            }
        }

        if (!empty($rules)) return array_values($rules);

        $total = $totalColumns[0] ?? '';
        $components = array_slice($totalColumns, 1);
        if ($total === '') return [];

        return [[
            'total' => $total,
            'components' => $components,
            'formula' => !empty($components) ? "{$total} = " . implode(' + ', $components) : '',
            'label' => 'Regla principal de sexo',
            'description' => $source === 'structure_inferred'
                ? 'Relación principal preliminar inferida desde columnas marcadas como total en la estructura.'
                : 'Relación principal detectada desde la estructura de la sección.',
        ]];
    }

    private function mainRuleLabel(string $totalColumn, array $labels): string
    {
        $totalLabel = trim((string) ($labels[$totalColumn] ?? ''));
        return $totalLabel !== '' ? "Regla principal: {$totalLabel}" : 'Regla principal de sexo';
    }

    private function buildFunctionalRulesForMatrixRow(int $row, array $rowCells, array $sectionData, array $cellDataRows, ?array $functionalColumns = null): array
    {
        if (empty($rowCells)) return [];

        $rules = [];

        // $functionalColumns es invariante por fila (depende de $sectionData y de
        // TODA la seccion, no de esta fila) -- el llamador normal (buildMatrix())
        // lo precalcula una sola vez fuera de su loop y lo pasa aqui. El default
        // null solo cubre llamadas directas fuera de ese loop (ninguna en este
        // archivo hoy, mantenido por compatibilidad/tests) recalculandolo como antes.
        $functionalColumns ??= $this->getFunctionalColumns($sectionData, $cellDataRows);

        foreach ($functionalColumns as $totalColumn) {
            $cell = $rowCells[$totalColumn] ?? [];
            if (!($cell['es_formula'] ?? false)) continue;

            $dependencies = $cell['dependencias'] ?? [];
            $originColumns = $this->extractDependencyColumns($dependencies);
            if (!$this->isSameRowFormula($row, $dependencies)) continue;
            if (!$this->isFunctionalHorizontalFormula($totalColumn, $originColumns, $sectionData, $cellDataRows)) continue;

            $originCoordinates = array_map(fn($column) => "{$column}{$row}", $originColumns);
            $formula = (string) ($cell['formula'] ?? '');

            $rules[] = [
                'total_column' => $totalColumn,
                'destination' => "{$totalColumn}{$row}",
                'destino_funcional' => "TOTAL ({$totalColumn}{$row})",
                'origin_columns' => $originColumns,
                'origin_coordinates' => $originCoordinates,
                'descripcion_funcional_origen' => $this->getFunctionalOriginDescription($originColumns),
                'formula_exacta' => $formula,
                'formula_template' => $formula !== '' ? $this->normalizeFormulaTemplate($formula, $row) : '',
                'label' => "{$totalColumn} = " . implode(' + ', $originColumns),
            ];
        }

        return $rules;
    }

    private function isVerticalConsolidationRow(int $row, array $rowCells): bool
    {
        $formulaCells = array_filter($rowCells, fn($cell) => (bool) ($cell['es_formula'] ?? false));
        if (empty($formulaCells)) return false;

        $verticalFormulas = 0;
        foreach ($formulaCells as $column => $cell) {
            $dependencies = $cell['dependencias'] ?? [];
            if (empty($dependencies)) continue;

            $dependencyColumns = $this->extractDependencyColumns($dependencies);
            if ($dependencyColumns !== [strtoupper((string) $column)]) continue;

            $sameColumnPreviousRows = true;
            foreach ($dependencies as $dependency) {
                if (!preg_match('/^([A-Z]+)(\d+)$/', strtoupper((string) $dependency), $matches)) {
                    $sameColumnPreviousRows = false;
                    break;
                }
                if ($matches[1] !== strtoupper((string) $column) || (int) $matches[2] >= $row) {
                    $sameColumnPreviousRows = false;
                    break;
                }
            }

            if ($sameColumnPreviousRows) {
                $verticalFormulas++;
            }
        }

        $label = $this->normalizeHeaderText((string) ($rowCells['A']['valor_bruto'] ?? ''));
        if ($label === 'total' && $verticalFormulas > 0) {
            return true;
        }

        return $verticalFormulas > 0 && $verticalFormulas === count($formulaCells);
    }

    private function inferSectionEndRowFromCellData(int $filaInicio, array $cellDataForRows): int
    {
        $rows = array_values(array_unique(array_map('intval', array_keys($cellDataForRows))));
        sort($rows);

        $candidate = $filaInicio;
        foreach ($rows as $row) {
            if ($row < $filaInicio) {
                continue;
            }

            if ($row > $candidate + 1 && $candidate >= $filaInicio) {
                break;
            }

            $candidate = $row;
            if ($this->isVerticalConsolidationRow($row, $cellDataForRows[$row] ?? [])) {
                break;
            }
        }

        return $candidate;
    }

    private function isSpecialSummaryRow(array $rowCells): bool
    {
        $label = $this->normalizeHeaderText((string) ($rowCells['A']['valor_bruto'] ?? ''));

        return str_contains($label, 'total de establecimientos educacionales');
    }

    private function buildFunctionalRulesForPatternRow(array $patron, int $row, array $cellDataRows): array
    {
        $rules = [];
        $totalColumns = $patron['total_columns'] ?? [$patron['columna_total']];
        $fallbackOrigins = $patron['origin_columns'] ?? $patron['columnas_origen'] ?? [];
        $formulaTemplates = $patron['formula_templates'] ?? [];

        foreach ($totalColumns as $totalColumn) {
            $cell = $cellDataRows[$row][$totalColumn] ?? [];
            $dependencies = $cell['dependencias'] ?? [];
            $originColumns = $this->extractDependencyColumns($dependencies);

            if (!empty($dependencies) && !$this->isSameRowFormula($row, $dependencies)) {
                continue;
            }

            if (empty($originColumns)) {
                $originColumns = $fallbackOrigins;
            }

            if (empty($originColumns) || in_array($totalColumn, $originColumns, true)) {
                continue;
            }

            $originCoordinates = array_map(fn($column) => "{$column}{$row}", $originColumns);
            $formula = (string) ($cell['formula'] ?? ($formulaTemplates[$totalColumn] ?? ''));

            $rules[] = [
                'total_column' => $totalColumn,
                'destination' => "{$totalColumn}{$row}",
                'destino_funcional' => "TOTAL ({$totalColumn}{$row})",
                'origin_columns' => $originColumns,
                'origin_coordinates' => $originCoordinates,
                'descripcion_funcional_origen' => $this->getFunctionalOriginDescription($originColumns),
                'formula_exacta' => $formula,
                'formula_template' => $formula !== '' ? $this->normalizeFormulaTemplate($formula, $row) : '',
                'label' => "{$totalColumn} = " . implode(' + ', $originColumns),
            ];
        }

        return $rules;
    }

    private function getFormulaTotalColumnsFromCellData(array $sectionData, array $cellDataRows): array
    {
        $columns = [];

        // Precalculado fuera del loop: getFunctionalColumns($sectionData, $cellDataRows)
        // no depende de $row/$rowCells (recibe siempre la seccion completa), asi que
        // recalcularlo en cada iteracion es el mismo patron O(filas) redundante ya
        // documentado en buildMatrix() -- aqui componia ademas con el propio escaneo
        // interno de getFunctionalColumns() sobre todas las filas, dando O(filas^2).
        // Medido: 288M llamadas a columnNumber() en el computo frio antes de este fix.
        $functionalColumns = $this->getFunctionalColumns($sectionData, $cellDataRows);

        foreach ($cellDataRows as $row => $rowCells) {
            foreach ($functionalColumns as $column) {
                $cell = $rowCells[$column] ?? [];
                if (!($cell['es_formula'] ?? false)) continue;

                $dependencies = $cell['dependencias'] ?? [];
                $dependencyColumns = $this->extractDependencyColumns($dependencies);
                if (!$this->isSameRowFormula((int) $row, $dependencies)) continue;
                if (empty($dependencyColumns) || in_array($column, $dependencyColumns, true)) continue;
                if (!$this->isFunctionalHorizontalFormula($column, $dependencyColumns, $sectionData, $cellDataRows)) continue;

                $columns[] = $column;
            }
        }

        return array_values(array_unique($columns));
    }

    private function isSexMainRuleFormula(string $totalColumn, array $components, array $sectionData, array $cellDataRows): bool
    {
        if (count($components) !== 2) return false;

        [$firstComponent, $secondComponent] = array_values($components);
        if ($this->columnNumber($firstComponent) !== $this->columnNumber($totalColumn) + 1) return false;
        if ($this->columnNumber($secondComponent) !== $this->columnNumber($totalColumn) + 2) return false;

        $totalLabel = $this->normalizeHeaderText($this->labelForColumn($totalColumn, $sectionData, $cellDataRows));
        $firstLabel = $this->normalizeHeaderText($this->labelForColumn($firstComponent, $sectionData, $cellDataRows));
        $secondLabel = $this->normalizeHeaderText($this->labelForColumn($secondComponent, $sectionData, $cellDataRows));

        $isValidTotal = str_contains($totalLabel, 'ambos sexos') || $totalLabel === 'total';

        return $isValidTotal
            && str_contains($firstLabel, 'hombres')
            && str_contains($secondLabel, 'mujeres');
    }

    /**
     * Gate de evidencia para aceptar `totalColumn = componentes` como relacion
     * horizontal real -- rediseñado 2026-08-21 (hallazgo A07/A: filas con 5 y
     * 18 componentes, ambas genuinas y verificadas contra el Excel real,
     * quedaban rechazadas porque las ramas anteriores exigian una cantidad
     * exacta de componentes y coincidencia de texto de etiqueta -- ninguna de
     * las dos es un criterio confiable: una fila real puede tener cualquier
     * cantidad de componentes, y una etiqueta multinivel compuesta, ej.
     * "CONSULTAS Y CONTROLES MEDICOS / TOTAL", nunca calzaba con el patron de
     * texto exacto `contains('total ')`).
     *
     * Dos caminos de aceptacion, ninguno basado en cantidad de componentes ni
     * en texto de etiqueta:
     *  - isSexMainRuleFormula(): suma de subtotales adyacentes (ej. Ambos
     *    Sexos = Hombres + Mujeres) -- tolera que los componentes sean ellos
     *    mismos formulas, no solo entrada manual.
     *  - hasEditableInputComponentsForFormula(): evidencia directa de
     *    cell-data -- existe al menos una fila real donde el total es formula,
     *    sus dependencias coinciden EXACTAMENTE con los componentes dados, y
     *    esos componentes son entrada editable real. Sin limite de cantidad.
     */
    /**
     * Gate de evidencia para aceptar `totalColumn = componentes` como relacion
     * horizontal real -- rediseñado 2026-08-21 (hallazgo A07/A: filas con 5 y
     * 18 componentes, ambas genuinas y verificadas contra el Excel real,
     * quedaban rechazadas porque las ramas anteriores exigian una cantidad
     * exacta de componentes y coincidencia de texto de etiqueta -- ninguna de
     * las dos es un criterio confiable: una fila real puede tener cualquier
     * cantidad de componentes, y una etiqueta multinivel compuesta, ej.
     * "CONSULTAS Y CONTROLES MEDICOS / TOTAL", nunca calzaba con el patron de
     * texto exacto `contains('total ')`).
     *
     * Dos caminos de aceptacion, ninguno basado en cantidad de componentes ni
     * en texto de etiqueta:
     *  - isSexMainRuleFormula(): suma de subtotales adyacentes (ej. Ambos
     *    Sexos = Hombres + Mujeres) -- tolera que los componentes sean ellos
     *    mismos formulas, no solo entrada manual.
     *  - hasEditableInputComponentsForFormula(): evidencia directa de
     *    cell-data -- existe al menos una fila real donde el total es formula,
     *    sus dependencias coinciden EXACTAMENTE con los componentes dados, y
     *    esos componentes son entrada editable real. Sin limite de cantidad.
     */
    private function isFunctionalHorizontalFormula(string $totalColumn, array $components, array $sectionData, array $cellDataRows): bool
    {
        if (empty($components)) return false;
        if (in_array($totalColumn, $components, true)) return false;

        return $this->isSexMainRuleFormula($totalColumn, $components, $sectionData, $cellDataRows)
            || $this->hasEditableInputComponentsForFormula($totalColumn, $components, $cellDataRows);
    }

    private function isGenericTotalMainRuleFormula(string $totalColumn, array $components, array $sectionData, array $cellDataRows): bool
    {
        $totalLabel = $this->normalizeHeaderText($this->labelForColumn($totalColumn, $sectionData, $cellDataRows));

        return ($totalLabel === 'total' || str_contains($totalLabel, 'total '))
            && count($components) > 1
            && $this->hasEditableInputComponentsForFormula($totalColumn, $components, $cellDataRows);
    }

    /**
     * Busqueda EXISTENCIAL de evidencia -- corregido 2026-08-21 (hallazgo
     * auditoria de 13 secciones "CORRECCION_DE_ARRASTRE_INVALIDO": A05/C,
     * A05/C2, A05/G, A05/Q, A08/R, A09/G). Bug previo: la funcion retornaba
     * `false` en cuanto encontraba la PRIMERA fila con la firma exacta
     * (columna total + conjunto de dependencias) si esa fila fallaba el
     * chequeo de editabilidad -- nunca seguia buscando otras filas con la
     * misma firma. Patron real descubierto: una fila TOTAL/subtotal lider
     * (ej. A05/C fila 35) comparte la firma exacta con filas normales de
     * captura real (ej. A05/C filas 45,46) porque el rango de columnas que
     * suman es identico, pero los componentes de la fila lider son ellos
     * mismos formulas bloqueadas (no captura), mientras que en las filas
     * normales SI son entrada editable real. Si la fila lider aparecia
     * primero en la iteracion, la funcion abortaba antes de llegar a
     * confirmar la evidencia real de las filas validas.
     *
     * Principio corregido: existencia de evidencia valida, no "la primera
     * coincidencia decide". Recorre TODAS las filas con la firma exacta;
     * solo retorna false si NINGUNA de ellas tiene los componentes
     * editables/no-formula.
     */
    private function hasEditableInputComponentsForFormula(string $totalColumn, array $components, array $cellDataRows): bool
    {
        $normalizedComponents = $components;
        sort($normalizedComponents);

        foreach ($cellDataRows as $rowCells) {
            $totalCell = $rowCells[$totalColumn] ?? [];
            if (!($totalCell['es_formula'] ?? false)) continue;

            $dependencies = $totalCell['dependencias'] ?? [];
            $dependencyColumns = $this->extractDependencyColumns($dependencies);
            sort($dependencyColumns);
            if ($dependencyColumns !== $normalizedComponents) continue;

            $allEditable = true;
            foreach ($components as $component) {
                $componentCell = $rowCells[$component] ?? [];
                if (($componentCell['es_formula'] ?? false) || ($componentCell['esta_bloqueada'] ?? true)) {
                    $allEditable = false;
                    break;
                }
            }

            if ($allEditable) {
                return true;
            }

            // Esta fila comparte la firma pero no es evidencia valida --
            // sigue buscando otras filas con la misma firma exacta, en vez
            // de abortar la busqueda completa.
        }

        return false;
    }

    private function buildSexColumnSubgroups(array $columns, array $sectionData, array $cellDataRows, array $dataRows, string $source): array
    {
        $men = [];
        $women = [];

        foreach ($columns as $column) {
            $label = $this->normalizeHeaderText($this->labelForColumn($column, $sectionData, $cellDataRows));
            if (str_contains($label, 'hombres')) {
                $men[] = $column;
            } elseif (str_contains($label, 'mujeres')) {
                $women[] = $column;
            }
        }

        $groups = [];
        if (!empty($men)) {
            $groups[] = [
                'key' => 'age_range_men',
                'label' => 'Rangos etarios Hombres ' . $this->formatColumnRange($men),
                'type' => 'age_range_sex',
                'columns' => $this->buildColumnItems($men, $sectionData, $cellDataRows, $dataRows),
                'editable_rows' => $this->countCellsByLockState($men, $cellDataRows, false, $dataRows),
                'blocked_rows' => $this->countCellsByLockState($men, $cellDataRows, true, $dataRows),
                'source' => $source,
            ];
        }

        if (!empty($women)) {
            $groups[] = [
                'key' => 'age_range_women',
                'label' => 'Rangos etarios Mujeres ' . $this->formatColumnRange($women),
                'type' => 'age_range_sex',
                'columns' => $this->buildColumnItems($women, $sectionData, $cellDataRows, $dataRows),
                'editable_rows' => $this->countCellsByLockState($women, $cellDataRows, false, $dataRows),
                'blocked_rows' => $this->countCellsByLockState($women, $cellDataRows, true, $dataRows),
                'source' => $source,
            ];
        }

        return $groups;
    }

    /**
     * Detecta, con evidencia real (label declarado en fields + cell_data),
     * el conjunto de columnas de la seccion que son etiquetas (concepto,
     * profesional, sub-categoria) y por lo tanto nunca deben tratarse como
     * columnas de dato/editable. Reemplaza el supuesto fijo ['A','B']:
     * concepto y profesional se detectan por texto de label (mismo criterio
     * que RemParserService::buildSectionMaps()); sub-categoria se detecta
     * por evidencia consistente de cell_data (mismo criterio que
     * RemParserService::detectLabelSubcategoryColumn()). Una columna
     * marcada esTotal=true nunca se excluye por esta via -- las columnas de
     * total ya se filtran aparte, en cada sitio que las necesita.
     */
    private function detectLabelColumns(array $sectionData, array $cellDataForRows, int $filaInicio, int $filaFin, int $filaHeader = 0): array
    {
        $conceptColumn = $this->firstFieldWithLabelInSection($sectionData, ['tipo de control', 'curso', 'actividad', 'lugar']) ?? 'A';

        $professionalColumn = $this->firstProfessionalIdentifierColumnInSection($sectionData);
        if ($professionalColumn !== null) {
            foreach ($sectionData['fields'] ?? [] as $field) {
                if (strtoupper((string) ($field['letra'] ?? '')) === $professionalColumn && ($field['esTotal'] ?? false)) {
                    $professionalColumn = null;
                    break;
                }
            }
        }

        $excluded = array_values(array_unique(array_filter([$conceptColumn, $professionalColumn])));
        $subcategoryColumn = $this->detectSubcategoryColumnFromCellData($sectionData, $cellDataForRows, $excluded, $filaInicio, $filaFin);

        // CAPA 1 (fallback general, mismo mecanismo que RemParserService --
        // ver ColumnRoleResolverService::detectSubcategoryColumn() -- para
        // que la matriz de calibracion no diverja de lo que realmente
        // persiste el parser): el detector anterior exige zona=zona_etiquetas
        // y no encuentra nada si la hoja no tiene cell_data (ej. A19a/A29/A31).
        if ($subcategoryColumn === null) {
            $subcategoryColumn = $this->columnRoleResolver->detectSubcategoryColumn(
                $cellDataForRows,
                $sectionData['fields'] ?? [],
                $excluded,
                $filaInicio,
                $filaFin,
                $conceptColumn,
            );
        }

        $labelColumns = array_values(array_unique(array_filter([$conceptColumn, $professionalColumn, $subcategoryColumn])));

        // CAPA 2 (alcance acotado para este servicio, ver nota de diseño): las
        // columnas dentro del ancho de fusion del encabezado de concepto se
        // tratan aqui como columna de etiqueta SOLO cuando nunca muestran
        // evidencia real de valor numerico en ninguna fila de la seccion (el
        // mismo criterio de RemParserService::classifyOverflowCell(), pero
        // agregado a nivel de seccion en vez de por fila: este servicio ya
        // usa `currentLabelColumns` como una lista fija por seccion en mas de
        // 10 sitios, y resolverla por fila ahi ampliaria el alcance de este
        // cambio mucho mas alla de lo pedido). Si la columna SI llega a tener
        // una fila con valor numerico real (ej. A09/I), se deja fuera de esta
        // lista y sigue tratandose como candidata numerica normal -- coherente
        // con el 96% de los casos reales auditados donde esto no ocurre.
        $excludedForOverflow = array_values(array_unique(array_filter([$professionalColumn, $subcategoryColumn])));
        $overflowColumns = $this->columnRoleResolver->resolveConceptOverflowColumns(
            $cellDataForRows,
            $filaHeader,
            $conceptColumn,
            $excludedForOverflow,
        );
        foreach ($overflowColumns as $overflowColumn) {
            if ($this->overflowColumnIsNeverNumeric($cellDataForRows, $overflowColumn, $filaInicio, $filaFin)) {
                $labelColumns[] = $overflowColumn;
            }
        }

        return array_values(array_unique(array_filter($labelColumns)));
    }

    private function overflowColumnIsNeverNumeric(array $cellDataForRows, string $column, int $filaInicio, int $filaFin): bool
    {
        $sawAnyEvidence = false;
        for ($row = $filaInicio; $row <= $filaFin; $row++) {
            $cell = $cellDataForRows[$row][$column] ?? null;
            if ($cell === null) {
                continue;
            }
            $rawValue = trim((string) ($cell['valor_bruto'] ?? ''));
            if ($rawValue === '') {
                continue;
            }
            $sawAnyEvidence = true;
            if (is_numeric($rawValue)) {
                return false;
            }
        }
        return $sawAnyEvidence;
    }

    private function firstFieldWithLabelInSection(array $sectionData, array $needles): ?string
    {
        foreach ($sectionData['fields'] ?? [] as $field) {
            $label = $this->normalizeHeaderText((string) ($field['label'] ?? ''));
            foreach ($needles as $needle) {
                if (str_contains($label, $needle)) {
                    return strtoupper((string) ($field['letra'] ?? ''));
                }
            }
        }

        return null;
    }

    /**
     * Misma correccion que RemParserService::firstProfessionalIdentifierColumn()
     * -- ver ese metodo para el detalle completo de la evidencia y el criterio.
     * Duplicada aqui porque este servicio mantiene su propia copia de la
     * deteccion de columnas (concept/profesional/subcategoria) para el panel
     * de calibracion, independiente del parser.
     */
    private function firstProfessionalIdentifierColumnInSection(array $sectionData): ?string
    {
        $enumerationStarters = ['un', 'una', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'otro', 'otros', 'otra', 'otras', 'mas', 'más'];

        foreach ($sectionData['fields'] ?? [] as $field) {
            $label = $this->normalizeHeaderText((string) ($field['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $words = preg_split('/[^a-z0-9]+/', $label, -1, PREG_SPLIT_NO_EMPTY);
            if (empty($words)) {
                continue;
            }

            $hasProfesionalWord = in_array('profesional', $words, true) || in_array('profesionales', $words, true);
            if (!$hasProfesionalWord) {
                continue;
            }

            if (count($words) > 4) {
                continue;
            }

            if (in_array($words[0], $enumerationStarters, true)) {
                continue;
            }

            return strtoupper((string) ($field['letra'] ?? ''));
        }

        return null;
    }

    private function detectSubcategoryColumnFromCellData(array $sectionData, array $cellDataForRows, array $excluded, int $filaInicio, int $filaFin): ?string
    {
        if (empty($cellDataForRows)) {
            return null;
        }

        $candidateColumns = [];
        foreach ($sectionData['fields'] ?? [] as $field) {
            $letter = strtoupper((string) ($field['letra'] ?? ''));
            if ($letter === '' || in_array($letter, $excluded, true) || ($field['esTotal'] ?? false) || in_array($letter, $candidateColumns, true)) {
                continue;
            }
            $candidateColumns[] = $letter;
        }

        foreach ($candidateColumns as $column) {
            $sawEvidence = false;
            $consistentLabel = true;

            foreach ($cellDataForRows as $row => $cellsInRow) {
                if ($row < $filaInicio || $row > $filaFin) {
                    continue;
                }

                $cell = $cellsInRow[$column] ?? null;
                if ($cell === null) {
                    continue;
                }

                $sawEvidence = true;

                $isLabelCell = ($cell['esta_bloqueada'] ?? false) === true
                    && ($cell['es_editable'] ?? false) !== true
                    && ($cell['es_formula'] ?? false) !== true
                    && in_array($cell['tipo_celda'] ?? null, ['etiqueta', 'vacio'], true)
                    && ($cell['zona'] ?? null) === 'zona_etiquetas';

                if (!$isLabelCell) {
                    $consistentLabel = false;
                    break;
                }
            }

            if ($sawEvidence && $consistentLabel) {
                return $column;
            }
        }

        return null;
    }

    private function normalizeHeaderText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ];

        return strtr($value, $map);
    }

    private function isSameRowFormula(int $row, array $dependencies): bool
    {
        if (empty($dependencies)) return false;

        foreach ($dependencies as $dependency) {
            if (!preg_match('/^[A-Z]+(\d+)$/', strtoupper((string) $dependency), $matches)) {
                return false;
            }
            if ((int) $matches[1] !== $row) {
                return false;
            }
        }

        return true;
    }

    private function humanFormula(string $totalColumn, array $components, string $formulaTemplate): string
    {
        if ($totalColumn !== '' && !empty($components)) {
            return "{$totalColumn} = " . implode(' + ', $components);
        }
        return $formulaTemplate;
    }

    private function getFunctionalColumns(array $sectionData, array $cellDataRows = []): array
    {
        $columns = [];
        foreach ($sectionData['fields'] ?? [] as $field) {
            $letter = strtoupper((string) ($field['letra'] ?? ''));
            if ($letter === '') continue;
            if (in_array($letter, $this->currentLabelColumns, true) && !($field['esTotal'] ?? false)) continue;
            if ($field['esControlOculto'] ?? false) continue;
            $columns[] = $letter;
        }
        foreach ($cellDataRows as $rowCells) {
            foreach (array_keys($rowCells) as $coordinateColumn) {
                $letter = strtoupper((string) $coordinateColumn);
                if ($letter === '') continue;
                if (!preg_match('/^[A-Z]+$/', $letter)) continue;
                if (in_array($letter, $this->currentLabelColumns, true) && !($rowCells[$letter]['es_formula'] ?? false)) continue;
                $columns[] = $letter;
            }
        }
        usort($columns, fn($a, $b) => $this->columnNumber($a) <=> $this->columnNumber($b));
        return array_values(array_unique($columns));
    }

    private function getEditableInputColumnsForRow(array $sectionData, array $rowCells, ?array $functionalColumns = null): array
    {
        // $functionalColumns, cuando lo pasa el llamador, es el resultado
        // section-wide (todas las filas) precalculado una sola vez -- superset
        // del resultado de calcularlo solo con [$rowCells]. Es equivalente aqui
        // porque el filtro de abajo ya descarta explicitamente (a) toda columna
        // de etiqueta sin condicion, y (b) cualquier columna ausente en esta
        // fila via `empty($cell)` -- exactamente lo que limitaria el resultado
        // a una sola fila si se recalculara. El default null preserva el
        // comportamiento original para cualquier otro llamador.
        $functionalColumns ??= $this->getFunctionalColumns($sectionData, [$rowCells]);
        $columns = [];

        foreach ($functionalColumns as $column) {
            if (in_array($column, $this->currentLabelColumns, true)) continue;

            $cell = $rowCells[$column] ?? [];
            if (empty($cell)) continue;
            if ($cell['es_formula'] ?? false) continue;
            if ($cell['esta_bloqueada'] ?? true) continue;

            $columns[] = $column;
        }

        return $columns;
    }

    private function columnsFromRange(string $start, string $end, array $sectionData): array
    {
        $startNumber = $this->columnNumber($start);
        $endNumber = $this->columnNumber($end);
        return array_values(array_filter(
            $this->getFunctionalColumns($sectionData),
            fn($column) => $this->columnNumber($column) >= $startNumber && $this->columnNumber($column) <= $endNumber
        ));
    }

    private function columnsBetween(string $start, string $end): array
    {
        $columns = [];
        for ($number = $this->columnNumber($start); $number <= $this->columnNumber($end); $number++) {
            $columns[] = $this->columnName($number);
        }
        return $columns;
    }

    private function expandContiguousColumnBlock(array $availableColumns, array $candidateColumns): array
    {
        if (empty($candidateColumns)) return [];

        $candidateNumbers = array_map(fn($column) => $this->columnNumber($column), $candidateColumns);
        $start = min($candidateNumbers);
        $end = max($candidateNumbers);

        return array_values(array_filter(
            $availableColumns,
            fn($column) => $this->columnNumber($column) >= $start && $this->columnNumber($column) <= $end
        ));
    }

    private function buildColumnItems(array $columns, array $sectionData, array $cellDataRows, array $dataRows): array
    {
        return array_map(fn($column) => [
            'letter' => $column,
            'label' => $this->labelForColumn($column, $sectionData, $cellDataRows),
            'editable_rows' => $this->countCellsByLockState([$column], $cellDataRows, false, $dataRows),
            'blocked_rows' => $this->countCellsByLockState([$column], $cellDataRows, true, $dataRows),
        ], $columns);
    }

    private function labelsForColumns(array $columns, array $sectionData, array $cellDataRows): array
    {
        $labels = [];
        foreach ($columns as $column) {
            $labels[$column] = $this->labelForColumn($column, $sectionData, $cellDataRows);
        }
        return $labels;
    }

    private function labelForColumn(string $column, array $sectionData, array $cellDataRows): string
    {
        $hierarchicalLabel = $this->hierarchicalHeaderLabel($column, $sectionData, $cellDataRows);
        if ($hierarchicalLabel !== '') return $hierarchicalLabel;

        $preferredRows = array_values(array_unique(array_filter([
            (int) ($sectionData['filaInicioDatos'] ?? 0),
            (int) ($sectionData['filaHeader'] ?? 0) + 1,
            (int) ($sectionData['filaHeader'] ?? 0),
        ])));

        foreach ($preferredRows as $row) {
            $value = trim((string) ($cellDataRows[$row][$column]['valor_bruto'] ?? ''));
            if ($value !== '') return $value;
        }

        foreach ($cellDataRows as $rowCells) {
            $value = trim((string) ($rowCells[$column]['valor_bruto'] ?? ''));
            if ($value !== '') return $value;
        }

        foreach ($sectionData['fields'] ?? [] as $field) {
            if (strtoupper((string) ($field['letra'] ?? '')) === $column && !empty($field['label'])) {
                return (string) $field['label'];
            }
        }

        return '';
    }

    private function hierarchicalHeaderLabel(string $column, array $sectionData, array $cellDataRows): string
    {
        $headerRow = (int) ($sectionData['filaHeader'] ?? 0);
        if ($headerRow <= 0) return '';

        $top = trim((string) ($cellDataRows[$headerRow][$column]['valor_bruto'] ?? ''));
        $middleRow = $headerRow + 1;
        $bottomRow = $headerRow + 2;
        $middle = trim((string) ($cellDataRows[$middleRow][$column]['valor_bruto'] ?? ''));
        $bottom = trim((string) ($cellDataRows[$bottomRow][$column]['valor_bruto'] ?? ''));

        if ($this->isSexHeader($bottom)) {
            if ($this->looksLikeAgeColumn($middle)) {
                return "{$middle} - {$bottom}";
            }

            $parentAge = $this->nearestLeftAgeHeader($column, $middleRow, $cellDataRows);
            if ($parentAge !== '') {
                return "{$parentAge} - {$bottom}";
            }

            return $bottom;
        }

        if ($this->looksLikeAgeColumn($middle)) return $middle;
        if ($middle !== '') return $middle;
        if ($top !== '') return $top;

        return '';
    }

    private function nearestLeftAgeHeader(string $column, int $row, array $cellDataRows): string
    {
        for ($number = $this->columnNumber($column) - 1; $number > 0; $number--) {
            $candidate = $this->columnName($number);
            $value = trim((string) ($cellDataRows[$row][$candidate]['valor_bruto'] ?? ''));
            if ($this->looksLikeAgeColumn($value)) return $value;
            if ($value !== '' && !$this->isSexHeader($value)) return '';
        }

        return '';
    }

    private function isSexHeader(string $label): bool
    {
        $normalized = mb_strtolower(trim($label));
        return in_array($normalized, ['hombres', 'mujeres', 'ambos sexos'], true);
    }

    private function countCellsByLockState(array $columns, array $cellDataRows, bool $blocked, array $dataRows): int
    {
        $count = 0;
        foreach ($cellDataRows as $row => $rowCells) {
            if (!in_array((int) $row, $dataRows, true)) continue;
            foreach ($columns as $column) {
                if (!array_key_exists($column, $rowCells)) continue;
                $isBlocked = (bool) ($rowCells[$column]['esta_bloqueada'] ?? false);
                if ($isBlocked === $blocked) $count++;
            }
        }
        return $count;
    }

    private function matrixDataRows(array $matrixRows): array
    {
        return array_values(array_map(
            fn($row) => (int) $row['row'],
            array_filter($matrixRows, fn($row) => ($row['row_type'] ?? '') === 'data')
        ));
    }

    private function looksLikeAgeColumn(string $label): bool
    {
        $normalized = mb_strtolower(trim($label));
        if ($normalized === '') return false;
        return str_contains($normalized, 'mes')
            || str_contains($normalized, 'año')
            || str_contains($normalized, 'anos')
            || str_contains($normalized, 'etario')
            || str_contains($normalized, 'menor')
            || str_contains($normalized, 'mayor')
            || str_contains($normalized, 'más')
            || preg_match('/\bmas\b/u', $normalized) === 1
            || preg_match('/\d+\s*-\s*\d+/', $normalized) === 1;
    }

    private function formatColumnRange(array $columns): string
    {
        if (empty($columns)) return '';
        return $columns[0] . ':' . $columns[count($columns) - 1];
    }

    /**
     * Memoizado por instancia: funcion pura (misma letra de columna siempre
     * da el mismo numero), y el universo de letras distintas vistas en una
     * request es pequeno (columnas de Excel reales, nunca miles) frente a la
     * cantidad de veces que se invoca -- llamada desde comparadores de
     * usort() y desde filtros ejecutados por columna en muchos puntos de
     * este archivo.
     */
    private array $columnNumberCache = [];

    private function columnNumber(string $column): int
    {
        if (isset($this->columnNumberCache[$column])) {
            return $this->columnNumberCache[$column];
        }

        $number = 0;
        foreach (str_split(strtoupper($column)) as $char) {
            $number = ($number * 26) + (ord($char) - 64);
        }

        return $this->columnNumberCache[$column] = $number;
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(($number % 26) + 65) . $name;
            $number = intdiv($number, 26);
        }
        return $name;
    }

    public function getRowDetail(string $sheet, string $section, int $row): ?array
    {
        $matrix = $this->buildMatrix($sheet, $section);
        foreach ($matrix['rows'] as $r) {
            if ((int) $r['row'] === $row) return $r;
        }
        return null;
    }

    // ─── Row classification ───────────────────────────────────────

    private function classifyRow(string $sheet, string $section, int $row, int $headerRow, array $sectionData): string
    {
        $knownHeaders = $this->getKnownHeaderRows($sheet, $section);

        if (in_array($row, $knownHeaders)) return 'header';
        if (in_array($row, $this->getKnownSpacers($sheet, $section))) return 'spacer';
        if (in_array($row, $this->getKnownNotApplicable($sheet, $section))) return 'not_applicable';

        if ($row === $headerRow) return 'header';

        $totalFieldCount = 0;
        $blockedFieldCount = 0;
        foreach ($sectionData['fields'] ?? [] as $f) {
            if (!in_array($f['letra'] ?? '', $this->currentLabelColumns) && !($f['esControlOculto'] ?? false)) {
                $totalFieldCount++;
            }
            if ($f['esControlOculto'] ?? false) $blockedFieldCount++;
        }

        $lastRow = $sectionData['filaFinDatos'] ?? 32;
            if ($this->isEmbeddedBackwardSubtotalRow($sheet, $section, $row, $sectionData)) return 'total';
            if ($this->isEmbeddedLeadingTotalRow($sheet, $section, $row, $sectionData)) return 'total';

        return 'data';
    }

    private function getKnownHeaderRows(string $sheet, string $section): array
    {
        $map = [
            'A01' => ['A' => [10]],
        ];
        return $map[$sheet][$section] ?? [];
    }

    private function getKnownSpacers(string $sheet, string $section): array
    {
        return [];
    }

    private function getKnownNotApplicable(string $sheet, string $section): array
    {
        return [];
    }

    /**
     * Fila subtotal EMBEBIDA que agrega hacia atras -- hallazgo A32/F2 fila
     * 140 (2026-08-10), tambien confirmado en A26/A.1 fila 41 y A26/B fila
     * 59. Misma logica generica que RemParserService::isEmbeddedBackwardSubtotalRow(),
     * duplicada aqui de forma independiente: busca la primera columna (en
     * orden de indice, entre los campos reales de la seccion, excluyendo
     * columnas de control oculto) con texto plano que parezca una etiqueta
     * de TOTAL -- no cualquier texto (hallazgo real de A09/I fila 336: un
     * dato derivado/calculado legitimo con formulas hacia atras, no un
     * subtotal de control, NUNCA debe clasificarse como 'total'). El resto
     * de columnas debe ser formula que agregue exclusivamente filas
     * anteriores dentro de la seccion (una referencia a la propia fila es
     * neutral). Al clasificarse como 'total' en vez de 'data', la fila
     * queda automaticamente fuera de la construccion de patrones (ver
     * usos de row_type === 'data' en este archivo) sin necesidad de un
     * mecanismo adicional.
     */
    private function isEmbeddedBackwardSubtotalRow(string $sheet, string $section, int $row, array $sectionData): bool
    {
        if (!$this->cellDataStorage->hasCellData($sheet, $section)) {
            return false;
        }

        $sectionStartRow = (int) ($sectionData['filaInicioDatos'] ?? 0);
        if ($sectionStartRow <= 0) {
            return false;
        }

        // IMPORTANTE: NO se excluyen columnas esControlOculto aqui -- a
        // diferencia de otros usos de esa bandera en este archivo, "control
        // oculto" en este codebase significa "formula en TODAS las filas de
        // la seccion" (ver ColumnDetectorService::isControlOculto()), que es
        // EXACTAMENTE el tipo de columna donde vive la evidencia de
        // agregacion hacia atras que este metodo busca. Hallazgo real de
        // A32/F2 (2026-08-10): casi todas sus columnas de dato (C..AS, 43 de
        // 45 campos) estan marcadas esControlOculto=true porque son
        // subtotales por-fila calculados (ej. "Ambos Sexos" = Hombres +
        // Mujeres), no valores crudos capturados -- excluirlas dejaba sin
        // ninguna columna con la que confirmar la fila 140 como subtotal,
        // haciendo que volviera a aparecer en un patron tras el scan-cells
        // real (las pruebas sinteticas nunca marcaron esControlOculto=true,
        // por lo que esto no se detecto hasta aplicar el patch real).
        $columnas = [];
        foreach ($sectionData['fields'] ?? [] as $f) {
            $letra = $f['letra'] ?? '';
            if ($letra === '') {
                continue;
            }
            $columnas[] = $letra;
        }
        $columnas = array_values(array_unique($columnas));
        usort($columnas, fn(string $a, string $b) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($a) <=> \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($b));

        $columnaConcepto = null;
        foreach ($columnas as $columna) {
            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $columna . $row);
            if ($cell === null) {
                continue;
            }
            $valorBruto = $cell['valor_bruto'] ?? null;
            if ($valorBruto === null || trim((string) $valorBruto) === '') {
                continue;
            }
            if (($cell['es_formula'] ?? false) === true) {
                continue;
            }

            $columnaConcepto = $this->pareceEtiquetaTotalMatrix((string) $valorBruto) ? $columna : null;
            break;
        }

        if ($columnaConcepto === null) {
            return false;
        }

        $tieneFormulaHaciaAtras = false;
        foreach ($columnas as $columna) {
            if ($columna === $columnaConcepto) {
                continue;
            }

            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $columna . $row);
            if ($cell === null) {
                continue;
            }

            $esFormula = ($cell['es_formula'] ?? false) === true;
            if (!$esFormula) {
                $esCapturableReal = ($cell['es_editable'] ?? false) === true
                    && ($cell['esta_bloqueada'] ?? false) !== true;
                if ($esCapturableReal) {
                    return false;
                }
                continue;
            }

            $formulaTexto = (string) ($cell['formula'] ?? '');
            if ($formulaTexto === '' || !preg_match_all('/[A-Z]{1,3}(\d+)/', $formulaTexto, $matches)) {
                return false;
            }

            $tieneReferenciaPosterior = false;
            foreach ($matches[1] as $filaReferenciada) {
                $fr = (int) $filaReferenciada;
                if ($fr > $row) {
                    $tieneReferenciaPosterior = true;
                }
                if ($fr < $row && $fr >= $sectionStartRow) {
                    $tieneFormulaHaciaAtras = true;
                }
            }

            if ($tieneReferenciaPosterior) {
                return false;
            }
        }

        return $tieneFormulaHaciaAtras;
    }

    /**
     * Fila TOTAL LIDER embebida dentro del rango activo de una seccion --
     * patron OPUESTO a isEmbeddedBackwardSubtotalRow() (hallazgo A09/G
     * filas 183 y 196, 2026-08-24, auditoria de los REQUIRES_INVESTIGATION
     * de Tanda 2B-6): la columna de concepto tiene texto propio que parece
     * etiqueta TOTAL, y toda celda con evidencia de cell_data entre las
     * columnas de la seccion es una formula que referencia EXCLUSIVAMENTE
     * filas POSTERIORES a $row (nunca la propia fila ni una anterior). Si
     * alguna celda tiene evidencia de ser capturable de verdad (editable,
     * no bloqueada), la fila se trata como dato real, no como total -- no
     * se excluye. Misma logica estructural que
     * RemParserService::isEmbeddedLeadingTotalRow() (mecanismo #6 del
     * pipeline de persistencia), portada aqui de forma independiente
     * porque esta clase nunca aplicaba ese mecanismo al construir
     * patrones de calibracion -- solo isEmbeddedBackwardSubtotalRow()
     * (mecanismo #12) estaba conectado en classifyRow(), dejando filas
     * TOTAL lider reales (que agregan hacia adelante) mezcladas como
     * 'data' dentro de un patron junto a filas heterogeneas.
     *
     * Publico desde 2026-08-24 (flujo structural_row_exclusion,
     * RuleTagMismatchResolutionCommand via PatternMigrationScanner): el
     * gate de exclusion estructural necesita verificar, fila por fila, que
     * cada fila que un patron dice "excluir" cumple REALMENTE este
     * mecanismo -- nunca confiar en que una fila ausente del conjunto vivo
     * sea automaticamente un TOTAL lider legitimo.
     */
    public function isEmbeddedLeadingTotalRow(string $sheet, string $section, int $row, array $sectionData): bool
    {
        if (!$this->cellDataStorage->hasCellData($sheet, $section)) {
            return false;
        }

        $sectionStartRow = (int) ($sectionData['filaInicioDatos'] ?? 0);
        if ($sectionStartRow <= 0) {
            return false;
        }

        $columnas = [];
        foreach ($sectionData['fields'] ?? [] as $f) {
            $letra = $f['letra'] ?? '';
            if ($letra === '') {
                continue;
            }
            $columnas[] = $letra;
        }
        $columnas = array_values(array_unique($columnas));
        usort($columnas, fn(string $a, string $b) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($a) <=> \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($b));

        // A diferencia de isEmbeddedBackwardSubtotalRow() (que asume que la
        // PRIMERA celda de texto plano ya es la etiqueta de concepto),
        // hallazgo A09/G filas 183/196: la columna de concepto real
        // ("Altas integrales...") vive ANTES que la columna con el
        // marcador "TOTAL" (encabezado multi-columna "PROGRAMA -
        // ACTIVIDAD" en A/B/C, con "TOTAL" recien en C) -- se recorren
        // TODAS las columnas de texto plano de la fila buscando una que
        // parezca etiqueta TOTAL, en vez de detenerse en la primera.
        $columnaConcepto = null;
        foreach ($columnas as $columna) {
            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $columna . $row);
            if ($cell === null) {
                continue;
            }
            $valorBruto = $cell['valor_bruto'] ?? null;
            if ($valorBruto === null || trim((string) $valorBruto) === '') {
                continue;
            }
            if (($cell['es_formula'] ?? false) === true) {
                continue;
            }

            if ($this->pareceEtiquetaTotalMatrix((string) $valorBruto)) {
                $columnaConcepto = $columna;
                break;
            }
        }

        if ($columnaConcepto === null) {
            return false;
        }

        $tieneAlgunaFormula = false;
        foreach ($columnas as $columna) {
            if ($columna === $columnaConcepto) {
                continue;
            }

            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $columna . $row);
            if ($cell === null) {
                continue;
            }

            $esFormula = ($cell['es_formula'] ?? false) === true;
            if (!$esFormula) {
                $esCapturableReal = ($cell['es_editable'] ?? false) === true
                    && ($cell['esta_bloqueada'] ?? false) !== true;
                if ($esCapturableReal) {
                    return false;
                }
                continue;
            }

            $formulaTexto = (string) ($cell['formula'] ?? '');
            if ($formulaTexto === '' || !preg_match_all('/[A-Z]{1,3}(\d+)/', $formulaTexto, $matches)) {
                return false;
            }

            // Hallazgo A09/G fila 183: D183=SUM(F183) y F183=SUM(AD183+...+AP183)
            // son subtotales HORIZONTALES de la propia fila (mismo patron ya
            // documentado en el mecanismo #8 de TOTAL final: "una referencia a
            // la propia fila es neutral") -- no descalifican la fila como
            // TOTAL lider, pero tampoco cuentan por si solas como evidencia de
            // agregacion hacia adelante. Solo una referencia a una fila
            // ESTRICTAMENTE posterior cuenta como evidencia; una referencia a
            // una fila ESTRICTAMENTE anterior si descalifica (mezclaria
            // semantica con el mecanismo #12).
            $tieneReferenciaPosterior = false;
            foreach ($matches[1] as $filaReferenciada) {
                $fr = (int) $filaReferenciada;
                if ($fr < $row) {
                    return false;
                }
                if ($fr > $row) {
                    $tieneReferenciaPosterior = true;
                }
            }

            if ($tieneReferenciaPosterior) {
                $tieneAlgunaFormula = true;
            }
        }

        return $tieneAlgunaFormula;
    }

    private function pareceEtiquetaTotalMatrix(string $valor): bool
    {
        $texto = mb_strtoupper(trim($valor), 'UTF-8');

        return str_contains($texto, 'TOTAL') || str_contains($texto, 'AMBOS SEXOS');
    }

    // ─── Evidence ──────────────────────────────────────────────────

    private function getEvidenceDetail(array $evidenceRules, int $row, array $sectionData, array $columnIndex): array
    {
        $sheet = $this->getCurrentSheet();
        $section = $sectionData['codigo'] ?? 'A';

        $cellEvidence = $this->getCellLevelEvidence($sheet, $section, $row, $sectionData);
        if ($cellEvidence !== null) {
            return $cellEvidence;
        }

        $exactMatch = $this->findExactEvidence($evidenceRules, $row);
        if ($exactMatch) {
            $cols = $exactMatch['columnas_origen'];
            $dest = $exactMatch['columna_destino'];
            $destinoExacto = "{$dest}{$row}";
            $origenEfectivo = array_map(fn($c) => preg_replace('/\d+$/', (string) $row, $c), $cols);
            $formula = implode(' + ', $origenEfectivo) . " = {$destinoExacto}";

            return [
                'destino_exacto' => $destinoExacto,
                'origen_efectivo' => $origenEfectivo,
                'origen_columnas' => array_map(fn($c) => preg_replace('/\d+$/', '', $c), $cols),
                'formula_efectiva' => $formula,
                'formula_candidata' => $formula,
                'columna_destino' => $dest,
                'tipo_evidencia' => 'formula_xlsm',
            ];
        }

        $patternMatch = $this->findPatternEvidence($evidenceRules);
        if ($patternMatch) {
            $cols = $patternMatch['columnas_origen'];
            $dest = $patternMatch['columna_destino'];
            $destinoExacto = "{$dest}{$row}";
            $origenEfectivo = array_map(fn($c) => preg_replace('/\d+/', (string) $row, $c), $cols);
            $formula = implode(' + ', $origenEfectivo) . " = {$destinoExacto}";

            return [
                'destino_exacto' => $destinoExacto,
                'origen_efectivo' => $origenEfectivo,
                'origen_columnas' => array_map(fn($c) => preg_replace('/\d+$/', '', $c), $cols),
                'formula_efectiva' => $formula,
                'formula_candidata' => $formula,
                'columna_destino' => $dest,
                'tipo_evidencia' => 'structure_pattern',
            ];
        }

        $candidate = $this->inferCandidateFormula($row, $sectionData, $columnIndex);
        if ($candidate) {
            return array_merge($candidate, ['tipo_evidencia' => 'inferred_candidate']);
        }

        return [
            'destino_exacto' => null,
            'origen_efectivo' => [],
            'origen_columnas' => [],
            'formula_efectiva' => null,
            'formula_candidata' => null,
            'columna_destino' => null,
            'tipo_evidencia' => 'no_evidence',
        ];
    }

    private function getCellLevelEvidence(string $sheet, string $section, int $row, array $sectionData): ?array
    {
        if (!$this->cellDataStorage->hasCellData($sheet, $section)) {
            return null;
        }

        $totalCol = null;
        foreach ($sectionData['fields'] ?? [] as $f) {
            if ($f['esTotal'] ?? false) {
                $totalCol = $f['letra'] ?? null;
                break;
            }
        }

        if (!$totalCol) return null;

        $totalCell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, "{$totalCol}{$row}");
        if (!$totalCell || empty($totalCell['es_formula'])) {
            return null;
        }

        $dependencies = $totalCell['dependencias'] ?? [];
        $formulaReal = $totalCell['formula'] ?? '';
        $colDestino = $totalCell['columna'] ?? $totalCol;

        $origenEfectivo = [];
        $origenColumnas = [];

        foreach ($dependencies as $dep) {
            if (preg_match('/^([A-Z]+)(\d+)$/', strtoupper($dep), $m)) {
                $origenEfectivo[] = $dep;
                $letter = $m[1];
                if (!in_array($letter, $origenColumnas, true)) {
                    $origenColumnas[] = $letter;
                }
            }
        }

        $destinoExacto = "{$colDestino}{$row}";
        $formulaEfectiva = !empty($origenEfectivo)
            ? implode(' + ', $origenEfectivo) . " = {$destinoExacto}"
            : $formulaReal;

        return [
            'destino_exacto' => $destinoExacto,
            'origen_efectivo' => $origenEfectivo,
            'origen_columnas' => $origenColumnas,
            'formula_efectiva' => $formulaReal ?: $formulaEfectiva,
            'formula_candidata' => $formulaEfectiva,
            'columna_destino' => $colDestino,
            'tipo_evidencia' => 'cell_level_xlsm',
            'cell_data' => [
                'tipo_celda' => $totalCell['tipo_celda'] ?? null,
                'zona' => $totalCell['zona'] ?? null,
                'color_fondo' => $totalCell['color_fondo'] ?? null,
                'es_editable' => $totalCell['es_editable'] ?? null,
                'esta_bloqueada' => $totalCell['esta_bloqueada'] ?? null,
                'validacion_datos' => $totalCell['validacion_datos'] ?? null,
                'es_combinada' => $totalCell['es_combinada'] ?? false,
                'rango_combinado' => $totalCell['rango_combinado'] ?? null,
            ],
        ];
    }

    private function findExactEvidence(array $evidenceRules, int $row): ?array
    {
        foreach ($evidenceRules as $ev) {
            $range = $ev['rango_filas'] ?? '';
            if (preg_match('/(\d+)/', $range, $m)) {
                if ((int) $m[1] === $row) return $ev;
            }
        }
        return null;
    }

    private function findPatternEvidence(array $evidenceRules): ?array
    {
        foreach ($evidenceRules as $ev) {
            if (!empty($ev['columnas_origen'])) return $ev;
        }
        return null;
    }

    private function inferCandidateFormula(int $row, array $sectionData, array $columnIndex): ?array
    {
        $totalCols = $this->getTotalColumns($sectionData);
        $numericCols = $this->getStructureOriginColumns($sectionData, $totalCols);

        if (empty($totalCols) || empty($numericCols)) return null;

        $totalCol = $totalCols[0];
        $destinoExacto = "{$totalCol}{$row}";
        $origenEfectivo = array_map(fn($c) => "{$c}{$row}", $numericCols);
        $formula = implode(' + ', $origenEfectivo) . " = {$destinoExacto}";

        return [
            'destino_exacto' => $destinoExacto,
            'origen_efectivo' => $origenEfectivo,
            'origen_columnas' => $numericCols,
            'formula_efectiva' => null,
            'formula_candidata' => $formula,
            'columna_destino' => $totalCol,
            'columnas_destino' => $totalCols,
        ];
    }

    // ─── Aggregated rules ─────────────────────────────────────────

    private function buildAggregatedRules(array $rules, array $evidenceRules, array $sectionData, array $certStatus): array
    {
        $result = [];
        foreach ($rules as $card) {
            $rangeStr = $card['rango_filas'] ?? '';
            $ruleRows = $this->parseRowRange($rangeStr);

            $allSectionRows = range(
                $sectionData['filaInicioDatos'] ?? 10,
                $sectionData['filaFinDatos'] ?? 32
            );

            $knownHeaders = $this->getKnownHeaderRows(
                $card['hoja'] ?? 'A01',
                $card['seccion'] ?? 'A'
            );

            $candidateRows = [];
            $excludedRows = [];

            foreach ($allSectionRows as $r) {
                if (in_array($r, $knownHeaders)) continue;
                if ($this->isRowDisabled($sectionData, $r)) continue;

                if (in_array($r, $ruleRows)) {
                    // direct row — already tracked in filas_directas
                    continue;
                }

                $candidateRows[] = $r;
            }

            $result[] = [
                'rule_key' => $card['rule_key'],
                'rule_type' => $card['rule_type'],
                'patron_general' => $card['formula_interpretada'],
                'columna_destino' => $card['columna_destino'],
                'columnas_origen' => $card['columnas_origen'],
                'rango_filas' => $rangeStr,
                'filas_directas' => $ruleRows,
                'filas_candidatas' => $candidateRows,
                'filas_excluidas' => $excludedRows,
                'total_filas_directas' => count($ruleRows),
                'total_filas_candidatas' => count($candidateRows),
                'total_excluidas' => count($excludedRows),
                'estado_tecnico' => $certStatus[$card['rule_key']]['estado'] ?? 'Pendiente',
            ];
        }

        return $result;
    }

    private function findAggregatedMatch(array $aggregatedRules, int $row, string $rowType, array $evidenceDetail, array $sectionData): array
    {
        if (in_array($rowType, ['header', 'spacer', 'not_applicable'])) {
            return [
                'rule_key' => null,
                'rule_type' => null,
                'es_excepcion' => false,
                'excepcion_razon' => null,
            ];
        }

        foreach ($aggregatedRules as $ag) {
            $isDirect = in_array($row, $ag['filas_directas']);
            $isCandidate = in_array($row, $ag['filas_candidatas']);

            if ($isDirect) {
                return [
                    'rule_key' => $ag['rule_key'],
                    'rule_type' => $ag['rule_type'],
                    'es_excepcion' => false,
                    'excepcion_razon' => null,
                ];
            }

            if ($isCandidate) {
                $esExcepcion = false;
                $razon = null;

                $detectedCols = $evidenceDetail['origen_columnas'] ?? [];
                $ruleCols = $ag['columnas_origen'] ?? [];
                $normalizedRuleCols = array_map(fn($c) => preg_replace('/\d+$/', '', $c), $ruleCols);
                $summedLetters = array_unique($normalizedRuleCols);

                if (!empty($detectedCols) && !empty($summedLetters)) {
                    $normalizedDetected = array_map(fn($c) => preg_replace('/\d+$/', '', $c), $detectedCols);
                    $normalizedDetected = array_unique($normalizedDetected);
                    sort($normalizedDetected);
                    sort($summedLetters);
                    if ($normalizedDetected !== $summedLetters) {
                        $esExcepcion = true;
                        $razon = 'Rango de columnas distinto: detectado=' . implode(',', $normalizedDetected)
                            . ' vs esperado=' . implode(',', $summedLetters);
                    }
                }

                return [
                    'rule_key' => $ag['rule_key'],
                    'rule_type' => $ag['rule_type'],
                    'es_excepcion' => $esExcepcion,
                    'excepcion_razon' => $razon,
                ];
            }
        }

        return [
            'rule_key' => null,
            'rule_type' => null,
            'es_excepcion' => false,
            'excepcion_razon' => null,
        ];
    }

    private function isRowDisabled(array $sectionData, int $row): bool
    {
        return false;
    }

    // ─── Coverage ──────────────────────────────────────────────────

    private function determineCoverage(
        string $rowType,
        ?array $directRule,
        array $aggregatedMatch,
        array $evidenceDetail,
        array $sectionData,
    ): string {
        if (in_array($rowType, ['header', 'spacer', 'not_applicable'])) {
            return 'not_applicable';
        }

        if ($directRule && $evidenceDetail['tipo_evidencia'] === 'formula_xlsm') {
            $ruleFormula = $directRule['formula_interpretada'];
            $ruleNormalized = $this->normalizeFormula($ruleFormula);
            $detectedNormalized = $this->normalizeFormula($evidenceDetail['formula_efectiva'] ?? '');
            $colsNormalized = $this->normalizeColumnList($evidenceDetail['origen_columnas'] ?? []);
            $ruleColsNormalized = $this->normalizeColumnList($directRule['columnas_origen'] ?? []);

            $isFullMatch = $ruleNormalized === $detectedNormalized
                || $colsNormalized === $ruleColsNormalized;

            return $isFullMatch ? 'direct_rule' : 'partial_exception';
        }

        if ($aggregatedMatch['rule_key'] && !$aggregatedMatch['es_excepcion']) {
            if ($evidenceDetail['tipo_evidencia'] === 'structure_pattern') {
                return 'aggregated_rule_candidate';
            }
            if ($evidenceDetail['tipo_evidencia'] === 'inferred_candidate') {
                return 'aggregated_rule_candidate';
            }
            if ($evidenceDetail['tipo_evidencia'] === 'no_evidence') {
                return 'no_formula';
            }
            return 'aggregated_rule_candidate';
        }

        if ($aggregatedMatch['es_excepcion']) {
            return 'exception';
        }

        if ($evidenceDetail['tipo_evidencia'] === 'no_evidence') {
            return 'no_formula';
        }

        return 'no_formula';
    }

    private function normalizeFormula(?string $formula): string
    {
        if (!$formula) return '';
        $f = strtoupper($formula);
        $f = preg_replace('/\d+/', 'N', $f);
        $f = preg_replace('/\s+/', '', $f);
        return $f;
    }

    private function normalizeColumnList(array $cols): string
    {
        $letters = array_map(fn($c) => preg_replace('/\d+$/', '', strtoupper($c)), $cols);
        $letters = array_unique($letters);
        sort($letters);
        return implode(',', $letters);
    }

    private function determineTecnicalStatus(string $rowType, ?array $directRule, array $certStatus): string
    {
        if (in_array($rowType, ['header', 'spacer', 'not_applicable'])) return 'No aplica';

        if ($directRule) {
            return $certStatus[$directRule['rule_key']]['estado'] ?? 'Pendiente';
        }

        return 'Sin regla directa';
    }

    // ─── Question generation ───────────────────────────────────────

    private function generateQuestions(array $rows, array $sectionData): array
    {
        $questions = [];
        $seenFormulaPatterns = [];
        $firstDataRow = null;

        foreach ($rows as $r) {
            if (in_array($r['row_type'], ['header', 'spacer', 'not_applicable', 'special'])) continue;
            if ($firstDataRow === null) $firstDataRow = $r['row'];
        }

        // — Aggregate-level questions (one per section)
        $dataRows = array_filter($rows, fn($r) => $r['row_type'] === 'data');
        $dataRowNums = array_map(fn($r) => $r['row'], $dataRows);
        if (empty($dataRowNums)) {
            return $questions;
        }

        $questions[] = [
            'row' => null,
            'type' => 'aggregate_pattern',
            'question' => 'Las filas ' . min($dataRowNums) . '–' . max($dataRowNums)
                . ' comparten el patrón estructural C{X} = SUMA(F{X}:N{X}). '
                . '¿Todas las filas operativas usan este mismo patrón o existen excepciones por rango etario o tipo de control?',
            'suggests_block' => false,
        ];

        // — Questions about columns outside the sum range (one per section)
        $firstData = $rows[array_key_first(array_filter($rows, fn($r) => $r['row_type'] === 'data'))] ?? null;
        if ($firstData) {
            $outsideCols = $firstData['columnas_fuera_suma'] ?? [];
            $outsideLabels = [];
            foreach ($outsideCols as $c) {
                foreach ($sectionData['fields'] ?? [] as $f) {
                    if (($f['letra'] ?? '') === $c) {
                        $outsideLabels[] = "{$c} ({$f['label']})";
                        break;
                    }
                }
            }
            if (!empty($outsideCols)) {
                $questions[] = [
                    'row' => null,
                    'type' => 'unsummed_columns',
                    'question' => 'Las columnas ' . implode(', ', array_slice($outsideLabels, 0, 10))
                        . ' están fuera del rango de suma del TOTAL (C).'
                        . ' ¿Deben tener su propio total o son columnas independientes por fila?',
                    'suggests_block' => false,
                ];
            }
        }

        // — Row-level exception questions (only for actual exceptions)
        foreach ($rows as $r) {
            if (in_array($r['row_type'], ['header', 'spacer', 'not_applicable', 'special'])) continue;
            $fila = $r['row'];

            if ($r['cobertura'] === 'exception') {
                $questions[] = [
                    'row' => $fila,
                    'type' => 'formula_exception',
                    'question' => "¿En la fila {$fila} el rango de columnas a sumar es diferente al patrón general? "
                        . "Detectado: " . implode(', ', $r['origen_columnas'] ?: ['ninguno'])
                        . ". Debe confirmarse el rango correcto.",
                    'suggests_block' => false,
                ];
            }

            if ($r['cobertura'] === 'no_formula') {
                $patternKey = 'no_formula';
                if (!isset($seenFormulaPatterns[$patternKey])) {
                    $seenFormulaPatterns[$patternKey] = true;
                    $questions[] = [
                        'row' => $fila,
                        'type' => 'missing_formula',
                        'question' => "La fila {$fila} y otras sin fórmula directa en XLSM. "
                            . "¿Debe aplicarse el patrón general (" . ($r['formula_candidata'] ?? 'C = suma de columnas habilitadas') . ")?",
                        'suggests_block' => false,
                    ];
                }
            }

            // — Functional questions: one per row type, not per row
            if ($r['funcional_por_fila'] === null && $r['cobertura'] !== 'not_applicable') {
                $patternKey = 'func_pending_' . $r['cobertura'];
                if (!isset($seenFormulaPatterns[$patternKey])) {
                    $seenFormulaPatterns[$patternKey] = true;
                    $questions[] = [
                        'row' => null,
                        'type' => 'functional_pending',
                        'question' => "Filas con cobertura '{$r['cobertura']}' no tienen restricción funcional definida. "
                            . "¿Pueden quedar vacías en ciertos establecimientos? "
                            . "¿Debe registrarse 0 o puede quedar vacío si no existen atenciones? "
                            . "¿La regla debe bloquear el archivo o generar solo advertencia?",
                        'suggests_block' => false,
                    ];
                }
            }
        }

        // — Exception summary question
        $exceptions = array_filter($rows, fn($r) => $r['es_excepcion']);
        if (!empty($exceptions)) {
            $excRows = implode(', ', array_map(fn($r) => $r['row'], $exceptions));
            $questions[] = [
                'row' => null,
                'type' => 'exception_summary',
                'question' => "Existen excepciones de fórmula en filas: {$excRows}. "
                    . "¿Son diferencias intencionales del formulario o requieren validación adicional?",
                'suggests_block' => false,
            ];
        }

        return $questions;
    }

    // ─── Helpers ───────────────────────────────────────────────────

    private function getColumnsOutsideSumRange(array $sectionData, array $summedColumns): array
    {
        $summedLetters = array_map(fn($c) => strtoupper(preg_replace('/\d+$/', '', $c)), $summedColumns);
        $outside = [];
        foreach ($sectionData['fields'] ?? [] as $f) {
            $letra = strtoupper($f['letra'] ?? '');
            if (in_array($letra, $this->currentLabelColumns)) continue;
            if ($f['esControlOculto'] ?? false) continue;
            if ($f['esTotal'] ?? false) continue;
            if (in_array($letra, $summedLetters)) continue;
            $outside[] = $letra;
        }
        return $outside;
    }

    private function parseRowRange(?string $rangeStr): array
    {
        if (!$rangeStr) return [];
        if (preg_match('/Fila[s]?\s+(\d+)[–\-](\d+)/', $rangeStr, $m)) {
            return range((int) $m[1], (int) $m[2]);
        }
        if (preg_match('/(\d+)/', $rangeStr, $m)) {
            return [(int) $m[1]];
        }
        return [];
    }

    private function findDirectRule(array $rules, int $row): ?array
    {
        foreach ($rules as $card) {
            $rowRange = $this->parseRowRange($card['rango_filas'] ?? '');
            if (in_array($row, $rowRange)) return $card;
        }
        return null;
    }

    private function getEnabledColumns(array $sectionData): array
    {
        $cols = [];
        foreach ($sectionData['fields'] ?? [] as $f) {
            $letra = $f['letra'] ?? '';
            $label = $f['label'] ?? '';
            $blocked = $f['esControlOculto'] ?? false;
            $isTotal = $f['esTotal'] ?? false;
            $cols[] = [
                'letra' => $letra,
                'label' => $label,
                'es_total' => $isTotal,
                'es_bloqueada' => $blocked,
            ];
        }
        return $cols;
    }

    private function emptyMatrix(string $sheet, string $section, string $status, array $warnings): array
    {
        return [
            'section' => [
                'codigo' => $section,
                'titulo' => '',
                'sheet' => $sheet,
                'status' => $status,
                'fila_header' => null,
                'fila_inicio' => null,
                'fila_fin' => null,
                'total_filas_fisicas' => 0,
                'total_campos' => 0,
            ],
            'rows' => [],
            'summary' => [
                'total_filas_fisicas' => 0,
                'total_filas_datos' => 0,
                'filas_con_regla_directa' => 0,
                'filas_candidatas' => 0,
                'filas_sin_formula' => 0,
                'filas_no_aplican' => 0,
                'excepciones' => 0,
            ],
            'columnas' => [],
            'aggregated_rules' => [],
            'patterns' => [],
            'questions' => [],
            'header_labels' => [],
            'warnings' => $warnings,
        ];
    }

    private function buildSummary(array $rows): array
    {
        $totalFisicas = count($rows);
        $totalData = 0;
        $totalHeaders = 0;
        $totalNoAplica = 0;
        $totalSubtotales = 0;
        $totalSpacers = 0;

        $cubiertasDirectas = 0;
        $candidatasAgregadas = 0;
        $excepciones = 0;
        $sinFormula = 0;
        $noAplica = 0;

        $certificadas = 0;
        $funcionalesValidadas = 0;
        $pendientesFuncionales = 0;

        foreach ($rows as $r) {
            match ($r['row_type']) {
                'header' => $totalHeaders++,
                'spacer' => $totalSpacers++,
                'not_applicable' => $totalNoAplica++,
                'total', 'special' => $totalSubtotales++,
                default => $totalData++,
            };

            match ($r['cobertura']) {
                'direct_rule' => $cubiertasDirectas++,
                'aggregated_rule_candidate' => $candidatasAgregadas++,
                'partial_exception', 'exception' => $excepciones++,
                'no_formula' => $sinFormula++,
                'not_applicable' => $noAplica++,
                default => $sinFormula++,
            };

            if ($r['estado_tecnico'] === 'Certificada técnicamente') $certificadas++;
            $rowFuncStatus = $r['funcional_por_fila']['status'] ?? '';
            $ruleFuncStatus = $r['funcional_por_regla']['status'] ?? '';
            $esValidadaFuncionalmente = in_array($rowFuncStatus, ['validada', 'aprobada'])
                || in_array($ruleFuncStatus, ['validada', 'aprobada']);
            if ($esValidadaFuncionalmente) {
                $funcionalesValidadas++;
            }
            if (!$esValidadaFuncionalmente
                && !in_array($r['row_type'], ['header', 'spacer', 'not_applicable', 'special'])) {
                $pendientesFuncionales++;
            }
        }

        return [
            'total_filas_fisicas' => $totalFisicas,
            'total_filas_datos' => $totalData,
            'total_headers' => $totalHeaders,
            'total_subtotales' => $totalSubtotales,
            'total_spacers' => $totalSpacers,
            'total_no_aplica' => $totalNoAplica,
            'cubiertas_directas' => $cubiertasDirectas,
            'candidatas_agregadas' => $candidatasAgregadas,
            'excepciones' => $excepciones,
            'sin_formula' => $sinFormula,
            'certificadas_tecnicamente' => $certificadas,
            'validadas_funcionalmente' => $funcionalesValidadas,
            'pendientes_definicion_funcional' => $pendientesFuncionales,
        ];
    }

    private function buildColumnIndex(array $sectionData): array
    {
        $cols = [];
        foreach ($sectionData['fields'] ?? [] as $f) {
            $cols[] = [
                'letra' => $f['letra'] ?? '',
                'label' => $f['label'] ?? '',
                'es_total' => $f['esTotal'] ?? false,
                'es_control_oculto' => $f['esControlOculto'] ?? false,
            ];
        }
        return $cols;
    }

    // ─── Structure ─────────────────────────────────────────────────

    private function findSection(array $est, string $sheet, string $section): ?array
    {
        foreach ($est['forms'] ?? [] as $form) {
            if (!$this->formMatchesSheet($form, $sheet)) continue;
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtolower(trim($sec['codigo'] ?? '')) === strtolower(trim($section))) {
                    return $this->normalizeSectionData($sec);
                }
            }
        }
        return null;
    }

    private function extractEvidenceRules(array $est, string $sheet, string $section): array
    {
        $found = [];
        foreach ($est['forms'] ?? [] as $form) {
            if (!$this->formMatchesSheet($form, $sheet)) continue;
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtolower(trim($sec['codigo'] ?? '')) !== strtolower(trim($section))) continue;
                foreach ($sec['fields'] ?? [] as $field) {
                    $regla = $field['reglaDetectada'] ?? null;
                    if (!$regla || !is_array($regla)) continue;
                    $found[] = [
                        'columna' => $field['letra'] ?? '',
                        'tipo' => $regla['tipo'] ?? '',
                        'columnas_origen' => $regla['columnasOrigen'] ?? [],
                        'columna_destino' => $regla['columnaDestino'] ?? $field['letra'] ?? '',
                        'rango_filas' => $regla['rangoFilas'] ?? null,
                        'es_total' => $field['esTotal'] ?? false,
                        'es_control_oculto' => $field['esControlOculto'] ?? false,
                    ];
                }
            }
        }
        return $found;
    }

    private function getActiveStructure(): ?RemTemplateStructure
    {
        return RemTemplateStructure::where('anio', 2026)
            ->where('serie', 'A')
            ->where('status', 'active')
            ->first();
    }

    private function parseEstructura(RemTemplateStructure $structure): ?array
    {
        if ($this->structureData !== null) return $this->structureData;
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        if (!is_array($est) || !isset($est['forms']) || !is_array($est['forms'])) {
            return null;
        }
        $this->structureData = $est;
        return $est;
    }

    private function formMatchesSheet(array $form, string $sheet): bool
    {
        $formSheet = $form['sheetName'] ?? $form['sheet'] ?? null;

        return strtoupper((string) $formSheet) === strtoupper($sheet);
    }

    private function normalizeSectionData(array $section): array
    {
        if (!isset($section['filaInicioDatos']) && isset($section['fila_inicio'])) {
            $section['filaInicioDatos'] = $section['fila_inicio'];
        }

        if (!isset($section['filaFinDatos']) && isset($section['fila_fin'])) {
            $section['filaFinDatos'] = $section['fila_fin'];
        }

        if (!isset($section['filaHeader'])) {
            $section['filaHeader'] = isset($section['filaInicioDatos'])
                ? max(1, (int) $section['filaInicioDatos'] - 1)
                : 9;
        }

        $section['fields'] = $section['fields'] ?? [];

        return $section;
    }

    private function getCurrentSheet(): string
    {
        return $this->currentSheet;
    }

    private function getTotalColumns(array $sectionData): array
    {
        $columns = [];
        foreach ($sectionData['fields'] ?? [] as $field) {
            if ($field['esTotal'] ?? false) {
                $columns[] = strtoupper((string) ($field['letra'] ?? ''));
            }
        }
        return array_values(array_filter($columns));
    }

    private function getStructureOriginColumns(array $sectionData, array $totalColumns): array
    {
        $totalLookup = array_flip(array_map('strtoupper', $totalColumns));
        $columns = [];
        foreach ($sectionData['fields'] ?? [] as $field) {
            $letter = strtoupper((string) ($field['letra'] ?? ''));
            if ($letter === '') continue;
            if (in_array($letter, $this->currentLabelColumns, true)) continue;
            if (isset($totalLookup[$letter])) continue;
            if ($field['esControlOculto'] ?? false) continue;
            $columns[] = $letter;
        }
        return $columns;
    }

    private function isNonCalibrableCellHeaderRow(array $rowCells, array $totalColumns): bool
    {
        if (empty($rowCells)) return false;

        $hasFormula = false;
        $hasEditableFunctionalCell = false;
        foreach ($rowCells as $column => $cell) {
            if (in_array(strtoupper((string) $column), $this->currentLabelColumns, true)) {
                continue;
            }

            if ($cell['es_formula'] ?? false) {
                $hasFormula = true;
            }
            if (!($cell['esta_bloqueada'] ?? true)) {
                $hasEditableFunctionalCell = true;
            }
        }

        return !$hasFormula && !$hasEditableFunctionalCell;
    }

    private function extractDependencyColumns(array $dependencies): array
    {
        $columns = [];
        foreach ($dependencies as $dependency) {
            if (preg_match('/^([A-Z]+)\d+$/', strtoupper((string) $dependency), $matches)) {
                $columns[] = $matches[1];
            }
        }
        return array_values(array_unique($columns));
    }

    private function normalizeFormulaTemplate(string $formula, int $row): string
    {
        if ($formula === '') return '';
        return preg_replace('/(?<=[A-Z])' . preg_quote((string) $row, '/') . '\b/', '{fila}', strtoupper($formula)) ?? strtoupper($formula);
    }

    private function buildFormulaTemplateFromColumns(string $totalColumn, array $originColumns): string
    {
        if (empty($originColumns)) return "{$totalColumn}{fila}";
        if (count($originColumns) === 1) {
            return "{$originColumns[0]}{fila}";
        }
        return "SUM({$originColumns[0]}{fila}:{$originColumns[count($originColumns) - 1]}{fila})";
    }

    private function buildEditabilitySignature(array $sectionData, array $rowCells): string
    {
        $parts = [];
        foreach ($sectionData['fields'] ?? [] as $field) {
            $letter = strtoupper((string) ($field['letra'] ?? ''));
            if ($letter === '' || in_array($letter, $this->currentLabelColumns, true)) continue;

            $cell = $rowCells[$letter] ?? [];
            $isBlocked = array_key_exists('esta_bloqueada', $cell)
                ? (bool) $cell['esta_bloqueada']
                : (bool) ($field['esControlOculto'] ?? false);
            $parts[] = $letter . ':' . ($isBlocked ? 'B' : 'E');
        }
        return implode('|', $parts);
    }

    private function describePatternName(array $formulaTemplates, string $primaryTotal, array $originColumns): string
    {
        if ($primaryTotal !== '' && isset($formulaTemplates[$primaryTotal])) {
            return "{$primaryTotal} = " . ltrim($formulaTemplates[$primaryTotal], '=');
        }
        if (!empty($originColumns)) {
            return 'Columnas ' . implode(', ', $originColumns);
        }
        return 'Sin fórmula detectada';
    }

    private function getFunctionalOriginDescription(array $columnas): string
    {
        $normalized = array_map(fn($c) => preg_replace('/\d+$/', '', strtoupper($c)), $columnas);
        $normalized = array_unique($normalized);
        sort($normalized);

        foreach (self::COL_RANGE_DESCRIPTIONS as $desc) {
            $expected = $desc['columnas'];
            sort($expected);
            if ($normalized === $expected) {
                return $desc['descripcion'] . ' (' . implode(':', [$normalized[0], $normalized[count($normalized) - 1]]) . ')';
            }
        }

        if (count($normalized) === 1) {
            return "Columna {$normalized[0]}";
        }

        return 'Columnas ' . implode(', ', $normalized);
    }
}
