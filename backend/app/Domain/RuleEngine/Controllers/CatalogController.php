<?php

namespace App\Domain\RuleEngine\Controllers;

use App\Domain\RemParser\Services\CellScanOrchestrator;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\CertificationService;
use App\Domain\RuleEngine\Services\FunctionalRuleService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CatalogController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(
        private CertificationService $certificationService,
        private FunctionalRuleService $functionalRuleService,
        private SectionCalibrationMatrixService $matrixService,
        private CellDataStorageService $cellDataStorage,
        private CellScanOrchestrator $cellScanOrchestrator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = array_filter([
            'sheet' => $request->query('sheet'),
            'rule_type' => $request->query('rule_type'),
        ]);

        $rules = $this->certificationService->getRules($filters);

        $statusFilter = $request->query('status');
        $search = $request->query('search');

        $cards = collect();
        foreach ($rules as $rule) {
            $card = $this->certificationService->buildCertificationCard($rule);
            $cards->push($card);
        }

        if ($statusFilter) {
            $cards = $cards->filter(fn($c) => ($c['estado'] ?? 'Pendiente') === $statusFilter);
        }

        if ($search) {
            $q = strtolower($search);
            $cards = $cards->filter(fn($c) =>
                str_contains(strtolower($c['rule_key']), $q) ||
                str_contains(strtolower($c['description'] ?? ''), $q)
            );
        }

        $total = $cards->count();
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min((int) $request->query('per_page', self::PER_PAGE), 100);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $reglas = $cards->slice($offset, $perPage)->values();

        return response()->json([
            'data' => [
                'reglas' => $reglas,
                'sheets' => $this->certificationService->getAvailableSheets(),
                'rule_types' => ['sum_equals', 'required_and_le_parent'],
                'statuses' => [
                    ['key' => 'Pendiente', 'label' => 'Pendiente'],
                    ['key' => 'Certificada técnicamente', 'label' => 'Certificada técnicamente'],
                    ['key' => 'Requiere revisión', 'label' => 'Requiere revisión'],
                ],
                'stats' => $this->certificationService->getStats(),
                'filters' => [
                    'sheet' => $request->query('sheet', ''),
                    'rule_type' => $request->query('rule_type', ''),
                    'status' => $request->query('status', ''),
                    'search' => $request->query('search', ''),
                ],
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function show(string $ruleKey): JsonResponse
    {
        $rule = $this->certificationService->getRuleByKey($ruleKey);
        abort_unless($rule, 404, "Regla {$ruleKey} no encontrada");

        $card = $this->certificationService->buildCertificationCard($rule);
        $funcional = $this->functionalRuleService->getFunctionalRule($ruleKey);

        return response()->json([
            'data' => [
                'regla' => $card,
                'funcional' => $funcional,
                'estructura' => $this->certificationService->getStructureForCard(),
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function status(Request $request, string $ruleKey): JsonResponse
    {
        $rule = $this->certificationService->getRuleByKey($ruleKey);
        abort_unless($rule, 404);

        $valid = $request->validate([
            'estado' => 'required|in:Pendiente,Certificada técnicamente,Requiere revisión',
            'observaciones' => 'nullable|string|max:2000',
            'certificado_por' => 'nullable|string|max:255',
        ]);

        $this->certificationService->saveCertificationStatus(
            $ruleKey,
            $valid['estado'],
            $valid['observaciones'] ?? '',
            $valid['certificado_por'] ?? '',
        );

        $updated = $this->certificationService->loadCertificationStatus()[$ruleKey] ?? [];

        return response()->json([
            'data' => [
                'success' => true,
                'message' => 'Estado actualizado correctamente.',
                'certification' => [
                    'rule_key' => $ruleKey,
                    'estado' => $valid['estado'],
                    'observaciones' => $valid['observaciones'] ?? '',
                    'certificado_por' => $valid['certificado_por'] ?? '',
                    'certificado_en' => $updated['certificado_en'] ?? now()->toIso8601String(),
                ],
            ],
            'message' => 'Estado actualizado correctamente.',
            'errors' => null,
        ]);
    }

    public function section(Request $request, string $sheet, string $section): JsonResponse
    {
        $sectionInfo = $this->certificationService->getSectionInfo($sheet, $section);
        abort_unless($sectionInfo, 404, "Sección {$section} no encontrada en hoja {$sheet}");

        $cards = $this->certificationService->getSectionRules($sheet, $section);
        $certStatus = $this->certificationService->loadCertificationStatus();
        $funcionalRules = $this->functionalRuleService->getFunctionalRulesBySheetSection($sheet, $section);

        $pendientes = 0;
        $certificadas = 0;
        $requiereRevision = 0;
        foreach ($cards as $card) {
            match ($card['estado']) {
                'Certificada técnicamente' => $certificadas++,
                'Requiere revisión' => $requiereRevision++,
                default => $pendientes++,
            };
        }

        $filaInicio = $request->query('fila');
        $estadoFiltro = $request->query('estado');
        $search = $request->query('search');

        $filtered = collect($cards);
        if ($filaInicio) {
            $filtered = $filtered->filter(fn($c) => str_contains($c['rango_filas'] ?? '', $filaInicio));
        }
        if ($estadoFiltro) {
            $filtered = $filtered->filter(fn($c) => ($c['estado'] ?? 'Pendiente') === $estadoFiltro);
        }
        if ($search) {
            $q = strtolower($search);
            $filtered = $filtered->filter(fn($c) =>
                str_contains(strtolower($c['rule_key']), $q) ||
                str_contains(strtolower($c['description'] ?? ''), $q)
            );
        }

        $horizontalRules = 0;
        $verticalRules = 0;
        $requiredRules = 0;
        foreach ($cards as $card) {
            match ($card['rule_type']) {
                'sum_equals' => $horizontalRules++,
                'required_and_le_parent' => $requiredRules++,
                default => 0,
            };
        }

        return response()->json([
            'data' => [
                'section' => $sectionInfo,
                'reglas' => $filtered->values(),
                'stats' => [
                    'total' => count($cards),
                    'pendientes' => $pendientes,
                    'certificadas' => $certificadas,
                    'requiere_revision' => $requiereRevision,
                    'horizontales' => $horizontalRules,
                    'verticales' => $verticalRules,
                    'obligatoriedad' => $requiredRules,
                ],
                'funcional_rules' => $funcionalRules,
                'filters' => [
                    'fila' => $request->query('fila', ''),
                    'estado' => $request->query('estado', ''),
                    'search' => $request->query('search', ''),
                ],
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function getFunctionalRules(string $ruleKey): JsonResponse
    {
        $rule = $this->certificationService->getRuleByKey($ruleKey);
        abort_unless($rule, 404);

        $funcional = $this->functionalRuleService->getFunctionalRule($ruleKey);

        return response()->json([
            'data' => [
                'funcional' => $funcional,
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function saveFunctionalRules(Request $request, string $ruleKey): JsonResponse
    {
        $rule = $this->certificationService->getRuleByKey($ruleKey);
        abort_unless($rule, 404);

        $valid = $request->validate([
            'empty_behavior' => 'nullable|string|max:100',
            'applies_to_types' => 'nullable|array',
            'applies_to_types.*' => 'string|max:50',
            'included_health_centers' => 'nullable|array',
            'included_health_centers.*' => 'string|max:255',
            'excluded_health_centers' => 'nullable|array',
            'excluded_health_centers.*' => 'string|max:255',
            'functional_condition' => 'nullable|string|max:2000',
            'justification' => 'nullable|string|max:2000',
            'informed_by' => 'nullable|string|max:255',
            'status' => 'nullable|in:propuesta,validada,rechazada,pending,aprobada',
        ]);

        $record = $this->functionalRuleService->saveFunctionalRule($ruleKey, $valid);

        return response()->json([
            'data' => [
                'success' => true,
                'message' => 'Restricción funcional guardada correctamente.',
                'funcional' => $record,
            ],
            'message' => 'Restricción funcional guardada correctamente.',
            'errors' => null,
        ]);
    }

    public function sectionExport(string $sheet, string $section)
    {
        $sectionInfo = $this->certificationService->getSectionInfo($sheet, $section);
        abort_unless($sectionInfo, 404);

        $cards = $this->certificationService->getSectionRules($sheet, $section);
        $funcionalRules = $this->functionalRuleService->getFunctionalRulesBySheetSection($sheet, $section);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $s = $spreadsheet->getActiveSheet();
        $s->setTitle("{$sheet}-{$section}");

        $headers = [
            'Serie', 'Hoja', 'Sección', 'Fila', 'Regla', 'Concepto',
            'Fórmula Técnica', 'Columnas Origen', 'Columna Destino',
            'Estado Técnico', 'Comportamiento Vacío', 'Aplica a',
            'Establecimientos Incluidos', 'Establecimientos Excluidos',
            'Condición Funcional', 'Justificación', 'Responsable',
            'Estado Funcional', 'Observaciones',
        ];
        $col = 1;
        foreach ($headers as $h) {
            $s->setCellValueByColumnAndRow($col++, 1, $h);
        }

        $row = 2;
        foreach ($cards as $card) {
            $frule = $funcionalRules[$card['rule_key']] ?? [];
            $col = 1;
            $s->setCellValueByColumnAndRow($col++, $row, 'A');
            $s->setCellValueByColumnAndRow($col++, $row, $card['hoja']);
            $s->setCellValueByColumnAndRow($col++, $row, $card['seccion']);
            $s->setCellValueByColumnAndRow($col++, $row, $card['rango_filas'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, $card['rule_key']);
            $s->setCellValueByColumnAndRow($col++, $row, $card['description'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, $card['formula_interpretada']);
            $s->setCellValueByColumnAndRow($col++, $row, implode(', ', $card['columnas_origen'] ?? []));
            $s->setCellValueByColumnAndRow($col++, $row, $card['columna_destino'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, $card['estado']);
            $s->setCellValueByColumnAndRow($col++, $row, $frule['empty_behavior'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, implode(', ', $frule['applies_to_types'] ?? []));
            $s->setCellValueByColumnAndRow($col++, $row, implode(', ', $frule['included_health_centers'] ?? []));
            $s->setCellValueByColumnAndRow($col++, $row, implode(', ', $frule['excluded_health_centers'] ?? []));
            $s->setCellValueByColumnAndRow($col++, $row, $frule['functional_condition'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, $frule['justification'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, $frule['informed_by'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, $frule['status'] ?? '');
            $s->setCellValueByColumnAndRow($col++, $row, $card['observaciones'] ?? '');
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $s->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = "seccion-{$sheet}-{$section}.xlsx";
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function matrix(string $sheet, string $section): JsonResponse
    {
        $matrix = $this->matrixService->buildMatrix($sheet, $section);
        abort_unless($matrix['section']['codigo'], 404, "Sección {$section} no encontrada en hoja {$sheet}");

        return response()->json([
            'data' => $matrix,
            'message' => null,
            'errors' => null,
        ]);
    }

    public function rowDetail(string $sheet, string $section, int $row): JsonResponse
    {
        $detail = $this->matrixService->getRowDetail($sheet, $section, $row);
        abort_unless($detail, 404, "Fila {$row} no encontrada en {$sheet}/{$section}");

        $funcional = $this->functionalRuleService->getFunctionalRuleByRow($sheet, $section, $row);

        return response()->json([
            'data' => [
                'row' => $detail,
                'funcional' => $funcional,
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function saveRowFunctionalRules(Request $request, string $sheet, string $section, int $row): JsonResponse
    {
        $detail = $this->matrixService->getRowDetail($sheet, $section, $row);
        abort_unless($detail, 404, "Fila {$row} no encontrada en {$sheet}/{$section}");

        $valid = $request->validate([
            'rule_key' => 'nullable|string|max:255',
            'empty_behavior' => 'nullable|string|max:100',
            'applies_to_types' => 'nullable|array',
            'applies_to_types.*' => 'string|max:50',
            'included_health_centers' => 'nullable|array',
            'included_health_centers.*' => 'string|max:255',
            'excluded_health_centers' => 'nullable|array',
            'excluded_health_centers.*' => 'string|max:255',
            'functional_condition' => 'nullable|string|max:2000',
            'justification' => 'nullable|string|max:2000',
            'informed_by' => 'nullable|string|max:255',
            'updated_by' => 'nullable|string|max:255',
            'status' => 'nullable|in:propuesta,validada,rechazada,pending,aprobada',
        ]);

        if (in_array($valid['empty_behavior'] ?? null, ['heredar_patron', 'heredar_seccion'], true)) {
            $previous = $this->functionalRuleService->clearFunctionalRuleByRow($sheet, $section, $row, [
                'updated_by' => $valid['updated_by'] ?? $valid['informed_by'] ?? '',
                'inheritance_mode' => $valid['empty_behavior'],
            ]);

            $this->functionalRuleService->addDecisionHistory($sheet, $section, [
                'action' => 'clear_row_functional_for_inheritance',
                'row' => $row,
                'data' => $valid,
                'previous' => $previous,
                'by' => $valid['updated_by'] ?? $valid['informed_by'] ?? '',
            ]);

            return response()->json([
                'data' => [
                    'success' => true,
                    'message' => 'Decisión explícita eliminada; la fila heredará el criterio funcional.',
                    'funcional' => null,
                ],
                'message' => 'Decisión explícita eliminada; la fila heredará el criterio funcional.',
                'errors' => null,
            ]);
        }

        if (($valid['empty_behavior'] ?? null) === 'requiere_revision') {
            $valid['empty_behavior'] = 'pendiente_definicion';
            $valid['status'] = $valid['status'] ?? 'propuesta';
            $valid['functional_condition'] = $valid['functional_condition'] ?? 'Requiere revisión de Estadística.';
        }

        $record = $this->functionalRuleService->saveFunctionalRuleByRow($sheet, $section, $row, $valid);

        $this->functionalRuleService->addDecisionHistory($sheet, $section, [
            'action' => 'save_row_functional',
            'row' => $row,
            'data' => $valid,
            'by' => $valid['updated_by'] ?? $valid['informed_by'] ?? '',
        ]);

        return response()->json([
            'data' => [
                'success' => true,
                'message' => 'Decisión funcional guardada y versionada correctamente.',
                'funcional' => $record,
            ],
            'message' => 'Decisión funcional guardada y versionada correctamente.',
            'errors' => null,
        ]);
    }

    public function rowFunctionalDecisions(string $sheet, string $section): JsonResponse
    {
        $matrix = $this->matrixService->buildPatternMatrix($sheet, $section);
        abort_unless($matrix['section']['codigo'] ?? null, 404, "Sección {$section} no encontrada en hoja {$sheet}");

        $explicitRules = $this->functionalRuleService->getFunctionalRulesForEngine($sheet, $section);
        $patternQuestionRules = $this->functionalRuleService->getPatternFunctionalRulesForRows(
            $sheet,
            $section,
            $matrix['patterns'] ?? [],
        );
        $rows = [];
        $patternByRow = [];

        foreach ($matrix['patterns'] ?? [] as $pattern) {
            $patternRows = $pattern['rows'] ?? [];
            $sourceRule = $this->firstApprovedRuleInRows($patternRows, $explicitRules);

            foreach ($patternRows as $rowData) {
                $rowNumber = (int) ($rowData['fila'] ?? $rowData['row'] ?? 0);
                if ($rowNumber <= 0 || $this->isNonCalibrableDecisionRow($rowData)) {
                    continue;
                }

                $rows[$rowNumber] ??= $rowData;
                if (isset($patternQuestionRules[$rowNumber])) {
                    $patternByRow[$rowNumber] = $patternQuestionRules[$rowNumber];
                } elseif ($sourceRule !== null) {
                    $patternByRow[$rowNumber] = $sourceRule + [
                        '_inheritance_source_row' => (int) ($sourceRule['row'] ?? 0),
                        '_inheritance_scope' => 'pattern',
                    ];
                }
            }
        }

        $sectionRule = $this->firstApprovedRuleInRows(array_values($rows), $explicitRules);
        $decisions = [];
        foreach ($rows as $rowNumber => $rowData) {
            $explicit = $explicitRules[$rowNumber] ?? null;
            $inherited = null;
            $origin = 'none';

            if ($explicit !== null) {
                $effective = $explicit;
                $origin = 'row';
            } elseif (isset($patternByRow[$rowNumber])) {
                $inherited = $patternByRow[$rowNumber];
                $effective = $inherited;
                $origin = 'pattern';
            } elseif ($sectionRule !== null) {
                $inherited = $sectionRule + [
                    '_inheritance_source_row' => (int) ($sectionRule['row'] ?? 0),
                    '_inheritance_scope' => 'section',
                ];
                $effective = $inherited;
                $origin = 'section';
            } else {
                $effective = null;
            }

            $decisions[$rowNumber] = [
                'row' => $rowNumber,
                'concept' => $rowData['concepto'] ?? $rowData['concept'] ?? '',
                'professional' => $rowData['profesional'] ?? $rowData['professional'] ?? '',
                'explicit_decision' => $explicit['empty_behavior'] ?? null,
                'inherited_decision' => $explicit === null ? ($inherited['empty_behavior'] ?? null) : null,
                'effective_decision' => $effective['empty_behavior'] ?? null,
                'origin' => $origin,
                'source_row' => $origin === 'row' ? $rowNumber : ($effective['_inheritance_source_row'] ?? null),
                'source_pattern' => $effective['_inheritance_source_pattern'] ?? $effective['_source_pattern'] ?? null,
                'status' => $effective['status'] ?? null,
                'reviewed_by' => $effective['updated_by'] ?? $effective['informed_by'] ?? null,
                'reviewed_at' => $effective['updated_at'] ?? $effective['informed_at'] ?? null,
                'condition' => $effective['functional_condition'] ?? null,
                'observation' => $effective['justification'] ?? null,
                'has_explicit_decision' => $explicit !== null,
                'is_inherited' => $explicit === null && $inherited !== null,
                'possible_inconsistency' => false,
            ];
        }

        $this->markPossibleDecisionInconsistencies($decisions);

        return response()->json([
            'data' => [
                'sheet' => $sheet,
                'section' => $section,
                'rows' => array_values($decisions),
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function getRowFunctionalVersions(string $sheet, string $section, int $row): JsonResponse
    {
        $versions = $this->functionalRuleService->getRowVersions($sheet, $section, $row);

        return response()->json([
            'data' => [
                'versions' => $versions,
                'total' => count($versions),
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function getQuestions(string $sheet, string $section): JsonResponse
    {
        $matrix = $this->matrixService->buildMatrix($sheet, $section);
        abort_unless($matrix['section']['codigo'], 404);

        $questions = $this->functionalRuleService->getQuestions($sheet, $section);
        $history = $this->functionalRuleService->getQuestionsHistory($sheet, $section);

        if (empty($questions)) {
            $questions = $matrix['questions'];
        }

        return response()->json([
            'data' => [
                'questions' => $questions,
                'history' => $history,
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function saveQuestions(Request $request, string $sheet, string $section): JsonResponse
    {
        $matrix = $this->matrixService->buildMatrix($sheet, $section);
        abort_unless($matrix['section']['codigo'], 404);

        $valid = $request->validate([
            'questions' => 'required|array',
            'questions.*.type' => 'nullable|string|max:100',
            'questions.*.question' => 'nullable|string',
            'questions.*.response' => 'nullable|string|max:2000',
            'questions.*.observation' => 'nullable|string|max:2000',
            'questions.*.responsible' => 'nullable|string|max:255',
            'questions.*.date' => 'nullable|string|max:50',
            'questions.*.status' => 'nullable|in:pending,answered,clarification',
        ]);

        $updated = $this->functionalRuleService->saveQuestions($sheet, $section, $valid['questions']);

        return response()->json([
            'data' => [
                'success' => true,
                'message' => 'Respuestas guardadas correctamente.',
                'questions' => $updated,
            ],
            'message' => 'Respuestas guardadas correctamente.',
            'errors' => null,
        ]);
    }

    public function bulkFunctional(Request $request, string $sheet, string $section): JsonResponse
    {
        $matrix = $this->matrixService->buildMatrix($sheet, $section);
        abort_unless($matrix['section']['codigo'], 404);

        $valid = $request->validate([
            'rowNumbers' => 'required|array',
            'rowNumbers.*' => 'integer|min:1',
            'empty_behavior' => 'nullable|string|max:100',
            'applies_to_types' => 'nullable|array',
            'applies_to_types.*' => 'string|max:50',
            'included_health_centers' => 'nullable|array',
            'included_health_centers.*' => 'string|max:255',
            'excluded_health_centers' => 'nullable|array',
            'excluded_health_centers.*' => 'string|max:255',
            'functional_condition' => 'nullable|string|max:2000',
            'justification' => 'nullable|string|max:2000',
            'informed_by' => 'nullable|string|max:255',
            'status' => 'nullable|in:propuesta,validada,rechazada,pending,aprobada',
        ]);

        $rowNumbers = $valid['rowNumbers'];
        unset($valid['rowNumbers']);

        $saved = $this->functionalRuleService->bulkSaveFunctionalRuleByRow($sheet, $section, $rowNumbers, $valid);
        $total = count($saved);

        $this->functionalRuleService->addDecisionHistory($sheet, $section, [
            'action' => 'bulk_functional',
            'rows_affected' => $rowNumbers,
            'total_affected' => $total,
            'data' => $valid,
            'by' => $valid['informed_by'] ?? '',
        ]);

        return response()->json([
            'data' => [
                'success' => true,
                'message' => "Decisión funcional aplicada a {$total} filas correctamente.",
                'total_affected' => $total,
                'rows_affected' => $rowNumbers,
            ],
            'message' => "Decisión funcional aplicada a {$total} filas.",
            'errors' => null,
        ]);
    }

    public function exportCalibration(string $sheet, string $section)
    {
        $matrix = $this->matrixService->buildMatrix($sheet, $section);
        abort_unless($matrix['section']['codigo'], 404);

        $patternMatrix = $this->matrixService->buildPatternMatrix($sheet, $section);
        $funcionalByRow = $this->functionalRuleService->getFunctionalRulesByRow($sheet, $section);
        $questions = $this->functionalRuleService->getQuestions($sheet, $section);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $s = $spreadsheet->getActiveSheet();
        $s->setTitle("A01-A Calibración");

        $s->setCellValue('A1', 'CALIBRACIÓN — A01 Sección A');
        $s->mergeCells('A1:U1');
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $s->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $s->setCellValue('A2', 'Generado: ' . now()->format('Y-m-d H:i:s'));
        $s->mergeCells('A2:U2');

        $headerRow = 4;
        $colLetters = range('A', 'W');
        $headers = [
            'Fila', 'Concepto', 'Profesional', 'Patrón',
            'Destino técnico', 'Destino funcional',
            'Origen técnico', 'Origen funcional',
            'Regla funcional', 'Cobertura', 'Evidencia',
            'Columnas sumadas', 'Estado técnico',
            'Comportamiento vacío', 'Aplica a',
            'Establecimientos incluidos', 'Establecimientos excluidos',
            'Condición funcional',
            'Pregunta Estadística', 'Respuesta', 'Responsable',
            'Observaciones', 'Columnas especiales U:AH',
        ];

        foreach ($headers as $i => $h) {
            $s->setCellValue("{$colLetters[$i]}{$headerRow}", $h);
        }
        $s->getStyle("A{$headerRow}:W{$headerRow}")->getFont()->setBold(true);
        $s->getStyle("A{$headerRow}:W{$headerRow}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');

        // Build pattern lookup
        $patronPorFila = [];
        foreach ($patternMatrix['patterns'] ?? [] as $p) {
            foreach ($p['rows'] as $pr) {
                $patronPorFila[$pr['fila']] = [
                    'id' => $p['id'],
                    'nombre' => $p['nombre'],
                    'especiales' => $pr['especiales'] ?? [],
                ];
            }
        }

        $dataRow = 5;
        foreach ($matrix['rows'] as $r) {
            $frule = $funcionalByRow["{$sheet}_{$section}_{$r['row']}"] ?? [];
            $pInfo = $patronPorFila[$r['row']] ?? null;

            $questionText = '';
            $response = '';
            $responsible = '';
            $observations = '';

            foreach ($questions as $q) {
                $qRow = $q['row'] ?? null;
                if ($qRow === null || $qRow === $r['row']) {
                    $questionText = $q['question'] ?? $questionText;
                    $response = $q['response'] ?? $response;
                    $responsible = $q['responsible'] ?? $responsible;
                    $observations = $q['observation'] ?? $observations;
                }
            }

            if (in_array($r['row_type'], ['header', 'spacer', 'not_applicable'])) {
                $questionText = '';
            }

            $especialesStr = '';
            if ($pInfo) {
                $especialesEditables = array_filter($pInfo['especiales'], fn($e) => $e['editable']);
                $especialesBloqueadas = array_filter($pInfo['especiales'], fn($e) => !$e['editable']);
                $especialesStr = 'E:' . implode(',', array_map(fn($e) => $e['letra'], $especialesEditables))
                    . ' | B:' . implode(',', array_map(fn($e) => $e['letra'], $especialesBloqueadas));
            }

            $destinoFuncional = $r['columna_total']
                ? "TOTAL ({$r['columna_total']}{$r['row']})"
                : ($r['destino_exacto'] ?? '');
            $origenColumnas = $r['columnas_sumadas'] ?? [];
            $origenFuncional = $this->getOriginDescription($origenColumnas);
            $reglaFuncional = $pInfo ? ("P{$pInfo['id']}: {$pInfo['nombre']}") : '';

            $vals = [
                $r['row'],
                $r['concepto'] ?? '',
                $r['profesional'] ?? '',
                $pInfo ? "P{$pInfo['id']}: {$pInfo['nombre']}" : '',
                $r['destino_exacto'] ?? '',
                $destinoFuncional,
                implode(', ', $r['origen_efectivo'] ?? []),
                $origenFuncional ? "{$origenFuncional} (" . implode(', ', $origenColumnas) . ')' : '',
                $reglaFuncional,
                $r['cobertura'],
                $r['tipo_evidencia'],
                implode(', ', $origenColumnas),
                $r['estado_tecnico'],
                $frule['empty_behavior'] ?? '',
                implode(', ', $frule['applies_to_types'] ?? []),
                implode(', ', $frule['included_health_centers'] ?? []),
                implode(', ', $frule['excluded_health_centers'] ?? []),
                $frule['functional_condition'] ?? '',
                $questionText,
                $response,
                $responsible,
                $observations,
                $especialesStr,
            ];

            foreach ($vals as $i => $v) {
                $s->setCellValue("{$colLetters[$i]}{$dataRow}", $v);
            }
            $dataRow++;
        }

        foreach ($colLetters as $col) {
            if (method_exists($s, 'getColumnDimension')) {
                $s->getColumnDimension($col)->setAutoSize(true);
            }
        }

        // Second sheet: Patterns summary
        $s2 = $spreadsheet->createSheet();
        $s2->setTitle("Patrones detectados");
        $s2->setCellValue('A1', 'PATRONES DE FÓRMULA — A01 Sección A');
        $s2->mergeCells('A1:I1');
        $s2->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $ph = 3;
        $pHeaders = ['Patrón', 'Nombre', 'Filas', 'Fórmula template', 'Columnas origen', 'Columna total', 'Cant. filas', 'Conceptos', 'Profesionales'];
        foreach ($pHeaders as $i => $h) {
            $s2->setCellValue(chr(65 + $i) . $ph, $h);
        }
        $s2->getStyle("A{$ph}:I{$ph}")->getFont()->setBold(true);
        $s2->getStyle("A{$ph}:I{$ph}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');

        $pr = $ph + 1;
        foreach ($patternMatrix['patterns'] ?? [] as $p) {
            $s2->setCellValue("A{$pr}", $p['id']);
            $s2->setCellValue("B{$pr}", $p['nombre']);
            $s2->setCellValue("C{$pr}", implode(', ', $p['filas']));
            $s2->setCellValue("D{$pr}", '=' . $p['formula_template']);
            $s2->setCellValue("E{$pr}", implode(', ', $p['columnas_origen']));
            $s2->setCellValue("F{$pr}", $p['columna_total']);
            $s2->setCellValue("G{$pr}", $p['cantidad_filas']);
            $s2->setCellValue("H{$pr}", implode(', ', $p['conceptos']));
            $s2->setCellValue("I{$pr}", implode(', ', $p['profesionales']));
            $pr++;
        }

        foreach (range('A', 'I') as $col) {
            if (method_exists($s2, 'getColumnDimension')) {
                $s2->getColumnDimension($col)->setAutoSize(true);
            }
        }

        // Third sheet: Special columns U:AH
        $s3 = $spreadsheet->createSheet();
        $s3->setTitle("Columnas U:AH");
        $s3->setCellValue('A1', 'COLUMNAS ESPECIALES U:AH — A01 Sección A');
        $s3->mergeCells('A1:F1');
        $s3->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sh = 3;
        $s3Headers = ['Columna', 'Label', 'Filas editables', 'Filas bloqueadas', 'Tipo', 'Observación'];
        foreach ($s3Headers as $i => $h) {
            $s3->setCellValue(chr(65 + $i) . $sh, $h);
        }
        $s3->getStyle("A{$sh}:F{$sh}")->getFont()->setBold(true);
        $s3->getStyle("A{$sh}:F{$sh}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFF0F0F0');

        $sr = $sh + 1;
        foreach ($patternMatrix['columnas_especiales'] ?? [] as $colLetter) {
            $editableRows = 0;
            $blockedRows = 0;
            $firstType = '';
            foreach ($patternMatrix['all_rows'] as $r) {
                $rn = $r['row'];
                foreach ($patternMatrix['patterns'] as $p) {
                    foreach ($p['rows'] as $pr) {
                        if ($pr['fila'] === $rn) {
                            foreach ($pr['especiales'] as $ec) {
                                if ($ec['letra'] === $colLetter) {
                                    if ($ec['editable']) $editableRows++;
                                    else $blockedRows++;
                                    if (!$firstType) $firstType = $ec['tipo_celda'];
                                }
                            }
                        }
                    }
                }
            }
            $colLabel = '';
            foreach ($matrix['rows'] as $r) {
                foreach (($r['columnas_habilitadas'] ?? []) as $ch) {
                    if ($ch['letra'] === $colLetter) { $colLabel = $ch['label'] ?? ''; break 2; }
                }
            }
            $observacion = ($editableRows > 0 && $blockedRows > 0) ? 'Híbrida' : (($editableRows > 0) ? 'Editable' : 'Bloqueada');

            $s3->setCellValue("A{$sr}", $colLetter);
            $s3->setCellValue("B{$sr}", $colLabel);
            $s3->setCellValue("C{$sr}", $editableRows);
            $s3->setCellValue("D{$sr}", $blockedRows);
            $s3->setCellValue("E{$sr}", $firstType ?: 'vacio');
            $s3->setCellValue("F{$sr}", $observacion);
            $sr++;
        }

        foreach (range('A', 'F') as $col) {
            if (method_exists($s3, 'getColumnDimension')) {
                $s3->getColumnDimension($col)->setAutoSize(true);
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = "A01_Seccion_A_Calibracion_Estadistica.xlsx";
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    public function scanCells(string $sheet, ?string $section = null): JsonResponse
    {
        try {
            if ($section) {
                $cells = $this->cellScanOrchestrator->scan($sheet, $section);
                $summary = [
                    'section' => $section,
                    'total_cells' => count($cells),
                    'formulas' => collect($cells)->filter(fn($c) => $c->esFormula)->count(),
                    'editables' => collect($cells)->filter(fn($c) => $c->esEditable)->count(),
                    'bloqueadas' => collect($cells)->filter(fn($c) => $c->estaBloqueada)->count(),
                ];
            } else {
                $results = $this->cellScanOrchestrator->scanAllSections($sheet);
                $summary = [
                    'sheet' => $sheet,
                    'sections' => $results,
                    'total_cells' => array_sum($results),
                ];
            }

            return response()->json([
                'data' => [
                    'success' => true,
                    'summary' => $summary,
                ],
                'message' => 'Escaneo de celdas completado.',
                'errors' => null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'data' => null,
                'message' => $e->getMessage(),
                'errors' => [['type' => 'scan_error', 'detail' => $e->getMessage()]],
            ], 500);
        }
    }

    public function getCellData(string $sheet, string $section): JsonResponse
    {
        $sectionInfo = $this->certificationService->getSectionInfo($sheet, $section);
        abort_unless($sectionInfo, 404, "Sección {$section} no encontrada en {$sheet}");

        $cellData = $this->cellDataStorage->loadCellData($sheet, $section);
        $count = count($cellData);

        $formulas = 0;
        $editables = 0;
        $bloqueadas = 0;
        $porTipo = [];
        $porZona = [];

        foreach ($cellData as $coord => $cell) {
            if ($cell['es_formula'] ?? false) $formulas++;
            if ($cell['es_editable'] ?? false) $editables++;
            if ($cell['esta_bloqueada'] ?? false) $bloqueadas++;
            $tipo = $cell['tipo_celda'] ?? 'desconocido';
            $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;
            $zona = $cell['zona'] ?? 'desconocido';
            $porZona[$zona] = ($porZona[$zona] ?? 0) + 1;
        }

        return response()->json([
            'data' => [
                'sheet' => $sheet,
                'section' => $section,
                'cell_data' => $cellData,
                'summary' => [
                    'total_cells' => $count,
                    'formulas' => $formulas,
                    'editables' => $editables,
                    'bloqueadas' => $bloqueadas,
                    'por_tipo' => $porTipo,
                    'por_zona' => $porZona,
                ],
            ],
            'message' => null,
            'errors' => null,
        ]);
    }

    public function export()
    {
        $cards = $this->certificationService->exportAllCards();

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catálogo Reglas');

        $headers = ['Regla', 'Tipo', 'Severidad', 'Descripción', 'Hoja', 'Sección',
            'Columnas Origen', 'Columna Destino', 'Rango Filas', 'Fórmula Interpretada',
            'Estado', 'Observaciones', 'Certificado por', 'Certificado en'];
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($col++, 1, $header);
        }

        $row = 2;
        foreach ($cards as $card) {
            $col = 1;
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['rule_key']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['rule_type']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['severity']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['description']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['hoja']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['seccion']);
            $sheet->setCellValueByColumnAndRow($col++, $row, implode(', ', $card['columnas_origen'] ?? []));
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['columna_destino'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['rango_filas'] ?? '');
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['formula_interpretada']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['estado']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['observaciones']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['certificado_por']);
            $sheet->setCellValueByColumnAndRow($col++, $row, $card['certificado_en'] ?? '');
            $row++;
        }

        foreach (range(1, count($headers)) as $col) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'catalogo-reglas-consistencia-rem-2026.xlsx';
        $tempPath = storage_path("app/{$filename}");
        $writer->save($tempPath);

        return response()->download($tempPath, $filename)->deleteFileAfterSend(true);
    }

    private function getOriginDescription(array $cols): string
    {
        if (empty($cols)) return '';

        $normalized = array_map(fn($c) => preg_replace('/\d+$/', '', strtoupper($c)), $cols);
        $normalized = array_unique($normalized);
        sort($normalized);

        $map = [
            'D' => 'Menores de 4 años',
            'F,G,H,I,J,K,L,M,N' => 'Rango etario 10–54 años',
            'F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T' => 'Rango etario completo 10+ años',
            'L,M,N,O,P' => 'Rango etario climaterio 40–64 años',
        ];

        $key = implode(',', $normalized);
        return $map[$key] ?? ('Columnas: ' . $key);
    }

    private function firstApprovedRuleInRows(array $rows, array $explicitRules): ?array
    {
        foreach ($rows as $rowData) {
            $rowNumber = (int) ($rowData['fila'] ?? $rowData['row'] ?? 0);
            $rule = $explicitRules[$rowNumber] ?? null;
            if (!$rule) {
                continue;
            }

            if (
                in_array($rule['status'] ?? '', ['aprobada', 'validada por Estadística', 'validada_por_estadistica'], true)
                && !empty($rule['empty_behavior'])
                && !$this->hasFunctionalExceptions($rule)
            ) {
                return $rule;
            }
        }

        return null;
    }

    private function hasFunctionalExceptions(array $rule): bool
    {
        foreach (['included_health_centers', 'excluded_health_centers', 'exceptions', 'establishment_scope'] as $key) {
            if (!empty($rule[$key])) {
                return true;
            }
        }

        $scope = $rule['scope'] ?? null;
        return is_string($scope) && !in_array($scope, ['', 'global', 'all', 'todos'], true);
    }

    private function isNonCalibrableDecisionRow(array $rowData): bool
    {
        $type = strtolower((string) ($rowData['row_type'] ?? $rowData['type'] ?? $rowData['objetivo'] ?? ''));

        return in_array($type, ['header', 'encabezado', 'subtotal', 'total', 'special', 'especial', 'no_calibrable'], true);
    }

    private function markPossibleDecisionInconsistencies(array &$decisions): void
    {
        $byConcept = [];
        foreach ($decisions as $row => $decision) {
            $concept = trim(mb_strtolower((string) ($decision['concept'] ?? '')));
            if ($concept === '') {
                continue;
            }

            $byConcept[$concept][] = $row;
        }

        foreach ($byConcept as $rows) {
            $behaviors = [];
            foreach ($rows as $row) {
                $behavior = $decisions[$row]['effective_decision'] ?? null;
                if ($behavior !== null) {
                    $behaviors[$behavior] = true;
                }
            }

            if (count($behaviors) <= 1) {
                continue;
            }

            foreach ($rows as $row) {
                $decisions[$row]['possible_inconsistency'] = true;
            }
        }
    }
}
