<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemUpload;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleExecutionLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class RuleExportMasterCatalogCommand extends Command
{
    protected $signature = 'rule:export-master-catalog
        {--year=2026 : Año de las reglas}
        {--series=A,BM,D : Series a incluir}
        {--output= : Ruta de salida del archivo .xlsx}
    ';

    protected $description = 'Genera Catálogo Maestro de Reglas de Consistencia REM';

    private const HEADERS_CATALOG = [
        'ID',
        'Código técnico',
        'Serie',
        'Año',
        'Formulario REM',
        'Nombre del formulario',
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
        'Validación Estadística: Correcta / Modificar / Eliminar / Agregar externa',
        'Comentarios Estadística',
        'Acción requerida',
        'Responsable',
        'Fecha revisión',
    ];

    private const HEADERS_SUMMARY = [
        'Serie',
        'Formularios',
        'Total reglas',
        'Reglas tipo suma',
        'Reglas tipo requerido/menor igual',
        'Errores',
        'Advertencias',
        'Estado revisión',
    ];

    private const HEADERS_FAILED = [
        'Código de regla',
        'Serie',
        'Formulario',
        'Filas con observación',
        'Mensaje de error',
        'Sección',
        'Columna',
        'Concepto',
        'Valor hijo',
        'Valor padre',
        'Motivo',
    ];

    private const COLOR_RED = 'FFFCE4E4';
    private const COLOR_YELLOW = 'FFFFF9D7';
    private const COLOR_GREEN = 'FFE8F5E9';
    private const COLOR_GRAY = 'FFF5F5F5';
    private const COLOR_BLUE = 'FF4472C4';
    private const COLOR_DARK_RED = 'FFF44336';
    private const COLOR_DARK_GREEN = 'FF4CAF50';
    private const COLOR_DARK_YELLOW = 'FFFFB300';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $seriesInput = explode(',', strtoupper($this->option('series')));
        $outputPath = $this->option('output') ?? storage_path("app/catalogo_maestro_reglas_consistencia_REM_{$year}.xlsx");

        $this->info("Generando Catálogo Maestro REM {$year}...");
        $this->line("Series: " . implode(', ', $seriesInput));

        // ── Load rules ──────────────────────────────────────────────
        $allRules = [];
        $totalRules = 0;
        $seriesCounts = [];

        foreach ($seriesInput as $serie) {
            $serie = trim($serie);
            $rules = $this->loadRules($serie, $year);
            $allRules[$serie] = $rules;
            $seriesCounts[$serie] = count($rules);
            $totalRules += count($rules);
            $this->line("  Serie {$serie}: " . count($rules) . " reglas");
        }

        $expectedTotals = ['A' => 529, 'BM' => 10, 'D' => 14];
        foreach ($seriesCounts as $s => $c) {
            $expected = $expectedTotals[$s] ?? null;
            if ($expected !== null && $c !== $expected) {
                $this->warn("  Serie {$s}: esperadas {$expected}, encontradas {$c}");
            }
        }

        // ── Latest uploads per series for validation results ─────────
        $latestUploads = [];
        $validationLookups = [];
        $failedKeysBySeries = [];

        foreach ($seriesInput as $serie) {
            $serie = trim($serie);
            $latestUpload = RemUpload::where('rem_type', $serie)
                ->where('year', $year)
                ->whereIn('status', ['success', 'with_errors'])
                ->latest('id')
                ->first();

            if ($latestUpload) {
                $latestUploads[$serie] = $latestUpload;
                $validationLookups[$serie] = $this->buildValidationLookup($latestUpload->id);
                $failedKeysBySeries[$serie] = $this->loadFailedValidationKeys($latestUpload->id);
                $this->line("  Último REM {$serie}: upload #{$latestUpload->id} ({$latestUpload->original_filename})");
            } else {
                $latestUploads[$serie] = null;
                $validationLookups[$serie] = [];
                $failedKeysBySeries[$serie] = [];
                $this->warn("  Sin uploads procesados para Serie {$serie}");
            }
        }

        // ── Build spreadsheet ───────────────────────────────────────
        $this->line('  Generando hoja de cálculo...');
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("Catálogo Maestro de Reglas de Consistencia REM {$year}")
            ->setCreator('Sistema Esalud - Estadística APS')
            ->setDescription('Catálogo maestro de reglas de consistencia REM para revisión funcional del equipo de Estadística');

        // Sheet order: Portada, Instrucciones, Resumen General,
        // Catálogo Serie A, Catálogo Serie BM, Catálogo Serie D,
        // Reglas con observaciones último REM, Pendientes revisión
        $sheetIndex = 0;

        // 0. Portada
        $this->buildPortadaSheet($spreadsheet, $year, $seriesInput, $seriesCounts, $totalRules);

        // 1. Instrucciones
        $spreadsheet->createSheet();
        $sheetIndex++;
        $this->buildInstruccionesSheet($spreadsheet, $sheetIndex, $totalRules, $year);

        // 2. Resumen General
        $spreadsheet->createSheet();
        $sheetIndex++;
        $this->buildResumenGeneralSheet($spreadsheet, $sheetIndex, $allRules, $seriesInput, $validationLookups);

        // 3-5. Catálogos por serie
        foreach ($seriesInput as $serie) {
            $serie = trim($serie);
            $spreadsheet->createSheet();
            $sheetIndex++;
            $this->buildCatalogoSheet(
                $spreadsheet, $sheetIndex, $serie,
                $allRules[$serie] ?? [],
                $validationLookups[$serie] ?? [],
                $year
            );
        }

        // 6. Reglas con observaciones (último REM)
        $spreadsheet->createSheet();
        $sheetIndex++;
        $this->buildFailedRulesSheet(
            $spreadsheet, $sheetIndex,
            $failedKeysBySeries, $latestUploads, $seriesInput
        );

        // 7. Pendientes revisión
        $spreadsheet->createSheet();
        $sheetIndex++;
        $this->buildPendientesSheet(
            $spreadsheet, $sheetIndex,
            $allRules, $validationLookups, $latestUploads, $seriesInput
        );

        // ── Save ────────────────────────────────────────────────────
        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
        $spreadsheet->disconnectWorksheets();

        $this->newLine();
        $this->info('Catálogo generado: ' . $outputPath);
        $this->table(
            ['Serie', 'Reglas exportadas'],
            array_map(fn($s) => [$s, $seriesCounts[$s] ?? 0], $seriesInput)
        );
        $this->line("Total general: {$totalRules} reglas");

        return Command::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SHEET BUILDERS
    // ═══════════════════════════════════════════════════════════════════

    private function buildPortadaSheet(Spreadsheet $spreadsheet, int $year, array $series, array $counts, int $total): void
    {
        $sheet = $spreadsheet->setActiveSheetIndex(0);
        $sheet->setTitle('Portada');

        $sheet->getColumnDimension('A')->setWidth(4);
        $sheet->getColumnDimension('B')->setWidth(70);
        $sheet->getColumnDimension('C')->setWidth(4);

        $titleStyle = [
            'font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => 'FF1A237E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EAF6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ];
        $sectionStyle = [
            'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF1A237E']],
        ];
        $normalStyle = [
            'font' => ['size' => 11],
            'alignment' => ['wrapText' => true],
        ];

        $r = 4;
        $sheet->setCellValue('B' . $r, 'Catálogo Maestro de Reglas de Consistencia REM ' . $year);
        $sheet->getStyle('B' . $r)->applyFromArray($titleStyle);
        $sheet->mergeCells('B' . $r . ':C' . $r);
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Proyecto: Esalud / Estadística APS');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Series incluidas:');
        $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
        $sheet->getStyle('B' . $r)->getFont()->setBold(true);
        $r++;
        foreach ($series as $s) {
            $s = trim($s);
            $sheet->setCellValue('B' . $r, "  Serie {$s}: {$counts[$s]} reglas");
            $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
            $r++;
        }
        $r++;

        $sheet->setCellValue('B' . $r, 'Fecha de generación: ' . now()->format('d/m/Y H:i'));
        $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
        $r++;

        $sheet->setCellValue('B' . $r, 'Total de reglas en catálogo: ' . $total);
        $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
        $sheet->getStyle('B' . $r)->getFont()->setBold(true);
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Objetivo del documento:');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        $sheet->setCellValue('B' . $r, 'Documento para revisión funcional del equipo de Estadística. Permite validar si las reglas implementadas por el sistema corresponden al Manual REM y a las prácticas internas de revisión.');
        $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
        $sheet->getStyle('B' . $r)->getAlignment()->setWrapText(true);
        $sheet->mergeCells('B' . $r . ':C' . ($r + 1));
        $r += 3;

        $sheet->setCellValue('B' . $r, 'Estructura del archivo:');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        $hojas = [
            'Instrucciones' => 'Guía para la revisión por parte de Estadística APS',
            'Resumen General' => 'Totales agregados por serie, tipo de regla, severidad y estado',
            'Catálogo Serie A' => 'Todas las reglas de la Serie A - Consultas Médicas',
            'Catálogo Serie BM' => 'Todas las reglas de la Serie BM - Salud Mental',
            'Catálogo Serie D' => 'Todas las reglas de la Serie D - Discapacidad',
            'Observaciones último REM' => 'Reglas que fallaron en la última carga por serie',
            'Pendientes revisión' => 'Todas las reglas listas para que Estadística complete su revisión',
        ];
        foreach ($hojas as $nombre => $desc) {
            $sheet->setCellValue('B' . $r, "• {$nombre}: {$desc}");
            $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
            $r++;
        }
    }

    private function buildInstruccionesSheet(Spreadsheet $spreadsheet, int $index, int $totalRules, int $year): void
    {
        $sheet = $spreadsheet->setActiveSheetIndex($index);
        $sheet->setTitle('Instrucciones');

        $sheet->getColumnDimension('A')->setWidth(4);
        $sheet->getColumnDimension('B')->setWidth(90);
        $sheet->getColumnDimension('C')->setWidth(4);

        $titleStyle = [
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FF1A237E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EAF6']],
        ];
        $sectionStyle = [
            'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF1A237E']],
        ];
        $normalStyle = ['font' => ['size' => 11], 'alignment' => ['wrapText' => true]];

        $r = 2;
        $sheet->setCellValue('B' . $r, "Instrucciones para Estadística APS — Catálogo Maestro REM {$year}");
        $sheet->getStyle('B' . $r)->applyFromArray($titleStyle);
        $r += 2;

        $sheet->setCellValue('B' . $r, '¿Qué es este catálogo?');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        $sheet->setCellValue('B' . $r, "Este archivo contiene las {$totalRules} reglas de consistencia detectadas automáticamente desde las estructuras REM de las Series A, BM y D para el año {$year}. Cada regla valida relaciones entre celdas del formulario (sumas, totales, campos obligatorios). El objetivo es que el equipo de Estadística APS revise una por una antes de aprobar el motor de reglas para producción.");
        $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Este catálogo refleja las reglas actualmente implementadas en el sistema. La revisión de Estadística permitirá aprobar, corregir o complementar el motor de validación.');
        $sheet->getStyle('B' . $r)->getFont()->setItalic(true)->setSize(11);
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Instrucciones de revisión:');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;

        $instrucciones = [
            '1. Abrir la hoja del catálogo correspondiente a la serie a revisar (Catálogo Serie A, Catálogo Serie BM, Catálogo Serie D).',
            '2. Revisar cada regla en orden, verificando: formulario, sección, columna, variable, lógica y fórmula.',
            '3. Para cada regla, en las columnas de revisión (Validación Estadística, Comentarios, Acción, Responsable, Fecha), marcar:',
            '   • Correcta: la regla está bien implementada y corresponde al Manual REM.',
            '   • Modificar: la regla existe pero necesita ajuste (describir en Comentarios).',
            '   • Eliminar: la regla no corresponde a una validación real del Manual REM.',
            '   • Agregar externa: falta una regla que Estadística aplica manualmente (describirla en Comentarios).',
            '4. Si una regla del catálogo no corresponde al Manual REM, marcar "Eliminar" y explicar por qué.',
            '5. Si una regla falta en el catálogo pero existe en el Manual REM, anotarla en la hoja "Pendientes revisión" usando las filas en blanco al final, o contactar al equipo técnico para agregarla.',
            '6. Una vez revisadas todas las reglas, notificar al equipo técnico para ajustar el motor según las decisiones registradas.',
        ];
        foreach ($instrucciones as $inst) {
            $sheet->setCellValue('B' . $r, $inst);
            $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
            $r++;
        }
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Columnas de revisión (llenar por Estadística):');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        $colsRev = [
            ['Validación Estadística', 'Seleccionar: Correcta / Modificar / Eliminar / Agregar externa'],
            ['Comentarios Estadística', 'Explicar por qué requiere ajuste, eliminación o qué regla externa aplicar'],
            ['Acción requerida', 'Ej: Mantener, Ajustar rango, Corregir variable, Eliminar, Crear nueva regla'],
            ['Responsable', 'Nombre de la persona que revisa'],
            ['Fecha revisión', 'Fecha de la revisión'],
        ];
        foreach ($colsRev as $col) {
            $sheet->setCellValue('B' . $r, '• ' . $col[0] . ': ' . $col[1]);
            $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
            $r++;
        }
        $r += 2;

        $sheet->setCellValue('B' . $r, 'Leyenda de colores:');
        $sheet->getStyle('B' . $r)->applyFromArray($sectionStyle);
        $r++;
        $leyenda = [
            ['Verde', 'Correcta — regla válida y corresponde al Manual REM', self::COLOR_GREEN],
            ['Amarillo', 'Pendiente / Advertencia — requiere revisión', self::COLOR_YELLOW],
            ['Rojo', 'Observación / Error — regla con problemas o que falló en último REM', self::COLOR_RED],
            ['Gris', 'No aplica — sin datos o sin correlato', self::COLOR_GRAY],
        ];
        foreach ($leyenda as $l) {
            $sheet->setCellValue('B' . $r, "  {$l[0]} — {$l[1]}");
            $sheet->getStyle('B' . $r)->applyFromArray($normalStyle);
            $sheet->getStyle('B' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($l[2]);
            $r++;
        }
    }

    private function buildResumenGeneralSheet(Spreadsheet $spreadsheet, int $index, array $allRules, array $series, array $validationLookups): void
    {
        $sheet = $spreadsheet->setActiveSheetIndex($index);
        $sheet->setTitle('Resumen General');

        $this->writeHeaders($sheet, self::HEADERS_SUMMARY);

        $rowIdx = 2;
        $totals = array_fill_keys(array_slice(self::HEADERS_SUMMARY, 1), 0);

        foreach ($series as $serie) {
            $serie = trim($serie);
            $rules = $allRules[$serie] ?? [];

            $forms = [];
            $sumEquals = 0;
            $required = 0;
            $errors = 0;
            $warnings = 0;

            foreach ($rules as $r) {
                $meta = $r['metadata'];
                $sheetName = $meta['sheet'] ?? '';
                if ($sheetName) $forms[$sheetName] = true;
                if ($r['rule_type'] === 'sum_equals') $sumEquals++;
                else $required++;
                if ($r['severity'] === 'error') $errors++;
                else $warnings++;
            }

            $revisionStatus = $this->computeRevisionStatus($serie, $validationLookups, $errors);

            $sheet->setCellValue('A' . $rowIdx, $serie);
            $sheet->setCellValue('B' . $rowIdx, count($forms));
            $sheet->setCellValue('C' . $rowIdx, count($rules));
            $sheet->setCellValue('D' . $rowIdx, $sumEquals);
            $sheet->setCellValue('E' . $rowIdx, $required);
            $sheet->setCellValue('F' . $rowIdx, $errors);
            $sheet->setCellValue('G' . $rowIdx, $warnings);
            $sheet->setCellValue('H' . $rowIdx, $revisionStatus);

            $totals['Formularios'] += count($forms);
            $totals['Total reglas'] += count($rules);
            $totals['Reglas tipo suma'] += $sumEquals;
            $totals['Reglas tipo requerido/menor igual'] += $required;
            $totals['Errores'] += $errors;
            $totals['Advertencias'] += $warnings;

            if ($revisionStatus === 'Pendiente') {
                $sheet->getStyle('H' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_YELLOW);
            } elseif ($revisionStatus === 'Con observaciones') {
                $sheet->getStyle('H' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_RED);
            } elseif ($revisionStatus === 'Correcto') {
                $sheet->getStyle('H' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GREEN);
            }

            $rowIdx++;
        }

        // Total row
        $sheet->setCellValue('A' . $rowIdx, 'TOTAL');
        $sheet->getStyle('A' . $rowIdx)->getFont()->setBold(true);
        $colLetters = ['B', 'C', 'D', 'E', 'F', 'G', 'H'];
        foreach (array_slice(self::HEADERS_SUMMARY, 1) as $i => $h) {
            $sheet->setCellValue($colLetters[$i] . $rowIdx, $totals[$h]);
            $sheet->getStyle($colLetters[$i] . $rowIdx)->getFont()->setBold(true);
        }

        $this->applyColumnWidths($sheet, self::HEADERS_SUMMARY, [8, 14, 12, 18, 24, 10, 12, 22]);
        $sheet->setAutoFilter('A1:H' . $rowIdx);
        $sheet->freezePane('A2');
    }

    private function buildCatalogoSheet(Spreadsheet $spreadsheet, int $index, string $serie, array $rules, array $validationLookup, int $year): void
    {
        $sheet = $spreadsheet->setActiveSheetIndex($index);
        $sheet->setTitle("Catálogo Serie {$serie}");

        $this->writeHeaders($sheet, self::HEADERS_CATALOG);

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
                $validationResult = $vData['failed'] > 0 ? 'Observación' : 'Correcto';
                if (!empty($vData['messages'])) {
                    $sysMessage = implode(' | ', array_unique(array_slice($vData['messages'], 0, 3)));
                    if (count($vData['messages']) > 3) {
                        $sysMessage .= ' (... y ' . (count($vData['messages']) - 3) . ' más)';
                    }
                }
            } elseif (!empty($validationLookup)) {
                $validationResult = 'Sin correlato en REM probado';
            }

            $typeLabel = $type === 'sum_equals' ? 'Suma igual al Total' : 'Requerido y menor o igual al Total';
            $severityLabel = $rule['severity'] === 'error' ? 'Error' : 'Advertencia';
            $statusLabel = match ($rule['status']) { 'active' => 'Activa', 'inactive' => 'Inactiva', default => 'Deprecada' };
            $formName = $this->getFormName($sheetName);

            $sheet->setCellValue('A' . $rowIdx, $rule['id']);
            $sheet->setCellValue('B' . $rowIdx, $rule['rule_key']);
            $sheet->setCellValue('C' . $rowIdx, $serie);
            $sheet->setCellValue('D' . $rowIdx, $year);
            $sheet->setCellValue('E' . $rowIdx, $sheetName);
            $sheet->setCellValue('F' . $rowIdx, $formName);
            $sheet->setCellValue('G' . $rowIdx, $section);
            $sheet->setCellValue('H' . $rowIdx, $letra);
            $sheet->setCellValue('I' . $rowIdx, $variableName);
            $sheet->setCellValue('J' . $rowIdx, $typeLabel);
            $this->setCellValueSafe($sheet, 'K' . $rowIdx, $description);
            $this->setCellValueSafe($sheet, 'L' . $rowIdx, $logicSummary);
            $this->setCellValueSafe($sheet, 'M' . $rowIdx, $formula);
            $sheet->setCellValue('N' . $rowIdx, $rowRange);
            $sheet->setCellValue('O' . $rowIdx, $severityLabel);
            $sheet->setCellValue('P' . $rowIdx, $statusLabel);
            $sheet->setCellValue('Q' . $rowIdx, $sourceLabel);
            $this->setCellValueSafe($sheet, 'R' . $rowIdx, $evidence);
            $sheet->setCellValue('S' . $rowIdx, $validationResult);
            $sheet->setCellValue('T' . $rowIdx, $totalRows);
            $sheet->setCellValue('U' . $rowIdx, $failedRows);
            $this->setCellValueSafe($sheet, 'V' . $rowIdx, $sysMessage);

            // Review columns
            $sheet->setCellValue('W' . $rowIdx, '');
            $sheet->setCellValue('X' . $rowIdx, '');
            $sheet->setCellValue('Y' . $rowIdx, '');
            $sheet->setCellValue('Z' . $rowIdx, '');
            $sheet->setCellValue('AA' . $rowIdx, '');
            $sheet->setCellValue('AB' . $rowIdx, '');

            // Colors
            if ($severityLabel === 'Error') {
                $sheet->getStyle('O' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_RED);
                $sheet->getStyle('O' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_RED);
            } else {
                $sheet->getStyle('O' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_YELLOW);
                $sheet->getStyle('O' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_YELLOW);
            }

            if ($statusLabel === 'Activa') {
                $sheet->getStyle('P' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GREEN);
                $sheet->getStyle('P' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_GREEN);
            }

            if ($validationResult === 'Observación') {
                $sheet->getStyle('S' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_RED);
                $sheet->getStyle('S' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_RED);
            } elseif ($validationResult === 'Correcto') {
                $sheet->getStyle('S' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GREEN);
                $sheet->getStyle('S' . $rowIdx)->getFont()->getColor()->setARGB(self::COLOR_DARK_GREEN);
            } elseif ($validationResult === 'Sin correlato en REM probado') {
                $sheet->getStyle('S' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GRAY);
            }

            // Review columns ready to fill
            $sheet->getStyle('W' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GRAY);

            $rowIdx++;
        }

        $this->applyColumnWidths($sheet, self::HEADERS_CATALOG, [
            6, 28, 6, 6, 14, 36, 10, 8, 30, 24, 50, 30, 30, 12, 10, 10, 18, 24, 24, 10, 14, 40, 18, 30, 22, 16, 14,
        ]);
        $sheet->setAutoFilter('A1:AB' . ($rowIdx - 1));
        $sheet->freezePane('A2');
    }

    private function buildFailedRulesSheet(Spreadsheet $spreadsheet, int $index, array $failedKeysBySeries, array $latestUploads, array $series): void
    {
        $sheet = $spreadsheet->setActiveSheetIndex($index);
        $sheet->setTitle('Observaciones último REM');

        $this->writeHeaders($sheet, self::HEADERS_FAILED);

        $rowIdx = 2;
        $totalObs = 0;

        foreach ($series as $serie) {
            $serie = trim($serie);
            $failedKeys = $failedKeysBySeries[$serie] ?? [];
            $upload = $latestUploads[$serie] ?? null;

            if (empty($failedKeys)) {
                $sheet->setCellValue('A' . $rowIdx, "Serie {$serie}: sin observaciones en último REM");
                $sheet->getStyle('A' . $rowIdx)->getFont()->setItalic(true);
                $sheet->getStyle('A' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GREEN);
                $rowIdx++;
                continue;
            }

            foreach ($failedKeys as $fk) {
                $sheet->setCellValue('A' . $rowIdx, $fk['rule_key']);
                $sheet->setCellValue('B' . $rowIdx, $serie);

                $sheetName = $fk['sheet'] ?? '';
                $sheet->setCellValue('C' . $rowIdx, $sheetName);
                $sheet->setCellValue('D' . $rowIdx, $fk['failed_rows']);
                $firstMsg = $fk['messages'][0] ?? '';
                $this->setCellValueSafe($sheet, 'E' . $rowIdx, $firstMsg);

                $firstCtx = $fk['contexts'][0] ?? [];
                if ($firstCtx) {
                    $sheet->setCellValue('F' . $rowIdx, $firstCtx['section'] ?? '');
                    $childCol = $firstCtx['child_column'] ?? '';
                    $parentCol = $firstCtx['parent_column'] ?? '';
                    $sheet->setCellValue('G' . $rowIdx, $childCol . ' / ' . $parentCol);
                    $sheet->setCellValue('H' . $rowIdx, $firstCtx['concept'] ?? '');
                    $sheet->setCellValue('I' . $rowIdx, $firstCtx['child_value'] ?? '');
                    $sheet->setCellValue('J' . $rowIdx, $firstCtx['parent_value'] ?? '');
                    $sheet->setCellValue('K' . $rowIdx, $firstCtx['reason'] ?? '');
                }

                for ($col = 'A'; $col <= 'K'; $col++) {
                    $sheet->getStyle($col . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_RED);
                }
                $totalObs += $fk['failed_rows'];
                $rowIdx++;
            }
        }

        $this->applyColumnWidths($sheet, self::HEADERS_FAILED, [28, 6, 14, 16, 60, 12, 18, 24, 12, 12, 16]);
        $sheet->setAutoFilter('A1:K' . ($rowIdx - 1));
        $sheet->freezePane('A2');

        $rowIdx += 2;
        $sheet->setCellValue('A' . $rowIdx, 'Resumen de observaciones:');
        $sheet->getStyle('A' . $rowIdx)->getFont()->setBold(true);
        $rowIdx++;
        foreach ($series as $serie) {
            $serie = trim($serie);
            $upload = $latestUploads[$serie] ?? null;
            $count = count($failedKeysBySeries[$serie] ?? []);
            $sheet->setCellValue('A' . $rowIdx, "Serie {$serie}: {$count} reglas con observaciones" . ($upload ? " (upload #{$upload->id})" : ""));
            $sheet->getStyle('A' . $rowIdx)->applyFromArray(['font' => ['size' => 11]]);
            $rowIdx++;
        }
        $sheet->setCellValue('A' . $rowIdx, "Total filas con observación: {$totalObs}");
        $sheet->getStyle('A' . $rowIdx)->getFont()->setBold(true);
    }

    private function buildPendientesSheet(Spreadsheet $spreadsheet, int $index, array $allRules, array $validationLookups, array $latestUploads, array $series): void
    {
        $sheet = $spreadsheet->setActiveSheetIndex($index);
        $sheet->setTitle('Pendientes revisión');

        $pendHeaders = [
            'ID',
            'Código técnico',
            'Serie',
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
            'Resultado último REM',
            'Motivo pendiente',
            'Validación Estadística: Correcta / Modificar / Eliminar / Agregar externa',
            'Comentarios Estadística',
            'Acción requerida',
            'Responsable',
            'Fecha revisión',
        ];

        $this->writeHeaders($sheet, $pendHeaders);

        $rowIdx = 2;
        foreach ($series as $serie) {
            $serie = trim($serie);
            $rules = $allRules[$serie] ?? [];
            $lookup = $validationLookups[$serie] ?? [];

            foreach ($rules as $rule) {
                $meta = $rule['metadata'];
                $config = $rule['config'];

                $match = $this->matchRuleToValidation($rule, $lookup);
                $reason = 'Pendiente de revisión funcional';
                $resultText = '';

                if (!$match && !empty($lookup)) {
                    $reason = 'Sin correlato en último REM';
                    $resultText = 'Sin datos';
                } elseif ($match) {
                    if ($match['data']['failed'] > 0) {
                        $reason = 'Presentó observaciones en último REM';
                        $resultText = 'Observación';
                    } else {
                        $reason = 'Pasó validación técnica, pendiente revisión funcional';
                        $resultText = 'Correcto';
                    }
                }

                $variableName = $this->extractVariableName($meta['label'] ?? '', $meta['letra'] ?? '');
                $typeLabel = $rule['rule_type'] === 'sum_equals' ? 'Suma igual al Total' : 'Requerido y menor o igual al Total';

                $sheet->setCellValue('A' . $rowIdx, $rule['id']);
                $sheet->setCellValue('B' . $rowIdx, $rule['rule_key']);
                $sheet->setCellValue('C' . $rowIdx, $serie);
                $sheet->setCellValue('D' . $rowIdx, $meta['sheet'] ?? '');
                $sheet->setCellValue('E' . $rowIdx, $meta['section'] ?? '');
                $sheet->setCellValue('F' . $rowIdx, $meta['letra'] ?? '');
                $sheet->setCellValue('G' . $rowIdx, $variableName);
                $sheet->setCellValue('H' . $rowIdx, $typeLabel);
                $this->setCellValueSafe($sheet, 'I' . $rowIdx, $this->buildDescription($rule['rule_type'], $meta, $config, $variableName));
                $this->setCellValueSafe($sheet, 'J' . $rowIdx, $this->buildLogicSummary($rule['rule_type'], $config, $variableName, $meta['letra'] ?? ''));
                $sheet->setCellValue('K' . $rowIdx, $this->buildRowRange($config));
                $sheet->setCellValue('L' . $rowIdx, $rule['severity'] === 'error' ? 'Error' : 'Advertencia');
                $sheet->setCellValue('M' . $rowIdx, $rule['status'] === 'active' ? 'Activa' : 'Inactiva');
                $sheet->setCellValue('N' . $rowIdx, $this->sourceLabel($rule['source']));
                $sheet->setCellValue('O' . $rowIdx, $resultText);
                $this->setCellValueSafe($sheet, 'P' . $rowIdx, $reason);

                // Review columns — empty for Estadística to fill
                $sheet->setCellValue('Q' . $rowIdx, '');
                $sheet->setCellValue('R' . $rowIdx, '');
                $sheet->setCellValue('S' . $rowIdx, '');
                $sheet->setCellValue('T' . $rowIdx, '');
                $sheet->setCellValue('U' . $rowIdx, '');

                // Color rows
                if (str_contains($reason, 'observaciones')) {
                    $sheet->getStyle('P' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_RED);
                } elseif (str_contains($reason, 'Sin correlato')) {
                    $sheet->getStyle('P' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_YELLOW);
                } elseif (str_contains($reason, 'Pasó')) {
                    $sheet->getStyle('P' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GREEN);
                }

                if ($rule['severity'] === 'error') {
                    $sheet->getStyle('L' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_RED);
                } else {
                    $sheet->getStyle('L' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_YELLOW);
                }

                if ($rule['status'] === 'active') {
                    $sheet->getStyle('M' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GREEN);
                }

                // Review columns gray
                $sheet->getStyle('Q' . $rowIdx)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::COLOR_GRAY);

                $rowIdx++;
            }
        }

        $this->applyColumnWidths($sheet, $pendHeaders, [
            6, 28, 6, 14, 10, 8, 28, 22, 50, 30, 12, 10, 10, 16, 14, 50, 18, 30, 22, 16, 14,
        ]);
        $sheet->setAutoFilter('A1:U' . ($rowIdx - 1));
        $sheet->freezePane('A2');

        // Blank rows for manual additions
        $rowIdx += 2;
        $sheet->setCellValue('A' . $rowIdx, '');
        $sheet->setCellValue('P' . $rowIdx, '→ Usar filas como esta para agregar reglas faltantes detectadas en Manual REM');
        $sheet->getStyle('P' . $rowIdx)->getFont()->setItalic(true);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  STYLING HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function writeHeaders($sheet, array $headers): void
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
                ->getStartColor()->setARGB(self::COLOR_BLUE);
            $sheet->getRowDimension('1')->setRowHeight(30);
            $col++;
        }
    }

    private function setCellValueSafe($sheet, string $cell, string $value): void
    {
        if (str_starts_with($value, '=')) {
            $sheet->setCellValueExplicit($cell, ' ' . $value, DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue($cell, $value);
        }
    }

    private function applyColumnWidths($sheet, array $headers, array $widths): void
    {
        $col = 'A';
        foreach ($headers as $i => $header) {
            $w = $widths[$i] ?? 12;
            $sheet->getColumnDimension($col)->setWidth($w);
            $col++;
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DATA LOADING
    // ═══════════════════════════════════════════════════════════════════

    private function loadRules(string $serie, int $year): array
    {
        $sql = "
            SELECT r.id, r.rule_key, r.rule_type, r.name, r.source, r.severity, r.status,
                   r.metadata, r.config, r.description, rb.serie, rb.anio
            FROM rem_rules r
            JOIN rem_rule_bindings rb ON rb.rule_id = r.id AND rb.active = 1
            WHERE rb.serie = ? AND rb.anio = ? AND r.status = 'active'
            ORDER BY JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.sheet')),
                     JSON_UNQUOTE(JSON_EXTRACT(r.metadata, '$.section')),
                     r.rule_key
        ";

        $rows = DB::select($sql, [$serie, $year]);

        $rules = [];
        foreach ($rows as $r) {
            $meta = json_decode($r->metadata, true) ?? [];
            $config = json_decode($r->config, true) ?? [];
            $rules[] = [
                'id' => $r->id,
                'rule_key' => $r->rule_key,
                'rule_type' => $r->rule_type,
                'name' => $r->name,
                'source' => $r->source ?? 'excel_formula',
                'severity' => $r->severity,
                'status' => $r->status,
                'metadata' => $meta,
                'config' => $config,
                'description' => $r->description,
                'serie' => $r->serie,
                'anio' => $r->anio,
            ];
        }

        return $rules;
    }

    private function buildValidationLookup(int $uploadId): array
    {
        $logs = RuleExecutionLog::where('rem_upload_id', $uploadId)
            ->with('rule')
            ->get();

        $lookup = [];
        foreach ($logs as $log) {
            if (!$log->rule) continue;
            $key = $log->rule->rule_key;
            if (!isset($lookup[$key])) {
                $lookup[$key] = ['total' => 0, 'failed' => 0, 'messages' => [], 'contexts' => []];
            }
            $lookup[$key]['total'] += $log->total_rows;
            $lookup[$key]['failed'] += $log->failed_rows;
        }

        // Load RemValidationResult for messages and context
        $results = DB::table('rem_validation_results')
            ->where('rem_upload_id', $uploadId)
            ->get();

        foreach ($results as $r) {
            $key = $r->rule_key;
            if (!isset($lookup[$key])) continue;
            if ($r->message) {
                $lookup[$key]['messages'][] = $r->message;
            }
            $ctx = json_decode($r->context ?? '{}', true);
            if (!empty($ctx['details'])) {
                foreach ($ctx['details'] as $detail) {
                    $lookup[$key]['contexts'][] = $detail;
                }
            }
        }

        return $lookup;
    }

    private function loadFailedValidationKeys(int $uploadId): array
    {
        $logs = RuleExecutionLog::where('rem_upload_id', $uploadId)
            ->where('status', 'failed')
            ->with('rule')
            ->get();

        $failed = [];
        foreach ($logs as $log) {
            if (!$log->rule) continue;
            $key = $log->rule->rule_key;
            $meta = $log->rule->metadata ? (is_string($log->rule->metadata) ? json_decode($log->rule->metadata, true) : $log->rule->metadata) : [];

            if (!isset($failed[$key])) {
                $failed[$key] = [
                    'rule_key' => $key,
                    'failed_rows' => 0,
                    'messages' => [],
                    'contexts' => [],
                    'sheet' => $meta['sheet'] ?? '',
                ];
            }
            $failed[$key]['failed_rows'] += $log->failed_rows;
        }

        // Load messages and contexts from RemValidationResult
        $results = DB::table('rem_validation_results')
            ->where('rem_upload_id', $uploadId)
            ->where('passed', false)
            ->get();

        foreach ($results as $r) {
            $key = $r->rule_key;
            if (!isset($failed[$key])) continue;
            if ($r->message) {
                $failed[$key]['messages'][] = $r->message;
            }
            $ctx = json_decode($r->context ?? '{}', true);
            if (!empty($ctx['details'])) {
                foreach ($ctx['details'] as $detail) {
                    $failed[$key]['contexts'][] = $detail;
                }
            }
        }

        return array_values($failed);
    }

    private function matchRuleToValidation(array $rule, array $validationLookup): ?array
    {
        $key = $rule['rule_key'];
        if (isset($validationLookup[$key])) {
            return [
                'validation_key' => $key,
                'data' => $validationLookup[$key],
            ];
        }
        return null;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  FORMATTING HELPERS
    // ═══════════════════════════════════════════════════════════════════

    private function extractVariableName(string $label, string $letra): string
    {
        if (empty($label)) return "Columna {$letra}";
        if (!str_starts_with($label, '=') && strlen($label) < 100) {
            return trim($label);
        }
        $hasSpanish = (bool) preg_match('/"[^"]{10,}"/u', $label);
        if (!$hasSpanish) return "Columna {$letra}";
        if (preg_match('/"([^"]{10,})"/u', $label, $m)) {
            $text = $m[1];
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

    private function getFormName(string $sheetName): string
    {
        $names = [
            'A01' => 'Consultas Médicas por Tipo y Profesional',
            'A02' => 'Consultas de Especialidades',
            'A03' => 'Consultas de Urgencia',
            'A04' => 'Atenciones Domiciliarias',
            'A05' => 'Atenciones Odontológicas',
            'A06' => 'Procedimientos de Enfermería',
            'A07' => 'Procedimientos de Inmunizaciones',
            'A08' => 'Exámenes de Diagnóstico',
            'A09' => 'Procedimientos de Imagenología',
            'A10' => 'Procedimientos Quirúrgicos',
            'A11' => 'Procedimientos de Rehabilitación',
            'A12' => 'Consultas de Salud Mental',
            'A13' => 'Prestaciones de Alimentación',
            'A14' => 'Prestaciones Farmacéuticas',
            'A15' => 'Procedimientos Generales APS',
            'A16' => 'Actividades Colectivas',
            'A17' => 'Visitas Domiciliarias Integrales',
            'A18' => 'Procedimientos de Saneamiento Ambiental',
            'A19' => 'Prestaciones del Programa Nacional de Alimentación',
            'A20' => 'Prestaciones del Programa Nacional de Inmunizaciones',
            'A21' => 'Prestaciones del Programa de Tuberculosis',
            'A22' => 'Prestaciones del Programa de VIH/SIDA',
            'A23' => 'Prestaciones del Programa Cardiovascular',
            'A24' => 'Prestaciones del Programa de Diabetes',
            'A25' => 'Prestaciones del Programa de Salud Integral del Adulto',
            'A26' => 'Prestaciones del Programa de Salud Infantil',
            'A27' => 'Prestaciones del Programa de Salud del Adolescente',
            'A28' => 'Prestaciones del Programa de la Mujer',
            'A29' => 'Prestaciones del Programa de Discapacidad',
            'A30' => 'Prestaciones del Programa de Dependencia Severa',
            'A31' => 'Prestaciones del Programa de Cuidados Paliativos',
            'A32' => 'Prestaciones del Programa de Salud Rural',
            'A33' => 'Prestaciones del Programa de Salud Intercultural',
            'A34' => 'Prestaciones de Atención Primaria Oftalmológica',
            'A35' => 'Procedimientos de Atención Primaria',
            'BM18' => 'Exámenes de Diagnóstico y Procedimientos',
            'BM18A' => 'Exámenes de Diagnóstico Detallado',
            'D15' => 'Prestaciones de Discapacidad',
            'D16' => 'Programa de Alimentación Complementaria',
        ];
        return $names[$sheetName] ?? '';
    }

    private function computeRevisionStatus(string $serie, array $validationLookups, int $errors): string
    {
        $lookup = $validationLookups[$serie] ?? [];
        if (empty($lookup)) return 'Pendiente';

        $hasFailed = false;
        foreach ($lookup as $v) {
            if ($v['failed'] > 0) {
                $hasFailed = true;
                break;
            }
        }

        if ($hasFailed) return 'Con observaciones';
        return 'Correcto';
    }
}
