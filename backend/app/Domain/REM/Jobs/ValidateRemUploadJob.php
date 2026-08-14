<?php

namespace App\Domain\REM\Jobs;

use App\Domain\REM\Models\RemUpload;
use App\Domain\REM\Models\RemValidationResult;
use App\Domain\REM\Services\RemValidationService;
use App\Domain\RuleEngine\Jobs\ValidateWithEngineJob;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FeatureFlagService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use App\Support\MemoryProbe;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class ValidateRemUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    /**
     * Coordenadas que el escaneo de celdas marca correctamente como
     * desbloqueadas/editables (asi quedo el Excel real) pero que no aplican
     * funcionalmente para "debe_registrar_cero". Evidencia: en las 60 cargas
     * reales de A05 disponibles al momento de esta revision, N/O/P (60-64,
     * 65-69, 70+ años) nunca se completaron -ni con 0- en la fila "Sólo
     * preservativo MAC — Mujer", mientras que la fila "Hombre" equivalente
     * SI las completa siempre con 0 (edad fértil vs. sin límite de edad).
     * Estructura y fórmula son identicas entre ambas filas (mismo patrón de
     * calibración), por lo que la exclusión no puede expresarse a nivel de
     * patrón sin afectar también a la fila Hombre -- de ahi que se liste por
     * coordenada exacta en vez de por color de fondo o por patrón completo.
     * Clave: "{sheet}|{section}|{row}" => columnas excluidas.
     */
    private const FUNCTIONALLY_NOT_APPLICABLE_COORDINATES = [
        'A05|C|45' => ['N', 'O', 'P'],
        'A05|C2|73' => ['N', 'O', 'P'],
    ];

    public function __construct(public RemUpload $upload)
    {
    }

    public function handle(
        RemValidationService $validator,
        FeatureFlagService $featureFlags,
        FunctionalRuleService $functionalRuleService,
        CellDataStorageService $cellDataStorage,
        SectionCalibrationMatrixService $matrixService,
    ): void {
        MemoryProbe::log('validate_job.inicio', ['upload_id' => $this->upload->id]);

        // Step 1: Run structural validation
        $results = $validator->validate($this->upload);

        MemoryProbe::log('validate_job.despues_validacion_estructural', [
            'upload_id' => $this->upload->id,
            'results_count' => $results->count(),
        ]);

        // Step 2: Evaluate functional rules directly
        $functionalResults = $this->evaluateFunctionalRules($this->upload, $functionalRuleService, $cellDataStorage, $matrixService);

        MemoryProbe::log('validate_job.despues_validacion_funcional', [
            'upload_id' => $this->upload->id,
            'functional_results_count' => $functionalResults->count(),
            'cell_data_cache_entries' => $cellDataStorage->cachedSectionsCount(),
        ]);

        // Step 3: Persist functional results
        if ($functionalResults->isNotEmpty()) {
            $functionalResults->chunk(500)->each(
                fn ($batch) => RemValidationResult::insert($batch->toArray())
            );
        }

        MemoryProbe::log('validate_job.despues_persistencia_resultados', ['upload_id' => $this->upload->id]);

        // Step 4: Determine final status from combined results. Este job es el
        // validador principal: es la unica fuente de verdad para success/with_errors
        // (ValidateWithEngineJob nunca recalcula ni puede revertir esta decision,
        // solo la aplica cuando termina -- ver su finalize()).
        $allResults = $results->merge($functionalResults->toArray());
        $hasErrors = $allResults->contains(fn ($r) => ($r['passed'] ?? true) === false && ($r['severity'] ?? 'error') === 'error');
        $finalStatus = $hasErrors ? 'with_errors' : 'success';

        // Step 5: Dispatch engine job if enabled (will add RuleExecutionLog + additional results).
        // Si el motor va a correr, el estado terminal se difiere hasta que
        // termine (o determine que no corre) -- de lo contrario el frontend deja
        // de hacer polling en cuanto ve success/with_errors y captura un
        // validation_summary parcial, sin los resultados que todavia va a
        // escribir el motor. $finalStatus viaja al job para que lo aplique el;
        // este job sigue siendo la unica fuente de verdad sobre CUAL es el
        // estado, solo cambia CUANDO se persiste.
        if ($featureFlags->get('enabled')) {
            $this->upload->update(['status' => 'validating']);
            ValidateWithEngineJob::dispatch($this->upload->id, $finalStatus);
        } else {
            $this->upload->update(['status' => $finalStatus]);
        }

        MemoryProbe::log('validate_job.fin', [
            'upload_id' => $this->upload->id,
            'engine_habilitado' => $featureFlags->get('enabled'),
        ]);
    }

    /**
     * Se ejecuta cuando el job agota sus reintentos sin completar. Sin esto,
     * un fallo aqui dejaria el upload indefinidamente en 'validating' (el
     * estado que dejo ProcessRemUploadJob antes de despachar este job), sin
     * ninguna senal de que el procesamiento no va a continuar.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->upload->update(['status' => 'failed']);
    }

    private function evaluateFunctionalRules(
        RemUpload $upload,
        FunctionalRuleService $functionalRuleService,
        CellDataStorageService $cellDataStorage,
        ?SectionCalibrationMatrixService $matrixService = null,
    ): Collection
    {
        $results = collect();

        // Antes: $upload->remData()->get() -- llamar al metodo de relacion +
        // ->get() ignora el cache de relacion de Eloquent y dispara una
        // consulta nueva, duplicando en memoria la coleccion completa de
        // rem_data que RemValidationService::validate() (Step 1, mismo
        // $upload, misma llamada a este job) ya pudo haber cargado via
        // $upload->remData (acceso de propiedad, que Eloquent cachea en el
        // modelo). Usar la propiedad aqui reutiliza esa misma coleccion si
        // ya estaba cargada, o la carga una unica vez si no -- nunca dos
        // colecciones completas simultaneas para el mismo upload.
        $remData = $upload->remData;

        MemoryProbe::log('validate_job.evaluate_functional.despues_carga_rem_data', [
            'upload_id' => $upload->id,
            'rem_data_rows' => $remData->count(),
        ]);

        $rowsBySection = $remData->groupBy(function ($row) use ($upload) {
            $data = $row->data ?? [];
            $sheet = (string) ($data['section'] ?? $row->section ?? '');
            $section = (string) ($data['rem_section_code'] ?? $upload->rem_type);

            return strtoupper($sheet) . '|' . strtolower($section);
        });

        MemoryProbe::log('validate_job.evaluate_functional.despues_groupby', [
            'upload_id' => $upload->id,
            'secciones' => $rowsBySection->count(),
        ]);

        $approvedStatuses = ['aprobada', 'validada por Estadística', 'validada_por_estadistica'];

        foreach ($rowsBySection as $rows) {
            $firstRowData = $rows->first()?->data ?? [];
            $sheet = (string) ($firstRowData['section'] ?? $rows->first()?->section ?? '');
            $section = (string) ($firstRowData['rem_section_code'] ?? $upload->rem_type);
            if ($sheet === '' || $section === '') {
                continue;
            }

            $functionalRules = $functionalRuleService->getFunctionalRulesForEngine($sheet, $section);
            if ($matrixService !== null) {
                // Version liviana de buildPatternMatrix(): entrega solo los patrones
                // (id + filas), unico dato que getPatternFunctionalRulesForRows()
                // consume aqui. Evita reconstruir column_groups y el detalle
                // enriquecido por fila, que esta ruta de validacion masiva nunca usa.
                $patterns = $matrixService->getPatternsForValidation($sheet, $section);
                $patternRules = $functionalRuleService->getPatternFunctionalRulesForRows(
                    $sheet,
                    $section,
                    $patterns,
                );
                $functionalRules += $patternRules;
            }
            $effectiveFunctionalRules = $this->resolveEffectiveFunctionalRules(
                $rows,
                $functionalRules,
                $cellDataStorage,
                $approvedStatuses,
            );

            foreach ($rows as $row) {
                $rowNum = (int) ($row->data['row_number'] ?? 0);
                $fr = $effectiveFunctionalRules[$rowNum] ?? null;
                if (!$fr) {
                    continue;
                }

                $status = $fr['status'] ?? '';
                if (!in_array($status, $approvedStatuses, true)) {
                    continue;
                }

                $emptyBehavior = $fr['empty_behavior'] ?? null;
                if (!$emptyBehavior) {
                    continue;
                }

                $values = $row->data['values'] ?? [];
                $hasData = collect($values)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();

                $concept = $row->data['concept'] ?? $fr['concepto'] ?? '?';
                $professional = $row->data['professional'] ?? $fr['profesional'] ?? '?';

                $label = trim("{$concept} / {$professional}", ' /');

                if ($emptyBehavior === 'debe_registrar_cero') {
                    // Se evalúan las celdas editables aplicables independientemente del total
                    // de la fila: "debe_registrar_cero" exige que cada celda habilitada quede
                    // explícitamente en 0 cuando no hubo esa prestación puntual, incluso si
                    // otras celdas de la misma fila sí registraron datos (total > 0).
                    $missingCells = $this->missingApplicableInputCellDetails($row->data, $cellDataStorage);
                    $calibratedSeverity = $this->normalizeFunctionalSeverity($fr['severity'] ?? null);
                    if (!$hasData) {
                        $results->push($this->makeFunctionalResult($upload, $sheet, $rowNum, $concept, $professional, $calibratedSeverity ?? 'error', false, "La fila {$rowNum}, {$label}, debe registrar 0 explícitamente cuando no existan prestaciones.", $fr, $missingCells, $row->data));
                    } elseif (!empty($missingCells)) {
                        $coordinates = implode(', ', array_column($missingCells, 'coordinate'));
                        $results->push($this->makeFunctionalResult($upload, $sheet, $rowNum, $concept, $professional, $calibratedSeverity ?? 'warning', false, "La fila {$rowNum}, {$label}, debe registrar 0 explícitamente en {$coordinates}.", $fr, $missingCells, $row->data));
                    }
                } elseif ($emptyBehavior === 'puede_quedar_vacio') {
                    // El calibrador es la fuente de verdad: si la fila esta vacia, la decision
                    // se respeta y queda trazada como cumplida (passed=true), sin generar
                    // advertencia. Si la fila tiene datos (total o parcial), "puede quedar vacia"
                    // no exige nada: no se ignora la fila, las reglas tecnicas siguen su curso
                    // normal en RemValidationService, igual que hace SumEqualsEvaluator::rowHasData().
                    if (!$hasData) {
                        $results->push($this->makeFunctionalPassedResult($upload, $sheet, $section, $rowNum, $concept, $professional, $fr));
                    }
                } elseif ($emptyBehavior === 'incluir') {
                    if (!$hasData) {
                        $results->push($this->makeFunctionalResult($upload, $sheet, $rowNum, $concept, $professional, 'warning', false, "La fila {$rowNum}, {$label}, debe incluir datos según decisión funcional.", $fr));
                    }
                } elseif ($emptyBehavior === 'excluir') {
                    if ($hasData) {
                        $results->push($this->makeFunctionalResult($upload, $sheet, $rowNum, $concept, $professional, 'info', true, "La fila {$rowNum}, {$label}, está excluida por decisión funcional, pero contiene datos.", $fr));
                    }
                } elseif ($emptyBehavior === 'informativo') {
                    // Always informational — no pass/fail
                } elseif ($emptyBehavior === 'no_aplica') {
                    // Skipped entirely — no evaluation needed
                }
            }

            // Todas las filas de esta seccion (sheet|rem_section_code) ya
            // terminaron de evaluarse -- resolveEffectiveFunctionalRules() y
            // el bucle de arriba fueron los unicos consumidores de
            // $cellDataStorage para esta seccion (mismo sheet/section en
            // todas las filas de este grupo, por construccion del groupBy()
            // de mas arriba). Liberarla evita retener el cell_data de las
            // ~150+ secciones de un upload real simultaneamente -- mismo
            // patron y misma causa raiz que el OOM ya corregido en
            // RemParserService::buildSectionMaps().
            $cellDataStorage->forgetSection($sheet, $section);
            $matrixService?->forgetSectionCache($sheet, $section);

            MemoryProbe::log('validate_job.evaluate_functional.despues_seccion', [
                'upload_id' => $upload->id,
                'sheet' => $sheet,
                'section' => $section,
                'rows_en_seccion' => $rows->count(),
                'cell_data_cache_entries' => $cellDataStorage->cachedSectionsCount(),
            ]);
        }

        return $results;
    }

    private function resolveEffectiveFunctionalRules(
        Collection $rows,
        array $functionalRules,
        CellDataStorageService $cellDataStorage,
        array $approvedStatuses,
    ): array {
        $effective = [];
        $rulesBySignature = [];
        $sectionEmptyBehaviorRule = null;

        foreach ($rows as $row) {
            $rowNum = (int) ($row->data['row_number'] ?? 0);
            $explicitRule = $functionalRules[$rowNum] ?? null;
            if (!$explicitRule) {
                continue;
            }

            $effective[$rowNum] = $explicitRule;

            if (!$this->canInheritFromFunctionalRule($explicitRule, $approvedStatuses)) {
                continue;
            }

            $signature = $this->functionalStructureSignature($row->data, $cellDataStorage);
            if ($signature === null) {
                continue;
            }

            $rulesBySignature[$signature] ??= $explicitRule + [
                '_inheritance_source_row' => $rowNum,
                '_inheritance_scope' => 'pattern',
            ];

            if (
                ($explicitRule['empty_behavior'] ?? null) === 'debe_registrar_cero'
                && $this->hasApplicableEditableInputCells($row->data, $cellDataStorage)
            ) {
                $sectionEmptyBehaviorRule ??= $explicitRule + [
                    '_inheritance_source_row' => $rowNum,
                    '_inheritance_scope' => 'section',
                ];
            }
        }

        foreach ($rows as $row) {
            $rowNum = (int) ($row->data['row_number'] ?? 0);
            if (
                isset($effective[$rowNum])
                || $this->isNonInheritableRow($row->data)
                || !$this->hasApplicableEditableInputCells($row->data, $cellDataStorage)
            ) {
                continue;
            }

            $signature = $this->functionalStructureSignature($row->data, $cellDataStorage);
            if ($signature !== null && isset($rulesBySignature[$signature])) {
                $effective[$rowNum] = $rulesBySignature[$signature] + [
                    '_inherited' => true,
                ];
                continue;
            }

            if ($sectionEmptyBehaviorRule !== null) {
                $effective[$rowNum] = $sectionEmptyBehaviorRule + [
                    '_inherited' => true,
                ];
            }
        }

        return $effective;
    }

    private function canInheritFromFunctionalRule(array $functionalRule, array $approvedStatuses): bool
    {
        if (!in_array($functionalRule['status'] ?? '', $approvedStatuses, true)) {
            return false;
        }

        if (($functionalRule['empty_behavior'] ?? null) === null) {
            return false;
        }

        return !$this->hasExplicitFunctionalExceptions($functionalRule);
    }

    private function hasExplicitFunctionalExceptions(array $functionalRule): bool
    {
        foreach (['included_health_centers', 'excluded_health_centers', 'exceptions', 'establishment_scope'] as $key) {
            if (!empty($functionalRule[$key])) {
                return true;
            }
        }

        $scope = $functionalRule['scope'] ?? null;
        return is_string($scope) && !in_array($scope, ['', 'global', 'all', 'todos'], true);
    }

    private function isNonInheritableRow(array $rowData): bool
    {
        $type = strtolower((string) ($rowData['type'] ?? $rowData['row_type'] ?? $rowData['calibration_type'] ?? ''));
        if (in_array($type, ['header', 'encabezado', 'special', 'especial', 'total', 'no_calibrable'], true)) {
            return true;
        }

        $mode = strtolower((string) ($rowData['mode'] ?? ''));
        return in_array($mode, ['header', 'special', 'direct_total', 'direct_input'], true);
    }

    private function functionalStructureSignature(array $rowData, CellDataStorageService $cellDataStorage): ?string
    {
        $sheet = (string) ($rowData['section'] ?? '');
        $section = (string) ($rowData['rem_section_code'] ?? '');
        $rowNumber = $rowData['row_number'] ?? null;
        $values = $rowData['values'] ?? [];

        if ($sheet === '' || $section === '' || $rowNumber === null || empty($values)) {
            return null;
        }

        $rowNumber = (int) $rowNumber;
        $columns = array_map('strtoupper', array_keys($values));
        sort($columns);

        $rowCells = $cellDataStorage->getCellsForRow($sheet, $section, $rowNumber);
        $formulas = [];
        $editability = [];

        foreach ($columns as $column) {
            $cell = $rowCells[$column . $rowNumber]
                ?? $rowCells[$column]
                ?? $cellDataStorage->getCellForCoordinate($sheet, $section, $column . $rowNumber)
                ?? [];

            $formula = $cell['formula'] ?? $cell['formula_original'] ?? null;
            if (is_string($formula) && trim($formula) !== '') {
                $formulas[$column] = $this->normalizeFormulaForSignature($formula);
            }

            $isBlocked = (bool) ($cell['esta_bloqueada'] ?? false);
            $isEditable = (bool) ($cell['es_editable'] ?? !$isBlocked);
            $editability[$column] = $isEditable && !$isBlocked ? 'E' : 'B';
        }

        ksort($formulas);
        ksort($editability);

        return sha1(json_encode([
            'sheet' => strtoupper($sheet),
            'section' => strtoupper($section),
            'total_column' => strtoupper((string) ($rowData['total_column'] ?? '')),
            'columns' => $columns,
            'formulas' => $formulas,
            'editability' => $editability,
        ]));
    }

    private function normalizeFormulaForSignature(string $formula): string
    {
        $normalized = strtoupper(str_replace(' ', '', $formula));

        return preg_replace('/(\$?[A-Z]{1,3}\$?)\d+/', '$1{ROW}', $normalized) ?? $normalized;
    }

    private function hasApplicableEditableInputCells(array $rowData, CellDataStorageService $cellDataStorage): bool
    {
        return !empty($this->applicableEditableInputCoordinates($rowData, $cellDataStorage));
    }

    private function hasMissingApplicableInputCells(array $rowData, CellDataStorageService $cellDataStorage): bool
    {
        return !empty($this->missingApplicableInputCells($rowData, $cellDataStorage));
    }

    private function missingApplicableInputCells(array $rowData, CellDataStorageService $cellDataStorage): array
    {
        $sheet = (string) ($rowData['section'] ?? '');
        $section = (string) ($rowData['rem_section_code'] ?? '');
        $rowNumber = $rowData['row_number'] ?? null;
        if ($sheet === '' || $section === '' || $rowNumber === null) {
            return [];
        }

        $missing = [];
        foreach ($this->applicableEditableInputCoordinates($rowData, $cellDataStorage) as $coordinate => $value) {
            if ($value === null || $value === '') {
                $missing[] = $coordinate;
            }
        }

        return $missing;
    }

    private function missingApplicableInputCellDetails(array $rowData, CellDataStorageService $cellDataStorage): array
    {
        $sheet = (string) ($rowData['section'] ?? '');
        $section = (string) ($rowData['rem_section_code'] ?? '');
        $rowNumber = $rowData['row_number'] ?? null;
        if ($sheet === '' || $section === '' || $rowNumber === null) {
            return [];
        }

        $details = [];
        foreach ($this->applicableEditableInputCoordinates($rowData, $cellDataStorage) as $coordinate => $value) {
            if ($value !== null && $value !== '') {
                continue;
            }

            $column = strtoupper((string) preg_replace('/\d+/', '', $coordinate));
            $cell = $cellDataStorage->getCellForCoordinate($sheet, $section, $coordinate) ?? [];

            $details[] = [
                'coordinate' => $coordinate,
                'column' => $column,
                'label' => $this->labelForColumn($sheet, $section, $column, (int) $rowNumber, $cellDataStorage),
                'value' => $value,
                'expected_value' => 0,
                'editable' => ($cell['es_editable'] ?? true) === true,
                'blocked' => ($cell['esta_bloqueada'] ?? false) === true,
                'color' => $cell['color_fondo']['nombre_inferido'] ?? $cell['color_fondo']['rgb'] ?? null,
            ];
        }

        return $details;
    }

    private function applicableEditableInputCoordinates(array $rowData, CellDataStorageService $cellDataStorage): array
    {
        $sheet = (string) ($rowData['section'] ?? '');
        $section = (string) ($rowData['rem_section_code'] ?? '');
        $rowNumber = $rowData['row_number'] ?? null;
        if ($sheet === '' || $section === '' || $rowNumber === null) {
            return [];
        }

        $excludedColumns = self::FUNCTIONALLY_NOT_APPLICABLE_COORDINATES[strtoupper($sheet) . '|' . strtoupper($section) . '|' . (int) $rowNumber] ?? [];
        $coordinates = [];

        // Nota: antes se excluia cualquier columna igual a rowData['total_column']
        // sin verificar la celda concreta. Eso asumia que "la columna total" es
        // siempre una formula calculada, lo cual no es cierto en secciones donde
        // esa columna es la unica captura manual disponible (ej. A08/J, A08/L) o
        // donde mas de una columna quedo marcada esTotal=true en la estructura
        // (ej. A08/G.1, F y G). La exclusion real de un total ya la cubre el
        // chequeo de es_formula/esta_bloqueada mas abajo, celda por celda -- por
        // eso ya no hace falta comparar contra total_column aqui.
        foreach ($rowData['values'] ?? [] as $column => $value) {
            $column = strtoupper((string) $column);
            if (in_array($column, $excludedColumns, true)) {
                continue;
            }

            $coordinate = $column . (int) $rowNumber;
            $cell = $cellDataStorage->getCellForCoordinate($sheet, $section, $coordinate);
            if ($cell === null) {
                continue;
            }

            if (
                ($cell['esta_bloqueada'] ?? false) === true
                || ($cell['es_editable'] ?? false) !== true
                || ($cell['es_formula'] ?? false) === true
            ) {
                continue;
            }

            $coordinates[$coordinate] = $value;
        }

        return $coordinates;
    }

    /**
     * La severidad calibrada (FunctionalRuleService::patternQuestionsToFunctionalRule)
     * se guarda en español ('error'/'advertencia') porque asi responde la pregunta de
     * calibracion; rem_validation_results.severity es un enum en ingles
     * ('error'/'warning'/'info'). Traduce y descarta valores no reconocidos para que
     * el llamador conserve su default actual cuando no hay decision calibrada.
     */
    private function normalizeFunctionalSeverity(?string $severity): ?string
    {
        return match ($severity) {
            'error' => 'error',
            'advertencia', 'warning' => 'warning',
            'info' => 'info',
            default => null,
        };
    }

    private function labelForColumn(string $sheet, string $section, string $column, int $rowNumber, CellDataStorageService $cellDataStorage): string
    {
        for ($row = $rowNumber - 1; $row >= max(1, $rowNumber - 8); $row--) {
            $cell = $cellDataStorage->getCellForCoordinate($sheet, $section, $column . $row);
            $value = trim((string) ($cell['valor_bruto'] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return "Columna {$column}";
    }

    private function makeFunctionalResult(RemUpload $upload, string $section, int $rowNum, string $concept, string $professional, string $severity, bool $passed, string $message, array $functionalRule, array $pendingCells = [], array $rowData = []): array
    {
        $label = trim("{$concept} / {$professional}", ' /');
        $recomendacion = match ($functionalRule['empty_behavior'] ?? '') {
            'debe_registrar_cero' => "Revise la fila {$rowNum} y registre 0 explícitamente en las celdas habilitadas que quedaron sin dato.",
            'incluir' => "Revise la fila {$rowNum} y complete los datos requeridos según la decisión funcional.",
            'excluir' => "La fila {$rowNum} está excluida por decisión funcional. Verifique si corresponde mantener los datos.",
            default => "Revise la fila {$rowNum} y verifique los valores registrados.",
        };

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_' . $section . '_' . $rowNum,
            'rule_type' => 'functional_rule',
            'severity' => $severity,
            'passed' => $passed,
            'message' => "[{$section}] {$message}",
            'context' => json_encode([
                'section' => $section,
                'row_number' => $rowNum,
                'concept' => $concept,
                'profesional' => $professional,
                'row_total' => $rowData['total'] ?? ($rowData['values'][$rowData['total_column'] ?? ''] ?? null),
                'pending_cells' => $pendingCells,
                'pending_cells_count' => count($pendingCells),
                'criterio' => [
                    'empty_behavior' => $functionalRule['empty_behavior'] ?? null,
                    'functional_condition' => $functionalRule['functional_condition'] ?? '',
                    'justification' => $functionalRule['justification'] ?? '',
                    'informed_by' => $functionalRule['informed_by'] ?? '',
                    'informed_at' => $functionalRule['informed_at'] ?? null,
                    'status' => $functionalRule['status'] ?? '',
                    'updated_by' => $functionalRule['updated_by'] ?? '',
                    'updated_at' => $functionalRule['updated_at'] ?? null,
                    'scope' => $functionalRule['scope'] ?? 'global',
                    'confidence_level' => $functionalRule['confidence_level'] ?? '',
                    'acquisition_method' => $functionalRule['acquisition_method'] ?? '',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Traza interna de cumplimiento para una fila vacia cuya decision funcional
     * aprobada es 'puede_quedar_vacio'. passed=true: no debe aparecer como
     * advertencia ni error en la vista del funcionario (ValidationErrorFormatterService
     * solo formatea resultados con passed=false), pero queda auditable en
     * rem_validation_results.
     */
    private function makeFunctionalPassedResult(RemUpload $upload, string $sheet, string $remSectionCode, int $rowNum, string $concept, string $professional, array $functionalRule): array
    {
        $label = trim("{$concept} / {$professional}", ' /');
        $origin = ($functionalRule['_inherited'] ?? false)
            ? ($functionalRule['_inheritance_scope'] ?? 'heredada')
            : 'fila';

        return [
            'rem_upload_id' => $upload->id,
            'rule_key' => 'f_' . $sheet . '_' . $rowNum,
            'rule_type' => 'functional_rule',
            'severity' => 'info',
            'passed' => true,
            'message' => "[{$sheet}] La fila {$rowNum}, {$label}, quedó vacía y fue aceptada según decisión funcional (puede_quedar_vacio).",
            'context' => json_encode([
                'sheet' => $sheet,
                'rem_section_code' => $remSectionCode,
                'row_number' => $rowNum,
                'concept' => $concept,
                'professional' => $professional,
                'empty_behavior' => $functionalRule['empty_behavior'] ?? null,
                'decision_aplicada' => 'Fila vacía aceptada por decisión funcional: puede quedar vacía.',
                'origen_decision' => $origin,
                'origen_detalle' => [
                    'scope' => $functionalRule['_inheritance_scope'] ?? null,
                    'source_row' => $functionalRule['_inheritance_source_row'] ?? null,
                ],
                'reviewed_by' => $functionalRule['updated_by'] ?? $functionalRule['informed_by'] ?? '',
                'reviewed_at' => $functionalRule['updated_at'] ?? $functionalRule['informed_at'] ?? null,
                'functional_condition' => $functionalRule['functional_condition'] ?? '',
                'justification' => $functionalRule['justification'] ?? '',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}

