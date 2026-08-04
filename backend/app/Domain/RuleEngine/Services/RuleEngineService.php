<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Domain\REM\Models\RemData;
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
            if ($rowFrom !== null) {
                $rows = $rows->filter(fn($rd) => (
                    $rd->data['row_number'] >= $rowFrom
                    && $rd->data['row_number'] <= $rowTo
                ));
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
        $skipReasons = ['invalid_config', 'invalid_row_range_configuration', 'missing_total_row', 'empty_range', 'filtered_by_functional_rule'];
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
