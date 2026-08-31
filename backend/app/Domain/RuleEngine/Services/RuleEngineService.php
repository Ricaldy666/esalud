<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
use App\Domain\REM\Models\RemTechnicalTotal;
use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\RuleEvaluatorInterface;
use App\Domain\RuleEngine\Evaluators\RuleEvaluationResult;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use App\Support\MemoryProbe;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RuleEngineService
{
    private array $evaluators = [];

    public function __construct(
        private FunctionalRuleService $functionalRuleService,
        private ?CellDataStorageService $cellDataStorage = null,
    ) {
        $this->cellDataStorage = $cellDataStorage ?? new CellDataStorageService();
    }

    public function registerEvaluator(RuleEvaluatorInterface $evaluator): void
    {
        $this->evaluators[] = $evaluator;
    }

    public function resolveRules(int $structureId, ?RemTemplateStructure $structure = null): Collection
    {
        if ($structure === null) {
            $structure = RemTemplateStructure::withTrashed()->findOrFail($structureId);
        }

        $ruleIds = RuleBinding::where('active', true)
            ->where(function ($q) use ($structureId, $structure) {
                $q->where(function ($b) use ($structureId) {
                    $b->where('bindable_type', 'structure')
                      ->where('bindable_id', $structureId);
                })->orWhere(function ($b) use ($structure) {
                    $b->where('bindable_type', 'serie')
                      ->where('serie', $structure->serie)
                      ->where('anio', $structure->anio);
                })->orWhere('bindable_type', 'global');
            })
            ->pluck('rule_id');

        if ($ruleIds->isEmpty()) {
            return collect();
        }

        return Rule::whereIn('id', $ruleIds)
            ->where('status', 'active')
            ->get();
    }

    public function execute(int $uploadId, int $structureId, bool $write = false, string $triggeredBy = 'cli', bool $writeResults = true): array
    {
        $structure = RemTemplateStructure::withTrashed()->findOrFail($structureId);
        $rules = $this->resolveRules($structureId, $structure);

        if ($rules->isEmpty()) {
            return [
                'upload_id' => $uploadId,
                'structure_id' => $structureId,
                'total_rules' => 0,
                'executed' => 0,
                'skipped' => 0,
                'passed' => 0,
                'failed' => 0,
                'details' => [],
            ];
        }

        MemoryProbe::log('rule_engine.antes_carga_rem_data', ['upload_id' => $uploadId, 'rules_count' => $rules->count()]);

        $remData = RemData::where('rem_upload_id', $uploadId)->get();
        $grouped = $remData->groupBy('section');

        MemoryProbe::log('rule_engine.despues_carga_rem_data', [
            'upload_id' => $uploadId,
            'rem_data_rows' => $remData->count(),
        ]);

        // Load upload with health center for establishment filtering
        $upload = RemUpload::with('healthCenter')->findOrFail($uploadId);
        $healthCenter = $upload->healthCenter;

        if (!$healthCenter) {
            Log::warning("RuleEngine: Upload {$uploadId} no tiene establecimiento asignado. No se aplicarán filtros por establecimiento.");
        }

        $healthCenterName = $healthCenter?->name ?? null;
        $healthCenterType = $healthCenter?->type ?? null;

        $executionId = $write ? Str::uuid()->toString() : null;

        // Preload functional rules keyed by sheet_section for engine consumption
        $functionalBySection = [];
        foreach ($rules as $rule) {
            $sheet = $rule->config['sheet'] ?? null;
            $section = $rule->config['section'] ?? null;
            $sectionKey = $sheet ? strtolower($sheet) . '_' . strtolower($section ?? '') : '';
            if ($sectionKey && !isset($functionalBySection[$sectionKey])) {
                $functionalBySection[$sectionKey] = $this->functionalRuleService
                    ->getFunctionalRulesForEngine($sheet ?? '', $section ?? '');
            }
        }

        Log::info('[RuleEngine] Execute started', [
            'upload_id' => $uploadId,
            'structure_id' => $structureId,
            'rules_count' => $rules->count(),
            'sections_found' => array_keys($functionalBySection),
            'functional_rules_loaded' => array_map(function ($fr) {
                return array_map(fn($r) => $r['empty_behavior'] ?? '?', $fr);
            }, $functionalBySection),
        ]);

        MemoryProbe::log('rule_engine.despues_preload_functional_rules', [
            'upload_id' => $uploadId,
            'secciones_precargadas' => count($functionalBySection),
        ]);

        $results = [];
        $cellMetadataCache = [];
        $sectionBoundsCache = [];
        $rulesProcesadas = 0;

        foreach ($rules as $rule) {
            $config = $this->normalizeConfig($rule->config);
            $config['_rule_key'] = $rule->rule_key;

            $evaluator = $this->findEvaluator($rule->rule_type);

            if ($evaluator === null) {
                continue;
            }

            $sheet = $config['sheet'] ?? null;
            $section = $config['section'] ?? '';
            $sectionKey = $sheet ? strtolower($sheet) . '_' . strtolower($section) : '';
            $functionalRules = $functionalBySection[$sectionKey] ?? [];

            $rows = $sheet ? ($grouped->get($sheet, collect())) : collect();

            $rowFrom = $config['row_from'] ?? null;
            $rowTo = $config['row_to'] ?? null;
            // total_row (convencion: row_to + 1) queda fuera de [row_from:row_to]
            // por diseno -- sin esta excepcion, el prefiltro lo descarta antes de
            // que SumEqualsEvaluator::evaluateVerticalAggregation() pueda
            // encontrarlo, incluso cuando la fila si esta persistida en rem_data
            // (confirmado empiricamente contra datos reales, ver auditoria Fase C).
            // Endurecido en Fase 3C (2026-08-12): la excepcion solo aplica cuando
            // la regla efectivamente va a pasar por evaluateVerticalAggregation()
            // -- sum_equals + scope row_range + patron vertical estricto (un solo
            // source_letter igual al target_column). Cualquier otro rule_type o
            // forma de sum_equals (per_row, o row_range con multiples columnas
            // origen) nunca ve total_row, aunque el campo este presente en config
            // -- evita que la fila TOTAL se cuele como fila de negocio en
            // evaluadores que no la reconocen (RequiredAndLeParentEvaluator,
            // SumEqualsEvaluator::evaluatePerRow()).
            $totalRow = $this->isVerticalSumEqualsRule($rule->rule_type, $config)
                ? (isset($config['total_row']) ? (int) $config['total_row'] : null)
                : null;

            // Fase 3C-3A/3C-3B (CLAUDE.md punto 17.21/17.22): 'source_rows'
            // -- lista explicita de filas componentes -- solo se consulta
            // para el mismo flujo vertical estricto que ya protege
            // 'total_row' arriba (isVerticalSumEqualsRule) -- nunca para
            // reglas horizontales/per_row, sin importar que el campo este
            // presente en config. La validacion ESTRICTA (array, enteros
            // positivos, sin duplicados, dentro de los limites vivos de la
            // seccion) vive en SumEqualsEvaluator::validateSourceRows() --
            // aqui solo se decide que filas dejar pasar por el prefiltro
            // para que el evaluador, ya con $rows completo, pueda validar y
            // usarlas (o rechazarlas explicitamente, nunca con fallback
            // silencioso a [row_from:row_to]).
            $sourceRowsForFilter = ($this->isVerticalSumEqualsRule($rule->rule_type, $config)
                && isset($config['source_rows'])
                && is_array($config['source_rows']))
                ? $config['source_rows']
                : null;

            if ($sourceRowsForFilter !== null && $sheet !== null) {
                $boundsCacheKey = strtolower($sheet) . '_' . strtolower($section ?? '');
                if (!array_key_exists($boundsCacheKey, $sectionBoundsCache)) {
                    $sectionBoundsCache[$boundsCacheKey] = $this->findSectionBounds($structure, $sheet, $section ?? '');
                }
                $config['_section_bounds'] = $sectionBoundsCache[$boundsCacheKey];
            }

            if ($rowFrom !== null) {
                $rows = $rows->filter(fn($rd) => (
                    ($rd->data['row_number'] >= $rowFrom && $rd->data['row_number'] <= $rowTo)
                    || ($totalRow !== null && (int) $rd->data['row_number'] === $totalRow)
                    || ($sourceRowsForFilter !== null && in_array((int) $rd->data['row_number'], $sourceRowsForFilter, true))
                ));
            }

            // Fase 3B -- PILOTO (CLAUDE.md punto 17.8): si la regla es un
            // sum_equals vertical genuino con total_row configurado, y esa
            // fila TOTAL no llego a persistirse en rem_data para ESTA carga
            // (excluida por mecanismo #6/#8/#11/#12, ver deuda tecnica #5),
            // se busca en rem_technical_totals (Fase 3A) -- nunca al reves.
            // rem_data sigue siendo la UNICA fuente para filas normales; esta
            // consulta solo se activa para esta combinacion exacta y nunca
            // sustituye una fila que ya vino de rem_data. Sin fallback
            // silencioso: si tampoco existe en rem_technical_totals (carga
            // historica anterior a Fase 3A, o la fila realmente no se pudo
            // calcular), $rows sigue sin la fila total y el evaluador cae en
            // su comportamiento ya existente y testeado (missing_total_row,
            // skip/fail_open) -- ningun valor se inventa.
            if ($totalRow !== null && !$rows->contains(fn($rd) => (int) ($rd->data['row_number'] ?? null) === $totalRow)) {
                $technicalRow = $this->findTechnicalTotalRow($uploadId, $sheet, $section, $totalRow);
                if ($technicalRow !== null) {
                    $rows->push($technicalRow);
                }
            }

            // ── Step 1: Filter functional rules to only include approved decisions ──
            // Statuses that DO NOT affect the engine: pendiente, propuesta, rechazada, inactiva
            // Statuses that DO affect the engine: aprobada, validada por Estadística
            $approvedStatuses = ['aprobada', 'validada por Estadística', 'validada_por_estadistica'];
            $activeFunctionalRules = array_filter($functionalRules, function ($fr) use ($approvedStatuses, $healthCenterName, $healthCenterType) {
                $status = $fr['status'] ?? '';
                if (!in_array($status, $approvedStatuses, true)) {
                    return false;
                }
                // Establishment filtering: if functional rule specifies establishment constraints,
                // only apply when the upload's establishment matches
                if (!empty($fr['applies_to_types']) && !in_array($healthCenterType, $fr['applies_to_types'], true)) {
                    return false;
                }
                if (!empty($fr['included_health_centers'])) {
                    if (!$healthCenterName || !in_array($healthCenterName, $fr['included_health_centers'], true)) {
                        return false;
                    }
                }
                if (!empty($fr['excluded_health_centers'])) {
                    if ($healthCenterName && in_array($healthCenterName, $fr['excluded_health_centers'], true)) {
                        return false;
                    }
                }
                return true;
            });

            // ── Step 2: Remove rows only when no_aplica is formally approved ──
            $rowsBeforeFilter = $rows->count();
            $functionalDecisionTrace = [];
            $rows = $rows->filter(function ($rd) use ($activeFunctionalRules, &$functionalDecisionTrace, $healthCenterName) {
                $rn = (int) ($rd->data['row_number'] ?? 0);
                $fr = $activeFunctionalRules[$rn] ?? null;
                if (!$fr) return true;
                if ($fr['empty_behavior'] === 'no_aplica') {
                    $functionalDecisionTrace[$rn] = [
                        'row' => $rn,
                        'decision' => 'excluded',
                        'reason' => 'no_aplica',
                        'empty_behavior' => $fr['empty_behavior'],
                        'functional_condition' => $fr['functional_condition'] ?? '',
                        'establishment' => $healthCenterName,
                    ];
                    return false;
                }
                return true;
            });
            $skippedByFunctional = $rowsBeforeFilter - $rows->count();

            $config['_functional_rules'] = $activeFunctionalRules;

            $rowNumbersWithFr = array_keys($activeFunctionalRules);
            Log::info('[RuleEngine] Evaluating rule', [
                'rule_key' => $rule->rule_key,
                'rule_type' => $rule->rule_type,
                'sheet' => $sheet,
                'section' => $section,
                'section_key' => $sectionKey,
                'rows_in_group' => $rows->count(),
                'functional_rows' => $rowNumbersWithFr,
                'active_functional_rules_summary' => array_map(fn($rn) => [
                    'row' => $rn,
                    'empty_behavior' => $activeFunctionalRules[$rn]['empty_behavior'] ?? '?',
                    'status' => $activeFunctionalRules[$rn]['status'] ?? '?',
                ], $rowNumbersWithFr),
            ]);

            $startTime = microtime(true);

            if ($rows->isEmpty()) {
                $result = new RuleEvaluationResult(
                    ruleKey: $rule->rule_key,
                    totalRows: 0,
                    failedRows: 0,
                    details: [],
                    skippedRows: $rowsBeforeFilter > 0 ? $rowsBeforeFilter : 0,
                    reason: $skippedByFunctional > 0 ? 'filtered_by_functional_rule' : 'Sin datos',
                );

                if ($write) {
                    $this->writeExecutionLog(
                        rule: $rule,
                        uploadId: $uploadId,
                        structureId: $structureId,
                        executionId: $executionId,
                        status: 'skipped',
                        result: $result,
                        executionMs: (int) ((microtime(true) - $startTime) * 1000),
                        triggeredBy: $triggeredBy,
                    );
                }

                $results[] = [
                    'rule_key' => $rule->rule_key,
                    'rule_type' => $rule->rule_type,
                    'sheet' => $sheet,
                    'status' => 'skipped',
                    'total_rows' => 0,
                    'failed_rows' => 0,
                    'skipped_rows' => $result->skippedRows,
                    'reason' => $result->reason,
                    'functional_rows_skipped' => $skippedByFunctional,
                    'functional_decisions' => $functionalDecisionTrace,
                    'health_center' => $healthCenterName,
                    'health_center_type' => $healthCenterType,
                ];
                continue;
            }

            $cacheKey = $sheet . '_' . $section;
            if ($sheet !== null && !isset($cellMetadataCache[$cacheKey])) {
                $cellMetadataCache[$cacheKey] = $this->cellDataStorage->loadCellData($sheet, $section);

                MemoryProbe::log('rule_engine.cell_metadata_cache_crecio', [
                    'upload_id' => $uploadId,
                    'rule_key' => $rule->rule_key,
                    'cache_key' => $cacheKey,
                    'celdas_en_seccion' => count($cellMetadataCache[$cacheKey]),
                    'secciones_en_cache' => count($cellMetadataCache),
                ]);
            }
            if ($sheet !== null && !empty($cellMetadataCache[$cacheKey])) {
                $config['_cell_metadata'] = $cellMetadataCache[$cacheKey];
            }

            $result = $evaluator->evaluate($config, $rows);
            $executionMs = (int) ((microtime(true) - $startTime) * 1000);

            $rulesProcesadas++;
            if ($rulesProcesadas % 50 === 0) {
                MemoryProbe::log('rule_engine.progreso_reglas', [
                    'upload_id' => $uploadId,
                    'reglas_procesadas' => $rulesProcesadas,
                    'reglas_totales' => $rules->count(),
                    'secciones_en_cell_metadata_cache' => count($cellMetadataCache),
                ]);
            }

            // ── Step 3: Add traceability to result details ──
            // Annotate each detail row with the functional decision that affected it
            $enrichedDetails = [];
            foreach ($result->details as $detail) {
                $rn = $detail['row_number'] ?? null;
                $fr = $rn ? ($activeFunctionalRules[$rn] ?? null) : null;
                if ($fr) {
                    $detail['functional_decision'] = [
                        'empty_behavior' => $fr['empty_behavior'] ?? null,
                        'status' => $fr['status'] ?? '',
                        'functional_condition' => $fr['functional_condition'] ?? '',
                        'establishment' => $healthCenterName,
                    ];
                }
                $enrichedDetails[] = $detail;
            }

            // Add functional-skipped count to result (reconstruct because properties are readonly)
            $result = new RuleEvaluationResult(
                ruleKey: $result->ruleKey,
                totalRows: $result->totalRows,
                failedRows: $result->failedRows,
                details: $enrichedDetails,
                skippedRows: $result->skippedRows + $skippedByFunctional,
                reason: $result->reason,
            );

            $status = $this->determineStatus($result);

            if ($write) {
                $this->writeExecutionLog(
                    rule: $rule,
                    uploadId: $uploadId,
                    structureId: $structureId,
                    executionId: $executionId,
                    status: $status,
                    result: $result,
                    executionMs: $executionMs,
                    triggeredBy: $triggeredBy,
                );

                if ($writeResults) {
                    $message = $this->buildMessage($rule, $result);

                    RemValidationResult::create([
                        'rule_id' => $rule->id,
                        'rem_upload_id' => $uploadId,
                        'rule_key' => $rule->rule_key,
                        'rule_type' => $rule->rule_type,
                        'severity' => $rule->severity,
                        'passed' => $result->failedRows === 0,
                        'message' => $message,
                        'context' => [
                            'sheet' => $sheet,
                            'rule_config' => $config,
                            'total_rows' => $result->totalRows,
                            'passed_rows' => max(0, $result->totalRows - $result->failedRows),
                            'failed_rows' => $result->failedRows,
                            'skipped_rows' => $result->skippedRows,
                            'functional_rows_skipped' => $skippedByFunctional,
                            'functional_decisions' => $functionalDecisionTrace,
                            'health_center' => $healthCenterName,
                            'health_center_type' => $healthCenterType,
                            'reason' => $result->reason,
                            'details' => $result->details,
                        ],
                    ]);
                }
            }

            $results[] = [
                'rule_key' => $rule->rule_key,
                'rule_type' => $rule->rule_type,
                'sheet' => $sheet,
                'status' => $status,
                'total_rows' => $result->totalRows,
                'failed_rows' => $result->failedRows,
                'skipped_rows' => $result->skippedRows,
                'reason' => $result->reason,
                'functional_rows_skipped' => $skippedByFunctional,
                'functional_decisions' => $functionalDecisionTrace,
                'health_center' => $healthCenterName,
                'health_center_type' => $healthCenterType,
            ];
        }

        $executed = array_filter($results, fn($r) => $r['status'] !== 'skipped');
        $passed = array_filter($executed, fn($r) => $r['status'] === 'passed');
        $failed = array_filter($executed, fn($r) => $r['status'] === 'failed');
        $skipped = array_filter($results, fn($r) => $r['status'] === 'skipped');

        MemoryProbe::log('rule_engine.fin', [
            'upload_id' => $uploadId,
            'reglas_procesadas' => $rulesProcesadas,
            'secciones_en_cell_metadata_cache' => count($cellMetadataCache),
        ]);

        return [
            'upload_id' => $uploadId,
            'structure_id' => $structureId,
            'health_center' => $healthCenterName,
            'health_center_type' => $healthCenterType,
            'total_rules' => count($results),
            'executed' => count($executed),
            'skipped' => count($skipped),
            'passed' => count($passed),
            'failed' => count($failed),
            'details' => $results,
        ];
    }

    private function findEvaluator(string $ruleType): ?RuleEvaluatorInterface
    {
        foreach ($this->evaluators as $evaluator) {
            if ($evaluator->supports($ruleType)) {
                return $evaluator;
            }
        }
        return null;
    }

    /**
     * Determina si $config, para $ruleType, efectivamente va a ser evaluada
     * por SumEqualsEvaluator::evaluateVerticalAggregation() -- misma
     * definicion de "patron vertical" ya usada en
     * RuleBindingReconciliationService::classifyRule(), replicada aqui
     * deliberadamente (sin extraerla a un servicio compartido) para no
     * acoplar el motor de ejecucion a la capa de reconciliacion de
     * bindings. No depende de hoja, seccion ni rule_id -- solo de la forma
     * del config ya normalizado.
     */
    private function isVerticalSumEqualsRule(string $ruleType, array $config): bool
    {
        if ($ruleType !== 'sum_equals') {
            return false;
        }

        if (($config['scope'] ?? null) !== 'row_range') {
            return false;
        }

        $sourceLetters = $config['source_letters'] ?? [];
        $targetColumn = $config['target_column'] ?? '';

        return count($sourceLetters) === 1
            && $targetColumn !== ''
            && strtoupper((string) $sourceLetters[0]) === strtoupper((string) $targetColumn);
    }

    /**
     * Fase 3C-3A/3C-3B (CLAUDE.md punto 17.21/17.22). Resuelve los limites
     * vivos (filaInicioDatos/filaFinDatos) de una seccion contra la
     * estructura activa recibida por execute() -- usado exclusivamente para
     * inyectar '_section_bounds' en config cuando una regla trae
     * 'source_rows', de forma que SumEqualsEvaluator::validateSourceRows()
     * pueda rechazar filas fuera de la seccion "cuando esa informacion este
     * disponible" (instruccion explicita). Devuelve null si la seccion no
     * se encuentra (el guard de limites simplemente no se aplica en ese
     * caso -- el resto de guards de source_rows si). Solo lectura.
     */
    private function findSectionBounds(RemTemplateStructure $structure, string $sheet, string $section): ?array
    {
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        if (!is_array($est)) {
            return null;
        }

        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== strtoupper($sheet)) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtoupper((string) ($sec['codigo'] ?? '')) === strtoupper($section)) {
                    $inicio = $sec['filaInicioDatos'] ?? null;
                    $fin = $sec['filaFinDatos'] ?? null;
                    if ($inicio === null || $fin === null) {
                        return null;
                    }

                    return ['inicio' => (int) $inicio, 'fin' => (int) $fin];
                }
            }
        }

        return null;
    }

    /**
     * Fase 3B -- PILOTO (CLAUDE.md punto 17.8). Busca, para ESTA carga
     * especifica, el valor tecnico capturado por Fase 3A para una fila
     * TOTAL excluida de rem_data. Devuelve un RemData NO persistido (mismo
     * shape que uno real: ->data['row_number'|'values'|'concept'|'total'],
     * ->id=null) para que SumEqualsEvaluator lo consuma sin cambios -- o
     * null si no existe (carga anterior a Fase 3A, o fila sin capturar).
     * Nunca escribe nada; solo lectura.
     */
    private function findTechnicalTotalRow(int $uploadId, ?string $sheet, ?string $section, int $rowNumber): ?RemData
    {
        if ($sheet === null || $section === null || $section === '') {
            return null;
        }

        $technical = RemTechnicalTotal::where('rem_upload_id', $uploadId)
            ->where('sheet', $sheet)
            ->where('rem_section_code', $section)
            ->where('row_number', $rowNumber)
            ->first();

        if ($technical === null) {
            return null;
        }

        $synthetic = new RemData();
        $synthetic->rem_upload_id = $uploadId;
        $synthetic->section = $sheet;
        $synthetic->data = [
            'row_number' => $technical->row_number,
            'concept' => $technical->concept,
            'total' => $technical->total,
            'values' => $technical->values,
            'rem_section_code' => $technical->rem_section_code,
            '_source' => 'rem_technical_totals',
            '_exclusion_reason' => $technical->exclusion_reason,
        ];

        return $synthetic;
    }

    private function writeExecutionLog(
        Rule $rule,
        int $uploadId,
        int $structureId,
        ?string $executionId,
        string $status,
        RuleEvaluationResult $result,
        int $executionMs,
        string $triggeredBy = 'cli',
    ): void {
        RuleExecutionLog::create([
            'rule_id' => $rule->id,
            'rule_key' => $rule->rule_key,
            'rem_upload_id' => $uploadId,
            'rem_template_structure_id' => $structureId,
            'execution_id' => $executionId,
            'status' => $status,
            'total_rows' => $result->totalRows,
            'passed_rows' => max(0, $result->totalRows - $result->failedRows),
            'failed_rows' => $result->failedRows,
            'execution_ms' => $executionMs,
            'triggered_by' => $triggeredBy,
            'context' => [
                'details' => $result->details,
            ],
        ]);
    }

    private function determineStatus(RuleEvaluationResult $result): string
    {
        // 'invalid_source_rows_configuration' agregado en Fase 3C-3A/3C-3B
        // (CLAUDE.md punto 17.21/17.22) -- mismo tratamiento que
        // 'invalid_row_range_configuration', al que espeja el shape.
        $skipReasons = ['invalid_config', 'invalid_row_range_configuration', 'invalid_source_rows_configuration', 'missing_total_row', 'empty_range', 'filtered_by_functional_rule'];
        if (in_array($result->reason, $skipReasons, true)) {
            return 'skipped';
        }

        if ($result->failedRows > 0) {
            return 'failed';
        }

        if ($result->skippedRows > 0 && $result->totalRows === 0) {
            return 'skipped';
        }

        return 'passed';
    }

    private function buildMessage(Rule $rule, RuleEvaluationResult $result): string
    {
        $total = $result->totalRows;
        $failed = $result->failedRows;

        if ($total === 0) {
            return "Sin datos para {$rule->rule_key}";
        }

        if ($failed === 0) {
            return "{$total}/{$total} filas pasaron";
        }

        return "{$failed}/{$total} filas fallaron en {$rule->rule_key}";
    }

    private function normalizeConfig(array $config): array
    {
        if (isset($config['source_letters']) || isset($config['target_column'])) {
            return $config;
        }

        $column = $config['column'] ?? null;

        if ($column !== null) {
            $config['target_column'] = $column;
        }

        if (isset($config['rule_logic'])) {
            $sourceLetters = [];
            if (preg_match('/\(([^)]+)\)/', $config['rule_logic'], $m)) {
                $parts = explode(' + ', $m[1]);
                foreach ($parts as $part) {
                    $letter = trim($part);
                    if ($letter !== '') {
                        $sourceLetters[] = $letter;
                    }
                }
            }
            if (!empty($sourceLetters)) {
                $config['source_letters'] = $sourceLetters;
            }
        }

        if (isset($config['row_range']['from'])) {
            $config['row_from'] = (int) $config['row_range']['from'];
        }
        if (isset($config['row_range']['to'])) {
            $config['row_to'] = (int) $config['row_range']['to'];
        }

        if (($config['row_from'] ?? null) !== null && ($config['row_to'] ?? null) !== null) {
            $config['scope'] = $config['row_from'] !== $config['row_to'] ? 'row_range' : 'per_row';
        }

        return $config;
    }
}
