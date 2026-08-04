<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;

class RuleExportReviewCatalogCommand extends Command
{
    protected $signature = 'rule:export-review-catalog
        {--structure= : ID de estructura REM}
        {--serie= : Serie REM (A, BM, D, P, BS) — usado si no se especifica structure}
        {--upload= : ID de upload para incluir resultados de validación del último REM probado}
        {--output= : Ruta de salida del archivo .xlsx}
    ';

    protected $description = 'Genera catálogo funcional de reglas de consistencia para revisión con Estadística APS';

    private const COL_HEADERS = [
        'ID',
        'Código técnico de regla',
        'Serie',
        'Año',
        'Formulario REM',
        'Sección',
        'Columna destino',
        'Variable / nombre funcional',
        'Tipo de regla detectada',
        'Descripción funcional',
        'Lógica de validación',
        'Fórmula Excel original si existe',
        'Rango de filas',
        'Severidad actual',
        'Estado actual',
        'Fuente detectada',
        'Evidencia o referencia',
        'Resultado en último REM probado',
        'Filas evaluadas',
        'Filas con observación',
        'Observación del sistema',
        'Validación funcional',
        'Comentario Estadística',
        'Acción requerida',
        'Responsable',
        'Fecha revisión',
    ];

    private const COLOR_LIGHT_RED = 'FFFCE4E4';
    private const COLOR_LIGHT_YELLOW = 'FFFFF9D7';
    private const COLOR_LIGHT_GREEN = 'FFE8F5E9';
    private const COLOR_LIGHT_GRAY = 'FFF5F5F5';
    private const COLOR_DARK_RED = 'FFF44336';
    private const COLOR_DARK_GREEN = 'FF4CAF50';
    private const COLOR_DARK_YELLOW = 'FFFFB300';

    public function handle(): int
    {
        $structureId = $this->option('structure');
        $serieOption = $this->option('serie');
        $uploadId = $this->option('upload');
        $outputPath = $this->option('output');

        // Resolve serie and default output
        $serieResolved = strtoupper($serieOption ?? 'A');
        if ($structureId) {
            $struct = DB::selectOne('SELECT serie, anio FROM rem_template_structures WHERE id = ?', [(int) $structureId]);
            if ($struct) {
                $serieResolved = $struct->serie;
                $anioResolved = $struct->anio;
            } else {
                $anioResolved = 2026;
            }
        } else {
            $anioResolved = 2026;
        }

        $serieLabel = "Serie {$serieResolved} {$anioResolved}";
        $this->info("Generando catálogo de revisión de reglas {$serieLabel}...");

        if (!$outputPath) {
            $outputPath = storage_path("app/catalogo_revision_reglas_serie_{$serieResolved}_{$anioResolved}.xlsx");
        }

        $rules = $this->loadRules($structureId, $serieResolved, $anioResolved);
        if (empty($rules)) {
            $this->error("No se encontraron reglas {$serieLabel} activas.");
            return Command::FAILURE;
        }

        $validationLookup = [];
        $failedValidationKeys = [];
        $uploadInfo = null;

        if ($uploadId) {
            $this->line('  Cargando resultados de validación del upload ' . $uploadId . '...');
            $uploadInfo = $this->loadUploadInfo($uploadId);
            if (!$uploadInfo) {
                $this->warn('  Upload ' . $uploadId . ' no encontrado. Se omiten resultados de validación.');
            } else {
                $validationLookup = $this->buildValidationLookup($uploadId);
                $failedValidationKeys = $this->loadFailedValidationKeys($uploadId);
            }
        }

        $this->line('  Generando hoja de cálculo...');

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("Catálogo de Revisión de Reglas {$serieLabel}")
            ->setCreator('Sistema REM - Ministerio de Salud')
            ->setDescription('Catálogo funcional de reglas de consistencia para revisión previa con Estadística APS');

        $this->buildInstructionsSheet($spreadsheet, count($rules), $validationLookup, $failedValidationKeys, $uploadId, $serieLabel);

        $this->buildMainSheet($spreadsheet, $rules, $validationLookup, $uploadId);
        $this->buildSummaryByFormSheet($spreadsheet, $rules, $validationLookup);
        $this->buildSummaryByTypeSheet($spreadsheet, $rules, $validationLookup);
        $this->buildFailedRulesSheet($spreadsheet, $failedValidationKeys, $uploadInfo, $uploadId);
        $this->buildPendingReviewSheet($spreadsheet, $rules, $validationLookup, $uploadId);

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
        $spreadsheet->disconnectWorksheets();

        $this->newLine();
        $this->info('Archivo generado: ' . $outputPath);
        $this->line('   Total de reglas exportadas: ' . count($rules));

        $this->printCounts($rules, $validationLookup, $failedValidationKeys);

        return Command::SUCCESS;
    }

    // ─── Hoja 0: Instrucciones ─────────────────────────────────────────

    private function buildInstructionsSheet(Spreadsheet $spreadsheet, int $totalRules, array $validationLookup, array $failedKeys, ?string $uploadId, string $serieLabel): void
    {
        $sheet = $spreadsheet->setActiveSheetIndex(0);
        $sheet->setTitle('Instrucciones de revisión');

        $sheet->getColumnDimension('A')->setWidth(4);
        $sheet->getColumnDimension('B')->setWidth(80);
        $sheet->getColumnDimension('C')->setWidth(4);

        $titleStyle = [
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FF1A237E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EAF6']],
        ];
        $sectionStyle = [
            'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF1A237E']],
        ];
        $normalStyle = [
            'font' => ['size' => 11],
            'alignment' => ['wrapText' => true],
        ];
        $legendRow = [
            'font' => ['size' => 11],
            'alignment' => ['wrapText' => true],
        ];

        $r = 2;
        $sheet->setCellValue('B' . $r, "Catálogo de Revisión de Reglas de Consistencia — {$serieLabel}");
        $sheet->getStyle('B' . $r)->applyFromArray($titleStyle);
        $r += 2;

        $sheet->setCellValue('B' . $r, '¿Qué es este catálogo?');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        $sheet->setCellValue('B' . $r, "Este archivo contiene las {$totalRules} reglas de consistencia detectadas automáticamente desde la estructura REM de {$serieLabel}. Cada regla valida relaciones entre celdas del formulario (sumas, totales, campos obligatorios). El objetivo es que el equipo de Estadística APS revise una por una antes de aprobar el motor de reglas para producción.");
        $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Estructura del archivo');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;

        $hojas = [
            ['Catálogo completo', 'Listado de las ' . $totalRules . ' reglas con todos los detalles técnicos y funcionales. Columnas de revisión (V–Z) deben ser llenadas por Estadística.'],
            ['Resumen por formulario', 'Conteo de reglas por cada formulario REM (A01–A34), desglosado por tipo, severidad, fuente y resultado en el último REM probado.'],
            ['Resumen por tipo de regla', 'Conteo agregado por tipo de regla (sum_equals y required_and_le_parent).'],
            ['Reglas con observaciones', 'Reglas que presentaron errores en la última validación del REM de prueba (upload #' . ($uploadId ?? 'N/A') . '). Contienen el mensaje de error y contexto (formulario, concepto, profesional, valores).'],
            ['Pendientes de revisión', 'Todas las reglas que requieren validación funcional por parte de Estadística. Incluye motivo de pendiente y columnas para registrar la decisión.'],
        ];
        foreach ($hojas as $h) {
            $sheet->setCellValue('B' . $r, '• ' . $h[0] . ': ' . $h[1]);
            $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
            $r++;
        }
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Significado de los estados de validación');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;

        $estados = [
            ['Correcta', 'La regla es válida funcionalmente, corresponde al Manual REM y la lógica es correcta. No requiere cambios.', self::COLOR_LIGHT_GREEN],
            ['Requiere ajuste', 'La regla existe pero su lógica, fórmula, rango o variable necesita corrección. Describir el ajuste en "Comentario Estadística".', self::COLOR_LIGHT_YELLOW],
            ['No aplica', 'La regla no corresponde a una validación real del Manual REM. Puede ser un artefacto de la estructura Excel o una interpretación incorrecta. Marcar para revisión o eliminación.', self::COLOR_LIGHT_GRAY],
        ];
        foreach ($estados as $e) {
            $sheet->setCellValue('B' . $r, $e[0] . ': ' . $e[1]);
            $sheet->getStyle('B' . $r)->applyFromArray(['font' => ['bold' => true, 'size' => 11], 'alignment' => ['wrapText' => true]]);
            $sheet->getStyle('B' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($e[2]);
            $r++;
        }
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Leyenda de colores en el catálogo');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;

        $leyenda = [
            ['Error', 'Severidad de regla: su incumplimiento genera observación obligatoria.', self::COLOR_LIGHT_RED],
            ['Advertencia', 'Severidad de regla: su incumplimiento genera advertencia informativa.', self::COLOR_LIGHT_YELLOW],
            ['Activa', 'Estado actual de la regla: habilitada en el motor.', self::COLOR_LIGHT_GREEN],
            ['Correcta', 'Resultado de la última validación técnica: pasó todas las filas evaluadas.', self::COLOR_LIGHT_GREEN],
            ['Observación', 'Resultado de la última validación técnica: al menos una fila no cumplió la regla.', self::COLOR_LIGHT_RED],
            ['Pendiente', 'Validación funcional pendiente de revisión por Estadística.', self::COLOR_LIGHT_GRAY],
        ];
        foreach ($leyenda as $l) {
            $sheet->getStyle('B' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($l[2]);
            $sheet->setCellValue('B' . $r, '  ' . $l[0] . ' — ' . $l[1]);
            $sheet->getStyle('B' . $r)->applyFromArray($legendRow);
            $r++;
        }
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Instrucciones para Estadística APS');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        $instrucciones = [
            '1. Abrir la hoja "Catálogo completo". Revisar cada regla en orden por formulario.',
            '2. Para cada regla, verificar: formulario, sección, columna, variable, lógica y fórmula.',
            '3. En las columnas V–Z (Validación funcional, Comentario, Acción, Responsable, Fecha), registrar la decisión:',
            '   • Validación funcional: seleccionar "Correcta", "Requiere ajuste" o "No aplica".',
            '   • Comentario Estadística: explicar por qué requiere ajuste o no aplica.',
            '   • Acción requerida: ej. "Mantener", "Ajustar rango", "Corregir variable", "Eliminar".',
            '   • Responsable: nombre de quien revisa.',
            '   • Fecha revisión: fecha de la revisión.',
            '4. Si una regla falta en el catálogo pero existe en el Manual REM, anotarla en la hoja "Pendientes de revisión" usando las últimas filas (sin ID), o contactar al equipo técnico para agregarla.',
            '5. Si una regla del catálogo no corresponde al Manual REM, marcar "No aplica" y explicar por qué.',
            '6. Una vez revisadas todas las reglas, notificar al equipo técnico para ajustar el motor según las decisiones registradas.',
        ];
        foreach ($instrucciones as $i) {
            $sheet->setCellValue('B' . $r, $i);
            $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
            $r++;
        }
        $r += 2;

        $totalFailed = array_sum(array_column($failedKeys, 'failed_rows'));
        $sheet->setCellValue('B' . $r, 'Resumen rápido');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        // Count unique forms
        $formsList = [];
        if (!empty($rules)) {
            foreach ($rules as $rule) {
                $sheet = $rule['metadata']['sheet'] ?? '';
                if ($sheet) $formsList[$sheet] = true;
            }
        }
        $formsCount = count($formsList);
        $formsStr = !empty($formsList) ? implode(', ', array_keys($formsList)) : '—';

        $sheet->setCellValue('B' . $r, '• Total reglas en catálogo: ' . $totalRules);
        $sheet->setCellValue('B' . $r + 1, '• Reglas con observación técnica en upload ' . ($uploadId ?? 'N/A') . ': ' . count($failedKeys) . ' (' . $totalFailed . ' filas con error)');
        $sheet->setCellValue('B' . $r + 2, "• Formularios cubiertos: {$formsCount} ({$formsStr})");
        $sheet->setCellValue('B' . $r + 3, '• Fecha de generación: ' . now()->format('d/m/Y H:i'));
        for ($i = 0; $i < 4; $i++) {
            $sheet->getStyle('B' . ($r + $i))->applyFromArray($normalStyle);
        }
    }

    // ─── Hoja 1: Catálogo completo ─────────────────────────────────────

    private function buildMainSheet(Spreadsheet $spreadsheet, array $rules, array $validationLookup, ?string $uploadId): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Catálogo completo');

        $this->writeHeaders($sheet, self::COL_HEADERS);

        $rowIdx = 2;
        foreach ($rules as $rule) {
            $meta = $rule['metadata'];
            $config = $rule['config'];
            $sheetName = $meta['sheet'] ?? '';
            $section = $meta['section'] ?? '';
            $letra = $meta['letra'] ?? '';
            $label = $meta['label'] ?? '';
            $type = $rule['rule_type'];

            $variableName = $this->extractVariableName($label, $letra);
            $description = $this->buildDescription($type, $meta, $config, $variableName);
            $formula = $this->cleanFormula($label);
            $logicSummary = $this->buildLogicSummary($type, $config, $variableName, $letra);
            $rowRange = $this->buildRowRange($config);
            $sourceLabel = $this->sourceLabel($rule['source']);
            $evidence = $this->buildEvidence($meta, $rule['rule_key']);

            $match = $this->matchRuleToValidation($rule, $validationLookup);
            $validationResult = '';
            $totalRows = '';
            $failedRows = '';
            $sysMessage = '';

            if ($match) {
                $vData = $match['data'];
                $totalRows = $vData['total'];
                $failedRows = $vData['failed'];
                $validationResult = $vData['failed'] > 0 ? 'Observación' : 'Correcta';
                if (!empty($vData['messages'])) {
                    $sysMessage = implode(' | ', array_unique(array_slice($vData['messages'], 0, 3)));
                    if (count($vData['messages']) > 3) {
                        $sysMessage .= ' (... y ' . (count($vData['messages']) - 3) . ' más)';
                    }
                }
            } elseif ($uploadId) {
                $validationResult = 'No aplica (sin correlato en upload ' . $uploadId . ')';
            }

            $id = $rule['id'];
            $ruleKey = $rule['rule_key'];
            $serie = $rule['serie'] ?? 'A';
            $anio = $rule['anio'] ?? 2026;
            $typeLabel = $type === 'sum_equals' ? 'Suma igual al Total' : 'Requerido y menor o igual al Total';
            $severityLabel = $rule['severity'] === 'error' ? 'Error' : 'Advertencia';
            $statusLabel = match ($rule['status']) {
                'active' => 'Activa',
                'inactive' => 'Inactiva',
                default => 'Deprecada',
            };

            $sheet->setCellValue('A' . $rowIdx, $id);
            $sheet->setCellValue('B' . $rowIdx, $ruleKey);
            $sheet->setCellValue('C' . $rowIdx, $serie);
            $sheet->setCellValue('D' . $rowIdx, $anio);
            $sheet->setCellValue('E' . $rowIdx, $sheetName);
            $sheet->setCellValue('F' . $rowIdx, $section);
            $sheet->setCellValue('G' . $rowIdx, $letra);
            $sheet->setCellValue('H' . $rowIdx, $variableName);
            $sheet->setCellValue('I' . $rowIdx, $typeLabel);
            $this->setCellValueSafe($sheet, 'J' . $rowIdx, $description);
            $this->setCellValueSafe($sheet, 'K' . $rowIdx, $logicSummary);
            $this->setCellValueSafe($sheet, 'L' . $rowIdx, $formula);
            $sheet->setCellValue('M' . $rowIdx, $rowRange);
            $sheet->setCellValue('N' . $rowIdx, $severityLabel);
            $sheet->setCellValue('O' . $rowIdx, $statusLabel);
            $sheet->setCellValue('P' . $rowIdx, $sourceLabel);
            $this->setCellValueSafe($sheet, 'Q' . $rowIdx, $evidence);
            $sheet->setCellValue('R' . $rowIdx, $validationResult);
            $sheet->setCellValue('S' . $rowIdx, $totalRows);
            $sheet->setCellValue('T' . $rowIdx, $failedRows);
            $this->setCellValueSafe($sheet, 'U' . $rowIdx, $sysMessage);
            $sheet->setCellValue('V' . $rowIdx, 'Pendiente');
            $sheet->setCellValue('W' . $rowIdx, '');
            $sheet->setCellValue('X' . $rowIdx, '');
            $sheet->setCellValue('Y' . $rowIdx, '');
            $sheet->setCellValue('Z' . $rowIdx, '');

            // ── Colores ──
            if ($severityLabel === 'Error') {
                $sheet->getStyle('N' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_RED);
                $sheet->getStyle('N' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_RED);
            } elseif ($severityLabel === 'Advertencia') {
                $sheet->getStyle('N' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_YELLOW);
                $sheet->getStyle('N' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_YELLOW);
            }

            if ($statusLabel === 'Activa') {
                $sheet->getStyle('O' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GREEN);
                $sheet->getStyle('O' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_GREEN);
            }

            if ($validationResult === 'Observación') {
                $sheet->getStyle('R' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_RED);
                $sheet->getStyle('R' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_RED);
            } elseif ($validationResult === 'Correcta') {
                $sheet->getStyle('R' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GREEN);
                $sheet->getStyle('R' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_GREEN);
            }

            $sheet->getStyle('V' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GRAY);

            $rowIdx++;
        }

        $this->applyColumnWidths($sheet, self::COL_HEADERS, [
            8, 28, 6, 6, 12, 8, 10, 30, 24, 50, 30, 30, 12, 12, 10, 18, 22, 22, 10, 14, 40, 14, 30, 22, 16, 14
        ]);

        $sheet->setAutoFilter('A1:Z' . ($rowIdx - 1));
        $sheet->freezePane('A2');
    }

    // ─── Hoja 2: Resumen por formulario ────────────────────────────────

    private function buildSummaryByFormSheet(Spreadsheet $spreadsheet, array $rules, array $validationLookup): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen por formulario');

        $headers = [
            'Formulario REM',
            'Total reglas',
            'Suma igual al Total',
            'Requerido y menor o igual al Total',
            'Error',
            'Advertencia',
            'Fuente: Excel',
            'Fuente: Manual',
            'Con observaciones en último REM',
            'Correctas en último REM',
            'Sin correlato',
        ];

        $this->writeHeaders($sheet, $headers);

        $forms = [];
        foreach ($rules as $rule) {
            $meta = $rule['metadata'];
            $form = $meta['sheet'] ?? 'Sin formulario';
            if (!isset($forms[$form])) {
                $forms[$form] = [
                    'total' => 0, 'sum_equals' => 0, 'required_and_le_parent' => 0,
                    'error' => 0, 'warning' => 0, 'excel' => 0, 'manual' => 0,
                    'observations' => 0, 'correct' => 0, 'no_match' => 0,
                ];
            }
            $forms[$form]['total']++;
            if ($rule['rule_type'] === 'sum_equals') $forms[$form]['sum_equals']++;
            else $forms[$form]['required_and_le_parent']++;
            if ($rule['severity'] === 'error') $forms[$form]['error']++;
            else $forms[$form]['warning']++;
            if ($rule['source'] === 'excel_formula') $forms[$form]['excel']++;
            else $forms[$form]['manual']++;

            $match = $this->matchRuleToValidation($rule, $validationLookup);
            if ($match) {
                if ($match['data']['failed'] > 0) $forms[$form]['observations']++;
                else $forms[$form]['correct']++;
            } else {
                $forms[$form]['no_match']++;
            }
        }

        ksort($forms);
        $rowIdx = 2;
        $totals = [
            'Total reglas' => 0, 'Suma igual al Total' => 0, 'Requerido y menor o igual al Total' => 0,
            'Error' => 0, 'Advertencia' => 0, 'Fuente: Excel' => 0, 'Fuente: Manual' => 0,
            'Con observaciones en último REM' => 0, 'Correctas en último REM' => 0, 'Sin correlato' => 0,
        ];

        foreach ($forms as $form => $data) {
            $sheet->setCellValue('A' . $rowIdx, $form);
            $sheet->setCellValue('B' . $rowIdx, $data['total']);
            $sheet->setCellValue('C' . $rowIdx, $data['sum_equals']);
            $sheet->setCellValue('D' . $rowIdx, $data['required_and_le_parent']);
            $sheet->setCellValue('E' . $rowIdx, $data['error']);
            $sheet->setCellValue('F' . $rowIdx, $data['warning']);
            $sheet->setCellValue('G' . $rowIdx, $data['excel']);
            $sheet->setCellValue('H' . $rowIdx, $data['manual']);
            $sheet->setCellValue('I' . $rowIdx, $data['observations']);
            $sheet->setCellValue('J' . $rowIdx, $data['correct']);
            $sheet->setCellValue('K' . $rowIdx, $data['no_match']);

            // Color I (observations) if > 0
            if ($data['observations'] > 0) {
                $sheet->getStyle('I' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_RED);
            }
            if ($data['correct'] > 0) {
                $sheet->getStyle('J' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GREEN);
            }

            $totals['Total reglas'] += $data['total'];
            $totals['Suma igual al Total'] += $data['sum_equals'];
            $totals['Requerido y menor o igual al Total'] += $data['required_and_le_parent'];
            $totals['Error'] += $data['error'];
            $totals['Advertencia'] += $data['warning'];
            $totals['Fuente: Excel'] += $data['excel'];
            $totals['Fuente: Manual'] += $data['manual'];
            $totals['Con observaciones en último REM'] += $data['observations'];
            $totals['Correctas en último REM'] += $data['correct'];
            $totals['Sin correlato'] += $data['no_match'];
            $rowIdx++;
        }

        // Totals
        $sheet->setCellValue('A' . $rowIdx, 'TOTAL');
        $sheet->getStyle('A' . $rowIdx)->getFont()->setBold(true);
        $colLetters = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
        $idx = 0;
        foreach (array_slice($headers, 1) as $h) {
            $sheet->setCellValue($colLetters[$idx] . $rowIdx, $totals[$h]);
            $sheet->getStyle($colLetters[$idx] . $rowIdx)->getFont()->setBold(true);
            $idx++;
        }

        $this->applyColumnWidths($sheet, $headers, [18, 10, 18, 24, 8, 10, 12, 12, 20, 18, 12]);
        $sheet->setAutoFilter('A1:K' . $rowIdx);
        $sheet->freezePane('A2');
    }

    // ─── Hoja 3: Resumen por tipo de regla ─────────────────────────────

    private function buildSummaryByTypeSheet(Spreadsheet $spreadsheet, array $rules, array $validationLookup): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Resumen por tipo de regla');

        $headers = [
            'Tipo de regla', 'Total', 'Error', 'Advertencia',
            'Fuente: Excel', 'Fuente: Manual',
            'Con observaciones en último REM', 'Correctas en último REM', 'Sin correlato',
        ];

        $this->writeHeaders($sheet, $headers);

        $groups = [
            'Suma igual al Total (sum_equals)' => 'sum_equals',
            'Requerido y menor o igual al Total (required_and_le_parent)' => 'required_and_le_parent',
        ];

        $rowIdx = 2;
        $totals = [
            'Total' => 0, 'Error' => 0, 'Advertencia' => 0,
            'Fuente: Excel' => 0, 'Fuente: Manual' => 0,
            'Con observaciones en último REM' => 0, 'Correctas en último REM' => 0, 'Sin correlato' => 0,
        ];

        foreach ($groups as $label => $typeFilter) {
            $filtered = array_filter($rules, fn($r) => $r['rule_type'] === $typeFilter);
            $total = count($filtered);
            $error = count(array_filter($filtered, fn($r) => $r['severity'] === 'error'));
            $warning = count(array_filter($filtered, fn($r) => $r['severity'] === 'warning'));
            $excel = count(array_filter($filtered, fn($r) => $r['source'] === 'excel_formula'));
            $manual = count(array_filter($filtered, fn($r) => $r['source'] === 'manual'));

            $obs = 0; $correct = 0; $noMatch = 0;
            foreach ($filtered as $r) {
                $m = $this->matchRuleToValidation($r, $validationLookup);
                if ($m) {
                    if ($m['data']['failed'] > 0) $obs++;
                    else $correct++;
                } else {
                    $noMatch++;
                }
            }

            $sheet->setCellValue('A' . $rowIdx, $label);
            $sheet->setCellValue('B' . $rowIdx, $total);
            $sheet->setCellValue('C' . $rowIdx, $error);
            $sheet->setCellValue('D' . $rowIdx, $warning);
            $sheet->setCellValue('E' . $rowIdx, $excel);
            $sheet->setCellValue('F' . $rowIdx, $manual);
            $sheet->setCellValue('G' . $rowIdx, $obs);
            $sheet->setCellValue('H' . $rowIdx, $correct);
            $sheet->setCellValue('I' . $rowIdx, $noMatch);

            if ($obs > 0) {
                $sheet->getStyle('G' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_RED);
            }
            if ($correct > 0) {
                $sheet->getStyle('H' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GREEN);
            }

            $totals['Total'] += $total;
            $totals['Error'] += $error;
            $totals['Advertencia'] += $warning;
            $totals['Fuente: Excel'] += $excel;
            $totals['Fuente: Manual'] += $manual;
            $totals['Con observaciones en último REM'] += $obs;
            $totals['Correctas en último REM'] += $correct;
            $totals['Sin correlato'] += $noMatch;
            $rowIdx++;
        }

        $sheet->setCellValue('A' . $rowIdx, 'TOTAL');
        $sheet->getStyle('A' . $rowIdx)->getFont()->setBold(true);
        $colLetters = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        $idx = 0;
        foreach (array_slice($headers, 1) as $h) {
            $sheet->setCellValue($colLetters[$idx] . $rowIdx, $totals[$h]);
            $sheet->getStyle($colLetters[$idx] . $rowIdx)->getFont()->setBold(true);
            $idx++;
        }

        $this->applyColumnWidths($sheet, $headers, [40, 10, 8, 10, 12, 12, 20, 18, 12]);
        $sheet->setAutoFilter('A1:I' . $rowIdx);
    }

    // ─── Hoja 4: Reglas con observaciones ──────────────────────────────

    private function buildFailedRulesSheet(Spreadsheet $spreadsheet, array $failedKeys, ?object $uploadInfo, ?string $uploadId): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Reglas con observaciones');

        $headers = [
            'Código de regla (validación)',
            'Filas con observación',
            'Mensaje de error',
            'Formulario REM',
            'Sección',
            'Columna',
            'Concepto',
            'Profesional',
            'Valor hijo',
            'Valor padre',
            'Motivo',
        ];

        $this->writeHeaders($sheet, $headers);

        $rowIdx = 2;
        $totalObs = 0;

        foreach ($failedKeys as $fk) {
            $sheet->setCellValue('A' . $rowIdx, $fk['rule_key']);
            $sheet->setCellValue('B' . $rowIdx, $fk['failed_rows']);
            $firstMsg = $fk['messages'][0] ?? '';
            $this->setCellValueSafe($sheet, 'C' . $rowIdx, $firstMsg);

            $firstCtx = $fk['contexts'][0] ?? [];
            if ($firstCtx) {
                $section = $firstCtx['section'] ?? '';
                $childCol = $firstCtx['child_column'] ?? '';
                $parentCol = $firstCtx['parent_column'] ?? '';
                $concept = $firstCtx['concept'] ?? '';
                $professional = $firstCtx['professional'] ?? '';
                $childVal = $firstCtx['child_value'] ?? '';
                $parentVal = $firstCtx['parent_value'] ?? '';
                $reason = $firstCtx['reason'] ?? '';

                $sheet->setCellValue('D' . $rowIdx, $section);
                $sheet->setCellValue('F' . $rowIdx, $childCol . ' (hijo) / ' . $parentCol . ' (padre)');
                $sheet->setCellValue('G' . $rowIdx, $concept);
                $sheet->setCellValue('H' . $rowIdx, $professional);
                $sheet->setCellValue('I' . $rowIdx, $childVal);
                $sheet->setCellValue('J' . $rowIdx, $parentVal);
                $sheet->setCellValue('K' . $rowIdx, $reason);
            }

            // Color entire row light red
            for ($col = 'A'; $col <= 'K'; $col++) {
                $sheet->getStyle($col . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_RED);
            }

            $totalObs += $fk['failed_rows'];
            $rowIdx++;
        }

        $this->applyColumnWidths($sheet, $headers, [32, 16, 60, 12, 10, 30, 24, 22, 12, 12, 16]);
        $sheet->setAutoFilter('A1:K' . ($rowIdx - 1));
        $sheet->freezePane('A2');

        $rowIdx += 2;
        $sheet->setCellValue('A' . $rowIdx, 'Resumen:');
        $sheet->getStyle('A' . $rowIdx)->getFont()->setBold(true);
        $rowIdx++;
        $sheet->setCellValue('A' . $rowIdx, 'Total reglas con observaciones:');
        $sheet->setCellValue('B' . $rowIdx, count($failedKeys));
        $rowIdx++;
        $sheet->setCellValue('A' . $rowIdx, 'Total filas con observación:');
        $sheet->setCellValue('B' . $rowIdx, $totalObs);
        $rowIdx++;
        if ($uploadInfo) {
            $sheet->setCellValue('A' . $rowIdx, 'REM evaluado:');
            $sheet->setCellValue('B' . $rowIdx, $uploadInfo->name ?? 'Upload #' . $uploadId);
            $rowIdx++;
            $sheet->setCellValue('A' . $rowIdx, 'Establecimiento:');
            $sheet->setCellValue('B' . $rowIdx, $uploadInfo->establishment ?? '');
        }
    }

    // ─── Hoja 5: Pendientes de revisión ────────────────────────────────

    private function buildPendingReviewSheet(Spreadsheet $spreadsheet, array $rules, array $validationLookup, ?string $uploadId): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Pendientes de revisión');

        $headers = [
            'ID',
            'Código técnico de regla',
            'Formulario REM',
            'Sección',
            'Columna',
            'Variable / nombre funcional',
            'Tipo de regla',
            'Descripción funcional',
            'Lógica de validación',
            'Rango de filas',
            'Severidad',
            'Estado',
            'Fuente',
            'Motivo pendiente',
            'Validación funcional',
            'Comentario Estadística',
            'Acción requerida',
            'Responsable',
            'Fecha revisión',
        ];

        $this->writeHeaders($sheet, $headers);

        $pending = [];
        foreach ($rules as $rule) {
            $meta = $rule['metadata'];
            $config = $rule['config'];
            $match = $this->matchRuleToValidation($rule, $validationLookup);
            $reason = '';

            if (!$match && $uploadId) {
                $reason = 'Sin correlato en upload ' . $uploadId;
            } elseif ($match && $match['data']['failed'] > 0) {
                $reason = 'Presentó observaciones en último REM (correlato: ' . $match['validation_key'] . ')';
                if (!empty($match['data']['messages'])) {
                    $reason .= ' - ' . implode(' ', array_unique(array_slice($match['data']['messages'], 0, 1)));
                }
            } elseif ($match && $match['data']['failed'] === 0) {
                $reason = 'Pasó validación técnica, pendiente revisión funcional';
            } else {
                $reason = 'Pendiente de revisión funcional';
            }

            $pending[] = [
                'id' => $rule['id'],
                'rule_key' => $rule['rule_key'],
                'sheet' => $meta['sheet'] ?? '',
                'section' => $meta['section'] ?? '',
                'letra' => $meta['letra'] ?? '',
                'variable' => $this->extractVariableName($meta['label'] ?? '', $meta['letra'] ?? ''),
                'type' => $rule['rule_type'],
                'description' => $this->buildDescription(
                    $rule['rule_type'], $meta, $config,
                    $this->extractVariableName($meta['label'] ?? '', $meta['letra'] ?? '')
                ),
                'logic' => $this->buildLogicSummary(
                    $rule['rule_type'], $config,
                    $this->extractVariableName($meta['label'] ?? '', $meta['letra'] ?? ''),
                    $meta['letra'] ?? ''
                ),
                'row_range' => $this->buildRowRange($config),
                'severity' => $rule['severity'],
                'status' => $rule['status'],
                'source' => $this->sourceLabel($rule['source']),
                'reason' => $reason,
            ];
        }

        $rowIdx = 2;
        foreach ($pending as $p) {
            $sheet->setCellValue('A' . $rowIdx, $p['id']);
            $sheet->setCellValue('B' . $rowIdx, $p['rule_key']);
            $sheet->setCellValue('C' . $rowIdx, $p['sheet']);
            $sheet->setCellValue('D' . $rowIdx, $p['section']);
            $sheet->setCellValue('E' . $rowIdx, $p['letra']);
            $sheet->setCellValue('F' . $rowIdx, $p['variable']);
            $sheet->setCellValue('G' . $rowIdx, $p['type'] === 'sum_equals' ? 'Suma igual al Total' : 'Requerido y menor o igual al Total');
            $this->setCellValueSafe($sheet, 'H' . $rowIdx, $p['description']);
            $this->setCellValueSafe($sheet, 'I' . $rowIdx, $p['logic']);
            $sheet->setCellValue('J' . $rowIdx, $p['row_range']);
            $sheet->setCellValue('K' . $rowIdx, $p['severity'] === 'error' ? 'Error' : 'Advertencia');
            $sheet->setCellValue('L' . $rowIdx, $p['status'] === 'active' ? 'Activa' : 'Inactiva');
            $sheet->setCellValue('M' . $rowIdx, $p['source']);
            $this->setCellValueSafe($sheet, 'N' . $rowIdx, $p['reason']);

            // Review columns — leave filled with default values for easy editing
            $sheet->setCellValue('O' . $rowIdx, 'Pendiente');
            $sheet->setCellValue('P' . $rowIdx, '');
            $sheet->setCellValue('Q' . $rowIdx, '');
            $sheet->setCellValue('R' . $rowIdx, '');
            $sheet->setCellValue('S' . $rowIdx, '');

            // Color reason column
            if (str_contains($p['reason'], 'observaciones')) {
                $sheet->getStyle('N' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_RED);
            } elseif (str_contains($p['reason'], 'Sin correlato')) {
                $sheet->getStyle('N' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_YELLOW);
            } elseif (str_contains($p['reason'], 'Pasó')) {
                $sheet->getStyle('N' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GREEN);
            }

            // Color severity
            if ($p['severity'] === 'error') {
                $sheet->getStyle('K' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_RED);
            } else {
                $sheet->getStyle('K' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_YELLOW);
            }

            // Color status
            if ($p['status'] === 'active') {
                $sheet->getStyle('L' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GREEN);
            }

            // Row-level gray tint
            $sheet->getStyle('O' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_LIGHT_GRAY);

            $rowIdx++;
        }

        $this->applyColumnWidths($sheet, $headers, [
            6, 28, 12, 8, 10, 28, 22, 50, 30, 12, 10, 10, 16, 50, 14, 30, 22, 16, 14
        ]);

        $sheet->setAutoFilter('A1:S' . ($rowIdx - 1));
        $sheet->freezePane('A2');

        // Add blank rows for manual additions
        $rowIdx += 2;
        $sheet->setCellValue('A' . $rowIdx, '—');
        $sheet->setCellValue('N' . $rowIdx, '→ Usar filas como esta para agregar reglas faltantes detectadas en Manual REM.');
        $sheet->getStyle('N' . $rowIdx)->getFont()->setItalic(true);
    }

    // ─── Estilos ───────────────────────────────────────────────────────

    private function writeHeaders(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers): void
    {
        $col = 'A';
        foreach ($headers as $header) {
            $cell = $col . '1';
            $sheet->setCellValue($cell, $header);
            $sheet->getStyle($cell)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER)
                ->setWrapText(true);
            $sheet->getStyle($cell)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF4472C4');
            $sheet->getRowDimension('1')->setRowHeight(30);
            $col++;
        }
    }

    private function setCellValueSafe(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $cell, string $value): void
    {
        if (str_starts_with($value, '=')) {
            $sheet->setCellValueExplicit($cell, ' ' . $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue($cell, $value);
        }
    }

    private function applyColumnWidths(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, array $headers, array $widths): void
    {
        $col = 'A';
        foreach ($headers as $i => $header) {
            $w = $widths[$i] ?? 12;
            $sheet->getColumnDimension($col)->setWidth($w);
            $col++;
        }
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function extractVariableName(string $label, string $letra): string
    {
        if (empty($label)) return "Columna {$letra}";
        if (preg_match('/variable\s+(.+?)\./u', $label, $m)) {
            $name = trim($m[1]);
            if (!empty($name)) return $name;
        }
        if (!str_starts_with($label, '=') && strlen($label) < 100) {
            return trim($label);
        }
        $hasSpanish = (bool) preg_match('/"[^"]{10,}"/u', $label);
        if (!$hasSpanish) return "Columna {$letra}";
        if (preg_match('/"([^"]{10,})"/u', $label, $m)) {
            $text = $m[1];
            if (preg_match('/variable\s+(.+?)\./u', $text, $vm)) {
                return trim($vm[1]);
            }
            $clean = trim(preg_replace('/^[\s\*]*/', '', $text));
            $clean = preg_replace('/[\.\s]+$/', '', $clean);
            if (strlen($clean) > 5 && strlen($clean) < 100) return $clean;
        }
        return "Columna {$letra}";
    }

    private function cleanFormula(string $label): string
    {
        if (empty($label) || !str_starts_with($label, '=')) return '';
        return str_replace('""', '', $label);
    }

    private function buildDescription(string $type, array $meta, array $config, string $variable): string
    {
        if ($type === 'sum_equals') {
            $letters = $config['source_letters'] ?? [];
            $target = $config['target_column'] ?? $meta['letra'] ?? '';
            $letterStr = !empty($letters) ? implode(' + ', array_map('strtoupper', $letters)) : 'columnas detalle';
            $rowRange = $this->buildRowRange($config);
            $rowInfo = $rowRange !== '—' ? " ({$rowRange})" : '';
            return "Valida que la suma de las columnas {$letterStr} sea igual al total de la columna {$target}{$rowInfo}. Variable: {$variable}. De lo contrario, se marca como observación.";
        }
        if ($type === 'required_and_le_parent') {
            $parentCol = $config['source_letters'][0] ?? 'B';
            return "Valida que si la columna {$parentCol} (Total) tiene valor, entonces {$variable} debe tener un valor y este no puede superar el Total. Si la variable no corresponde, debe registrarse 0.";
        }
        return '';
    }

    private function buildLogicSummary(string $type, array $config, string $variable, string $letra): string
    {
        if ($type === 'sum_equals') {
            $letters = $config['source_letters'] ?? [];
            $target = $config['target_column'] ?? '';
            $letterStr = !empty($letters) ? implode(' + ', $letters) : 'detalle';
            return "Suma({$letterStr}) = Columna {$target}";
        }
        if ($type === 'required_and_le_parent') {
            $parentSrc = $config['source_letters'][0] ?? 'B';
            $varLabel = str_contains($variable, 'Columna') ? "Columna {$letra}" : $variable;
            return "Columna {$parentSrc} > 0 AND {$varLabel} debe existir AND {$varLabel} <= Columna {$parentSrc}";
        }
        return '';
    }

    private function buildRowRange(array $config): string
    {
        $from = $config['row_from'] ?? null;
        $to = $config['row_to'] ?? null;
        if ($from && $to) {
            return $from === $to ? "Fila {$from}" : "Filas {$from}–{$to}";
        }
        if ($from) return "Fila {$from}";
        return '—';
    }

    private function buildEvidence(array $meta, string $ruleKey): string
    {
        $parts = [];
        if (!empty($meta['sheet'])) $parts[] = 'Hoja: ' . $meta['sheet'];
        if (!empty($meta['section'])) $parts[] = 'Sección: ' . $meta['section'];
        if (!empty($meta['letra'])) $parts[] = 'Celda/Col: ' . $meta['letra'];
        if (!empty($meta['source_filename'])) $parts[] = 'Archivo: ' . $meta['source_filename'];
        return !empty($parts) ? implode(', ', $parts) : 'Regla #' . $ruleKey;
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'excel_formula' => 'Estructura Excel',
            'manual' => 'Manual REM',
            default => $source,
        };
    }

    // ─── Matching ──────────────────────────────────────────────────────

    private function loadRules(?string $structureId, string $serie = 'A', int $anio = 2026): array
    {
        $bindJoin = 'JOIN rem_rule_bindings rb ON rb.rule_id = r.id AND rb.active = 1';
        $bindWhere = '';
        $params = [];

        if ($structureId) {
            $bindWhere = 'AND rb.bindable_id = ?';
            $params[] = (int) $structureId;
        } else {
            $bindWhere = 'AND rb.serie = ? AND rb.anio = ?';
            $params = [$serie, $anio];
        }

        $sql = "
            SELECT r.id, r.rule_key, r.rule_type, r.name, r.source, r.severity, r.status,
                   r.metadata, r.config, r.description, rb.serie, rb.anio, rb.bindable_id
            FROM rem_rules r
            {$bindJoin}
            WHERE 1=1 {$bindWhere}
            ORDER BY JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.sheet')),
                     JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.section')),
                     r.rule_key
        ";

        $rows = DB::select($sql, $params);
        $rules = [];
        foreach ($rows as $r) {
            $rules[] = [
                'id' => $r->id,
                'rule_key' => $r->rule_key,
                'rule_type' => $r->rule_type,
                'name' => $r->name,
                'source' => $r->source,
                'severity' => $r->severity,
                'status' => $r->status,
                'metadata' => json_decode($r->metadata, true) ?? [],
                'config' => json_decode($r->config, true) ?? [],
                'description' => $r->description,
                'serie' => $r->serie,
                'anio' => $r->anio,
                'bindable_id' => $r->bindable_id,
            ];
        }
        return $rules;
    }

    private function loadUploadInfo(string $uploadId): ?object
    {
        return DB::selectOne('SELECT * FROM rem_uploads WHERE id = ?', [(int) $uploadId]);
    }

    private function buildValidationLookup(string $uploadId): array
    {
        $results = DB::select("
            SELECT rule_key, passed, message, context
            FROM rem_validation_results
            WHERE rem_upload_id = ?
        ", [(int) $uploadId]);

        $lookup = [];
        foreach ($results as $r) {
            $key = $r->rule_key;
            if (!isset($lookup[$key])) {
                $lookup[$key] = ['total' => 0, 'passed' => 0, 'failed' => 0, 'messages' => [], 'contexts' => []];
            }
            $lookup[$key]['total']++;
            if ($r->passed) {
                $lookup[$key]['passed']++;
            } else {
                $lookup[$key]['failed']++;
                $lookup[$key]['messages'][] = $r->message;
                if ($r->context) {
                    $lookup[$key]['contexts'][] = json_decode($r->context, true) ?? [];
                }
            }
        }
        return $lookup;
    }

    private function loadFailedValidationKeys(string $uploadId): array
    {
        $results = DB::select("
            SELECT rule_key, passed, message, context
            FROM rem_validation_results
            WHERE rem_upload_id = ? AND passed = 0
            ORDER BY rule_key
        ", [(int) $uploadId]);

        $keys = [];
        foreach ($results as $r) {
            if (!isset($keys[$r->rule_key])) {
                $keys[$r->rule_key] = [
                    'rule_key' => $r->rule_key, 'failed_rows' => 0, 'messages' => [], 'contexts' => [],
                ];
            }
            $keys[$r->rule_key]['failed_rows']++;
            $keys[$r->rule_key]['messages'][] = $r->message;
            if ($r->context) {
                $keys[$r->rule_key]['contexts'][] = json_decode($r->context, true) ?? [];
            }
        }
        return array_values($keys);
    }

    private function matchRuleToValidation(array $rule, array $validationLookup): ?array
    {
        $meta = $rule['metadata'];
        $sheet = strtolower($meta['sheet'] ?? '');
        $section = strtolower($meta['section'] ?? '');
        $letra = strtolower($meta['letra'] ?? '');
        $type = $rule['rule_type'];

        if (isset($validationLookup[$rule['rule_key']])) {
            return ['validation_key' => $rule['rule_key'], 'data' => $validationLookup[$rule['rule_key']], 'match_type' => 'key_exacto'];
        }

        $typeVariants = [];
        if ($type === 'sum_equals') {
            $typeVariants[] = 'sum_equals';
        } elseif ($type === 'required_and_le_parent') {
            $typeVariants = ['required_le_', 'required_and_le_', 'required_and_le_parent'];
        }

        $bestMatch = null;

        foreach ($validationLookup as $vKey => $vData) {
            $vKeyLower = strtolower($vKey);
            if (!str_starts_with($vKeyLower, $sheet . '_')) continue;

            $typeMatch = false;
            foreach ($typeVariants as $tv) {
                if (str_contains($vKeyLower, $tv)) { $typeMatch = true; break; }
            }
            if (!$typeMatch) continue;

            if ($type === 'sum_equals') {
                $parts = explode('_', $vKeyLower);
                if (count($parts) >= 4) {
                    $vCol = $parts[1];
                    if ($vCol === $letra || $vCol === $section) {
                        $bestMatch = ['validation_key' => $vKey, 'data' => $vData, 'match_type' => 'columna_y_tipo'];
                        break;
                    }
                }
            } elseif (!isset($bestMatch)) {
                $bestMatch = ['validation_key' => $vKey, 'data' => $vData, 'match_type' => 'hoja_y_tipo_parcial'];
            }
        }

        if (!$bestMatch && $type === 'sum_equals') {
            foreach ($validationLookup as $vKey => $vData) {
                $vKeyLower = strtolower($vKey);
                if (!str_starts_with($vKeyLower, $sheet . '_')) continue;
                if (!str_contains($vKeyLower, 'sum_equals')) continue;
                $parts = explode('_', $vKeyLower);
                if (count($parts) >= 4 && $parts[1] === $section) {
                    $bestMatch = ['validation_key' => $vKey, 'data' => $vData, 'match_type' => 'seccion_y_tipo'];
                    break;
                }
            }
        }

        return $bestMatch;
    }

    // ─── Console output ────────────────────────────────────────────────

    private function printCounts(array $rules, array $validationLookup, array $failedKeys): void
    {
        $countByForm = [];
        $countByType = [];
        $countBySeverity = [];
        $matched = 0;
        $observations = 0;

        foreach ($rules as $rule) {
            $meta = $rule['metadata'];
            $form = $meta['sheet'] ?? 'Sin formulario';
            $countByForm[$form] = ($countByForm[$form] ?? 0) + 1;
            $countByType[$rule['rule_type']] = ($countByType[$rule['rule_type']] ?? 0) + 1;
            $countBySeverity[$rule['severity']] = ($countBySeverity[$rule['severity']] ?? 0) + 1;

            $m = $this->matchRuleToValidation($rule, $validationLookup);
            if ($m) {
                $matched++;
                if ($m['data']['failed'] > 0) $observations++;
            }
        }

        $this->newLine();
        $this->line('── Conteo por formulario ──');
        ksort($countByForm);
        foreach ($countByForm as $form => $cnt) {
            $this->line("  {$form}: {$cnt}");
        }

        $this->newLine();
        $this->line('── Conteo por tipo de regla ──');
        foreach ($countByType as $type => $cnt) {
            $label = $type === 'sum_equals' ? 'Suma igual al Total' : 'Requerido y menor o igual al Total';
            $this->line("  {$label}: {$cnt}");
        }

        $this->newLine();
        $this->line('── Conteo por severidad ──');
        foreach ($countBySeverity as $sev => $cnt) {
            $this->line('  ' . ($sev === 'error' ? 'Error' : 'Advertencia') . ": {$cnt}");
        }

        $this->newLine();
        $this->line('── Correlación con último REM ──');
        $this->line('  Reglas con correlato en validación: ' . $matched);
        $this->line('  Reglas con observaciones: ' . $observations);
        $this->line('  Reglas sin correlato: ' . (count($rules) - $matched));
        $this->line('  Total reglas en catálogo: ' . count($rules) . ' en ' . count($countByForm) . ' formularios');
    }
}
