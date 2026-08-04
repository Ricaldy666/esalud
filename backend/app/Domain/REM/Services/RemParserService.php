<?php

namespace App\Domain\REM\Services;

use App\Domain\REM\Models\RemUpload;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Support\MemoryProbe;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class RemParserService
{
    public function __construct(
        private ?CellDataStorageService $cellDataStorage = null,
        private ?ColumnRoleResolverService $columnRoleResolver = null,
    ) {
        $this->cellDataStorage = $cellDataStorage ?? new CellDataStorageService();
        $this->columnRoleResolver = $columnRoleResolver ?? new ColumnRoleResolverService();
    }

    public function parse(RemUpload $upload): ParseResult
    {
        $template = $upload->remTemplate;
        if (!$template || empty($template->config['sheets'])) {
            return new ParseResult(
                status: 'rejected',
                errors: [['type' => 'structural', 'message' => 'Template no configurado o sin hojas definidas']]
            );
        }

        $fullPath = storage_path("app/rem-uploads/{$upload->stored_path}");
        if (!file_exists($fullPath)) {
            return new ParseResult(
                status: 'failed',
                errors: [['type' => 'structural', 'message' => 'Archivo fisico no encontrado: ' . basename($fullPath)]]
            );
        }

        MemoryProbe::log('parser.antes_lectura_excel', ['upload_id' => $upload->id]);

        try {
            $spreadsheet = $this->loadSpreadsheet($fullPath);
        } catch (\Throwable $e) {
            return new ParseResult(
                status: 'failed',
                errors: [['type' => 'structural', 'message' => 'Error al abrir archivo: ' . $e->getMessage()]]
            );
        }

        MemoryProbe::log('parser.despues_lectura_excel', ['upload_id' => $upload->id]);

        $errors = [];
        $extractedData = [];
        $totalRowsProcessed = 0;
        $totalCellsParsed = 0;
        $totalErrorCells = 0;
        $config = $template->config;
        $anyDataExtracted = false;
        $sectionMaps = $this->buildSectionMaps($upload);

        MemoryProbe::log('parser.despues_build_section_maps', [
            'upload_id' => $upload->id,
            'sheets_con_mapa' => count($sectionMaps),
            'cell_data_cache_entries' => $this->cellDataStorage->cachedSectionsCount(),
        ]);

        foreach ($config['sheets'] as $sheetConfig) {
            $sheetName = $sheetConfig['sheet_name'];

            if (!$spreadsheet->sheetNameExists($sheetName)) {
                if ($sheetConfig['is_required'] ?? false) {
                    $errors[] = [
                        'type' => 'structural',
                        'sheet' => $sheetName,
                        'message' => "Hoja requerida '{$sheetName}' no encontrada en el archivo",
                    ];
                }
                continue;
            }

            $worksheet = $spreadsheet->getSheetByName($sheetName);

            try {
                $result = $this->parseSheet($worksheet, $sheetConfig, $sectionMaps[$sheetName] ?? []);
                $extractedData = array_merge($extractedData, $result['data']);
                $errors = array_merge($errors, $result['errors']);
                $totalRowsProcessed += $result['rows_processed'];
                $totalCellsParsed += $result['cells_parsed'];
                $totalErrorCells += $result['error_cells'];

                if (!empty($result['data'])) {
                    $anyDataExtracted = true;
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'type' => 'structural',
                    'sheet' => $sheetName,
                    'message' => "Error al procesar hoja '{$sheetName}': " . $e->getMessage(),
                ];
            }

            // parseSheet() puede haber vuelto a pedir (y por lo tanto
            // recacheado) el cell_data de una o mas secciones de esta hoja
            // -- mismo motivo que en buildSectionMaps(): ya no se necesita
            // una vez terminado de parsear la hoja completa, y liberarlo
            // ahora evita que el cache acumule secciones de TODAS las hojas
            // ya procesadas ademas de las 379 que ya se liberaron en
            // buildSectionMaps().
            $this->cellDataStorage->forgetSheet($sheetName);

            MemoryProbe::log('parser.despues_parseo_hoja', [
                'upload_id' => $upload->id,
                'sheet' => $sheetName,
                'extracted_data_count' => count($extractedData),
            ]);
        }

        MemoryProbe::log('parser.despues_parseo_completo', [
            'upload_id' => $upload->id,
            'extracted_data_count' => count($extractedData),
        ]);

        $spreadsheet->disconnectWorksheets();

        MemoryProbe::log('parser.despues_disconnect_worksheets', ['upload_id' => $upload->id]);

        if (!$anyDataExtracted && !empty($errors)) {
            $status = 'failed';
        } elseif (!empty($errors)) {
            $status = 'with_errors';
        } else {
            $status = 'success';
        }

        return new ParseResult(
            status: $status,
            extractedData: $extractedData,
            errors: $this->buildErrorReport($errors),
            totalRowsProcessed: $totalRowsProcessed,
            totalCellsParsed: $totalCellsParsed,
            totalErrorCells: $totalErrorCells,
        );
    }

    private function loadSpreadsheet(string $path): Spreadsheet
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setIncludeCharts(false);
        return $reader->load($path);
    }

    private function parseSheet(Worksheet $worksheet, array $sheetConfig, array $sectionMap = []): array
    {
        $structure = $sheetConfig['structure'];
        $columns = $sheetConfig['columns'] ?? [];
        $validationRules = $sheetConfig['validation_rules'] ?? [];

        $headerRow = $structure['header_row'];
        $dataStartRow = $structure['data_start_row'];
        $conceptCol = $structure['concept_column'];
        $professionalCol = $structure['professional_column'] ?? null;
        $totalCol = $structure['total_column'];
        $sectionBreakPattern = $structure['section_break_pattern'] ?? null;
        $maxDataRows = $structure['max_data_rows'] ?? 1500;

        $data = [];
        $errors = [];
        $rowsProcessed = 0;
        $cellsParsed = 0;
        $errorCells = 0;
        $lastConceptBySection = [];
        // CAPA 2 (jerarquia): herencia vertical de columnas de overflow, por
        // seccion y por columna. Cada entrada guarda el ultimo rango
        // combinado visto para esa columna y el texto resuelto para ese
        // rango -- las filas de continuacion de una misma fusion vertical
        // (ej. B183:B189 en A09/G) heredan el valor sin volver a leerlo.
        // Nunca cruza secciones (la clave incluye $sectionKey) ni hojas
        // (el array se reinicia en cada llamada a parseSheet()).
        $lastOverflowValueBySection = [];

        $columnLetters = array_map(fn($c) => $c['letter'], $columns);

        $excludeColumns = array_unique(array_filter([
            $conceptCol,
            $professionalCol,
            $totalCol,
        ]));
        $numericColumnLetters = array_values(array_filter(
            $columnLetters,
            fn($letter) => !in_array($letter, $excludeColumns, true)
        ));

        $sheetMaxRow = $worksheet->getHighestRow();
        $dataEndRow = $structure['data_end_row'] ?? null;
        if ($dataEndRow) {
            $maxRow = min($dataEndRow, $sheetMaxRow);
        } else {
            $maxRow = min($sheetMaxRow, $maxDataRows);
        }

        if (!empty($sectionMap)) {
            $dataStartRow = min(array_map(fn($section) => (int) $section['data_start_row'], $sectionMap));
            $maxRow = min($sheetMaxRow, max(array_map(fn($section) => (int) $section['data_end_row'], $sectionMap)));
        }

        for ($row = $dataStartRow; $row <= $maxRow; $row++) {
            $sectionContext = $this->findSectionContextForRow($sectionMap, $row);
            $effectiveConceptCol = $sectionContext['concept_column'] ?? $conceptCol;
            $effectiveProfessionalCol = $this->resolveEffectiveProfessionalColumn(
                $sheetConfig['sheet_name'],
                $sectionContext,
                $professionalCol,
            );
            $effectiveTotalCol = $this->resolveEffectiveTotalColumn(
                $sheetConfig['sheet_name'],
                $sectionContext,
                $totalCol,
            );
            $effectiveNumericColumns = $sectionContext['numeric_columns'] ?? $numericColumnLetters;
            $effectiveSubcategoryCol = $sectionContext['subcategory_column'] ?? null;
            $effectiveDetailCol = $sectionContext['detail_column'] ?? null;
            $effectiveOverflowColumns = $sectionContext['concept_overflow_columns'] ?? [];
            $sectionKey = $sectionContext['code'] ?? '__global';

            $conceptValue = $worksheet->getCell($effectiveConceptCol . $row)->getCalculatedValue();
            $professional = $effectiveProfessionalCol ? trim((string)($worksheet->getCell($effectiveProfessionalCol . $row)->getCalculatedValue() ?? '')) : '';
            $totalRaw = $effectiveTotalCol ? $worksheet->getCell($effectiveTotalCol . $row)->getCalculatedValue() : null;
            $total = is_numeric($totalRaw) ? (int)$totalRaw : null;

            // JERARQUIA: subcategory_column y detail_column (si existen) se
            // resuelven encadenados, cada uno con herencia vertical dentro de
            // su propio rango combinado -- mismo mecanismo que la cadena de
            // concept_overflow_columns de mas abajo, aplicado aqui a las
            // columnas ya detectadas a nivel de seccion (Capa 1). Nivel 1
            // (subcategory) se compara contra el concepto; nivel 2 (detail)
            // se compara contra el propio subcategory_column, no contra el
            // concepto -- por eso una columna de detalle fusionada con la
            // columna de actividad (no con el concepto) queda absorbida ahi.
            $sectionSubcategory = '';
            $subcategoryCellData = null;
            $subcategoryRole = null;
            if ($effectiveSubcategoryCol !== null) {
                $conceptCellDataForChain = $this->cellDataStorage->getCellForCoordinate($sheetConfig['sheet_name'], $sectionContext['code'] ?? '', $effectiveConceptCol . $row);
                $resolved = $this->resolveChainedLevel(
                    $sheetConfig['sheet_name'],
                    $sectionContext['code'] ?? '',
                    $effectiveSubcategoryCol,
                    $row,
                    $conceptCellDataForChain,
                    $worksheet,
                    $sectionKey,
                    $lastOverflowValueBySection,
                );
                $sectionSubcategory = $resolved['value'];
                $subcategoryCellData = $resolved['cellData'];
                $subcategoryRole = $resolved['role'];
            }

            $sectionDetail = '';
            $detailRole = null;
            if ($effectiveDetailCol !== null && $subcategoryCellData !== null) {
                $resolved = $this->resolveChainedLevel(
                    $sheetConfig['sheet_name'],
                    $sectionContext['code'] ?? '',
                    $effectiveDetailCol,
                    $row,
                    $subcategoryCellData,
                    $worksheet,
                    $sectionKey,
                    $lastOverflowValueBySection,
                );
                $sectionDetail = $resolved['value'];
                $detailRole = $resolved['role'];
            }

            $hasConcept = $conceptValue !== null && trim((string)$conceptValue) !== '';

            if ($hasConcept) {
                $conceptStr = trim((string)$conceptValue);

                if ($sectionBreakPattern && preg_match($sectionBreakPattern, $conceptStr)) {
                    break;
                }

                if (strtoupper($conceptStr) === 'TIPO DE CONTROL') {
                    continue;
                }

                $lastConceptBySection[$sectionKey] = $conceptStr;
            }

            // La herencia de concepto (celdas combinadas) queda acotada a la
            // propia seccion: $lastConceptBySection[$sectionKey] nunca se lee
            // fuera de esta clave, por lo que una fila de encabezado/titulo
            // (sectionKey='__global', fuera de cualquier seccion declarada) o
            // el arrastre de una seccion anterior no pueden contaminar el
            // concepto de la seccion actual. Antes existia un fallback global
            // ($lastConcept) que sobrevivia entre secciones -- eliminado.
            $currentConcept = $lastConceptBySection[$sectionKey] ?? null;

            if ($currentConcept === null && $sectionContext !== null) {
                // Todavia no hay ningun concepto real dentro de esta seccion
                // (ni de esta fila ni de una fila previa ya confirmada dentro
                // de la misma seccion) -- no se debe inventar heredando de
                // otro lado. Como unico recurso, se lee -solo para esta fila
                // puntual, sin guardarla para que la hereden filas
                // siguientes- la columna inmediata a la derecha de la columna
                // de concepto. Evidencia real (A09/G filas 179-181): el
                // encabezado de concepto de la seccion esta fusionado en un
                // rango de varias columnas (ej. A176:C178, "PROGRAMA -
                // ACTIVIDAD"), y cuando ninguna fila de agrupacion propia
                // (columna A) aplica todavia, el texto especifico de esa fila
                // vive en la columna siguiente (columna B). Generico, no
                // exclusivo de ninguna hoja/seccion. Se descarta el fallback
                // si el valor es numerico (nunca puede ser una etiqueta real
                // -- evidencia: A08/R fila 210 tenia "0" en esa columna, que
                // es un dato, no un concepto) o si esta vacio: en ese caso el
                // concepto queda como cadena vacia (nunca null), para no
                // perder la fila ni inventar un texto -- sus valores
                // numericos reales se preservan igual.
                $fallbackColumn = $this->nextColumnLetter($effectiveConceptCol);
                $fallbackValue = trim((string) ($worksheet->getCell($fallbackColumn . $row)->getCalculatedValue() ?? ''));
                $currentConcept = ($fallbackValue !== '' && !is_numeric($fallbackValue)) ? $fallbackValue : '';
            }

            if ($currentConcept === null) {
                continue;
            }

            $values = [];
            $rowHasContent = false;
            $rowErrorCells = 0;
            $rowErrors = [];
            // CAPA 2 (jerarquia): cadena de niveles de overflow para esta fila,
            // en orden (columna mas cercana al concepto primero). El primer
            // nivel resuelto va a subcategory, el resto a detail -- ver
            // ensamblado despues del bucle.
            $overflowChainValues = [];
            // El primer eslabon de la cadena se compara contra la celda de
            // concepto; cada eslabon siguiente se compara contra la celda del
            // eslabon anterior (no siempre contra el concepto) -- asi una
            // columna de detalle (ej. C) fusionada con la columna de actividad
            // (ej. B) queda absorbida en ese nivel, no en el del concepto.
            $chainParentCellData = empty($effectiveOverflowColumns)
                ? null
                : $this->cellDataStorage->getCellForCoordinate($sheetConfig['sheet_name'], $sectionContext['code'] ?? '', $effectiveConceptCol . $row);

            foreach ($effectiveNumericColumns as $colLetter) {
                // subcategory_column/detail_column: igual que concept_overflow_columns,
                // el rol se decide por fila (ver resolveChainedLevel()), no de forma fija
                // a nivel de seccion. Si esta fila concreta resulto 'subcategory' o
                // 'merged', la columna ya quedo capturada arriba (o absorbida) y no debe
                // tratarse como dato -- se omite. Si resulto 'numeric' (evidencia real:
                // A05/U columna G, fusion vertical de encabezado con celdas de datos
                // reales "0" en filas puntuales -- ver BACKLOG9), debe caer al camino
                // normal de abajo igual que cualquier columna numerica, no perderse.
                // Antes esta columna se excluia por completo de numeric_columns a nivel
                // de seccion (sectionNumericColumns()), lo que descartaba en silencio el
                // valor numerico real de esas filas puntuales.
                if ($colLetter === $effectiveSubcategoryCol && $subcategoryRole !== 'numeric') {
                    continue;
                }
                if ($colLetter === $effectiveDetailCol && $detailRole !== 'numeric') {
                    continue;
                }

                if (in_array($colLetter, $effectiveOverflowColumns, true)) {
                    // CAPA 2: dentro de esta seccion, el concepto puede fusionarse
                    // en un ancho de columnas distinto segun la fila (evidencia
                    // real: A09/C, filas simples fusionan A:C, 3 sub-bloques
                    // internos fusionan solo A:B y dejan C como celda
                    // independiente con texto real). Se resuelve por fila, no por
                    // columna fija, con herencia vertical -- ver
                    // resolveChainedLevel()/ColumnRoleResolverService.
                    $resolved = $this->resolveChainedLevel(
                        $sheetConfig['sheet_name'],
                        $sectionContext['code'],
                        $colLetter,
                        $row,
                        $chainParentCellData,
                        $worksheet,
                        $sectionKey,
                        $lastOverflowValueBySection,
                    );

                    if ($this->columnRoleResolver->isMergedRole($resolved['role'])) {
                        // Fusionada con el eslabon anterior de la cadena (el
                        // concepto, o un nivel de overflow ya resuelto en esta
                        // misma fila) -- no es un dato ni un nivel propio, se
                        // omite por completo (ni en values, ni error, ni clave
                        // "null" inutil). El padre de la cadena no cambia.
                        continue;
                    }

                    if ($this->columnRoleResolver->isSubcategoryRole($resolved['role'])) {
                        if ($resolved['value'] !== '') {
                            $overflowChainValues[] = $resolved['value'];
                        }
                        // Este nivel pasa a ser el padre del siguiente eslabon
                        // de la cadena (ej. una columna de detalle mas a la
                        // derecha que este fusionada con este nivel, no con el
                        // concepto, queda absorbida aqui).
                        $chainParentCellData = $resolved['cellData'];
                        continue;
                    }

                    // role === numerico: se comporta exactamente igual que
                    // cualquier columna numerica normal (cae al camino de
                    // abajo). El padre de la cadena no cambia -- esta columna
                    // no tiene identidad de texto propia para heredar.
                }

                $cellValue = $worksheet->getCell($colLetter . $row)->getCalculatedValue();
                $cellsParsed++;

                $validation = $this->validateCell($cellValue, $validationRules);
                if ($validation['valid']) {
                    $parsed = $validation['value'];
                } else {
                    $parsed = null;
                    $rowErrorCells++;
                    $rowErrors[] = [
                        'type' => 'data',
                        'sheet' => $sheetConfig['sheet_name'],
                        'row' => $row,
                        'column' => $colLetter,
                        'value' => $cellValue,
                        'reason' => $validation['reason'],
                    ];
                }

                if ($parsed !== null) {
                    $rowHasContent = true;
                }

                $values[$colLetter] = $parsed;
            }

            // Ensamblado final: subcategory combina subcategory_column (Capa 1,
            // con herencia vertical) y el primer nivel de la cadena de
            // concept_overflow_columns (Capa 2, columna mas cercana al
            // concepto) -- en la practica nunca coinciden, son columnas
            // distintas por construccion (concept_overflow_columns excluye
            // subcategory_column). detail combina detail_column (Capa 1
            // encadenada) y el resto de la cadena de overflow. Guarda contra
            // duplicacion: si algun nivel resuelto es identico al concepto de
            // esta fila (caso A09/G filas 179-181, donde el concepto ya se
            // resolvio leyendo esa misma columna via el fallback de mas
            // arriba), se descarta -- no se duplica el mismo texto en concept
            // y subcategory/detail.
            $subcategoryParts = [];
            if ($sectionSubcategory !== '' && $sectionSubcategory !== $currentConcept) {
                $subcategoryParts[] = $sectionSubcategory;
            }
            $chainFirstLevel = array_shift($overflowChainValues);
            if ($chainFirstLevel !== null && $chainFirstLevel !== '' && $chainFirstLevel !== $currentConcept) {
                $subcategoryParts[] = $chainFirstLevel;
            }
            $subcategory = implode(' / ', array_unique(array_filter($subcategoryParts, fn($v) => trim((string) $v) !== '')));

            $detailParts = [];
            if ($sectionDetail !== '' && $sectionDetail !== $currentConcept) {
                $detailParts[] = $sectionDetail;
            }
            foreach ($overflowChainValues as $chainValue) {
                if ($chainValue !== $currentConcept) {
                    $detailParts[] = $chainValue;
                }
            }
            $detail = implode(' / ', array_unique(array_filter($detailParts, fn($v) => trim((string) $v) !== '')));

            if ($rowHasContent && $total !== null) {
                $errorCells += $rowErrorCells;
                array_push($errors, ...$rowErrors);
            }

            if ($effectiveTotalCol) {
                $values[$effectiveTotalCol] = $total;
            }

            $rowsProcessed++;

            if (!empty($sectionMap) && $sectionContext === null) {
                // La hoja tiene mapa de secciones y esta fila no cae dentro de ninguna
                // seccion declarada: es un titulo de seccion, un sub-encabezado, una nota
                // o un separador entre secciones (ej. "SECCION B: ..."), nunca una fila de
                // datos real. No se persiste bajo ninguna circunstancia, sin importar si
                // tiene texto en la columna de concepto/profesional (que en algunas hojas,
                // como A02, es la misma columna para ambos campos).
                continue;
            }

            $shouldPersist = $rowHasContent || $total !== null || $professional !== '' || $subcategory !== '' || $detail !== '';

            if (!$shouldPersist && $sectionContext !== null) {
                $shouldPersist = $this->rowHasApplicableEditableCells(
                    $sheetConfig['sheet_name'],
                    $sectionContext['code'],
                    $row,
                    $effectiveNumericColumns,
                );
            }

            if ($shouldPersist) {
                $entry = [
                    'concept' => $currentConcept,
                    'professional' => $professional,
                    'total' => $total,
                    'values' => $values,
                    'row_number' => $row,
                ];

                if ($subcategory !== '') {
                    $entry['subcategory'] = $subcategory;
                }

                if ($detail !== '') {
                    $entry['detail'] = $detail;
                }

                if (!empty($sheetConfig['section_code'])) {
                    $entry['section'] = $sheetConfig['section_code'];
                }
                if (!empty($sectionContext['code'])) {
                    $entry['rem_section_code'] = $sectionContext['code'];
                    $entry['total_column'] = $effectiveTotalCol;
                }

                $data[] = $entry;
            }
        }

        return [
            'data' => $data,
            'errors' => $errors,
            'rows_processed' => $rowsProcessed,
            'cells_parsed' => $cellsParsed,
            'error_cells' => $errorCells,
        ];
    }

    private function buildSectionMaps(RemUpload $upload): array
    {
        $structure = RemTemplateStructure::where('anio', (int) $upload->year)
            ->where('serie', $this->serieFromRemType((string) $upload->rem_type))
            ->where('status', 'active')
            ->orderByDesc('version_number')
            ->first();

        if (!$structure) {
            return [];
        }

        $estructura = is_string($structure->estructura)
            ? json_decode($structure->estructura, true)
            : $structure->estructura;

        if (!is_array($estructura) || empty($estructura['forms'])) {
            return [];
        }

        $maps = [];
        $sectionsProcesadas = 0;
        foreach ($estructura['forms'] as $form) {
            $sheet = (string) ($form['sheetName'] ?? $form['sheet'] ?? '');
            if ($sheet === '') {
                continue;
            }

            foreach ($form['sections'] ?? [] as $section) {
                $code = (string) ($section['codigo'] ?? '');
                $start = (int) ($section['filaInicioDatos'] ?? $section['fila_inicio'] ?? 0);
                $end = (int) ($section['filaFinDatos'] ?? $section['fila_fin'] ?? 0);
                $filaHeader = (int) ($section['filaHeader'] ?? 0);
                if ($code === '' || $start <= 0 || $end <= 0) {
                    continue;
                }

                $cellRows = $this->loadSectionCellRows($sheet, $code);
                $sectionsProcesadas++;
                if ($sectionsProcesadas % 10 === 0) {
                    MemoryProbe::log('parser.build_section_maps.progreso', [
                        'upload_id' => $upload->id,
                        'secciones_procesadas' => $sectionsProcesadas,
                        'sheet_actual' => $sheet,
                        'section_actual' => $code,
                        'cell_data_cache_entries' => $this->cellDataStorage->cachedSectionsCount(),
                    ]);
                }
                $formulaTotals = $this->detectHorizontalFormulaTotals($cellRows);
                $structureTotals = $this->structureTotalColumns($section);
                $totalColumns = !empty($formulaTotals) ? $formulaTotals : $structureTotals;
                $totalColumn = $totalColumns[0] ?? null;

                $conceptColumn = $this->firstFieldWithLabel($section, ['tipo de control', 'curso', 'actividad', 'lugar']) ?? 'A';
                $professionalColumn = $this->firstProfessionalIdentifierColumn($section);
                if ($professionalColumn !== null && in_array($professionalColumn, $totalColumns, true)) {
                    $professionalColumn = null;
                }

                $subcategoryColumn = $this->detectLabelSubcategoryColumn(
                    $cellRows,
                    $section,
                    $conceptColumn,
                    $professionalColumn,
                    $totalColumns,
                    $start,
                    $end,
                );

                // CAPA 1 (fallback general): el detector anterior exige que la
                // celda quede clasificada como zona=zona_etiquetas -- correcto en
                // la mayoria de los casos, pero no cubre columnas de subcategoria
                // real cuyo escaneo las zonifico zona_captura pese a nunca estar
                // fusionadas con el concepto y nunca contener un valor numerico.
                // Se generaliza con la misma evidencia de cell_data (fusion,
                // formula, valor bruto), sin exigir una zona concreta -- ver
                // ColumnRoleResolverService::detectSubcategoryColumn().
                if ($subcategoryColumn === null) {
                    $excludedForSubcategory = array_values(array_unique(array_filter([$conceptColumn, $professionalColumn, ...$totalColumns])));
                    $subcategoryColumn = $this->columnRoleResolver->detectSubcategoryColumn(
                        $cellRows,
                        $section['fields'] ?? [],
                        $excludedForSubcategory,
                        $start,
                        $end,
                        $conceptColumn,
                    );
                }

                // JERARQUIA (tercer nivel, "detail"): igual que subcategoryColumn,
                // pero encadenada un nivel mas -- se compara contra
                // subcategoryColumn (no contra el concepto) para decidir si esta
                // fusionada con el nivel anterior. Evidencia real: A09/G
                // (columna C, "TOTAL"/"JUNJI-INTEGRA-MINEDUC"/...), A25/A.3
                // (columna C, "SUBTIPO"), A27/A/B/L (columna C). Solo se busca si
                // ya existe un subcategoryColumn -- sin nivel 2 no tiene sentido
                // buscar un nivel 3.
                $detailColumn = null;
                if ($subcategoryColumn !== null) {
                    $excludedForDetail = array_values(array_unique(array_filter([$conceptColumn, $professionalColumn, $subcategoryColumn, ...$totalColumns])));
                    $detailColumn = $this->columnRoleResolver->detectSubcategoryColumn(
                        $cellRows,
                        $section['fields'] ?? [],
                        $excludedForDetail,
                        $start,
                        $end,
                        $subcategoryColumn,
                    );
                }

                // subcategoryColumn/detailColumn NO se excluyen aqui (a diferencia de
                // conceptColumn/professionalColumn): su rol real se decide por fila en
                // parseSheet() (ver resolveChainedLevel()) -- quedan en numericColumns
                // para que, cuando una fila puntual resuelva rol 'numeric' en vez de
                // 'subcategory'/'merged', esa fila caiga al parseo numerico normal en
                // vez de perderse en silencio (evidencia real: A05/U columna G).
                $numericColumns = $this->sectionNumericColumns($section, $conceptColumn, $professionalColumn, $totalColumns);

                // CAPA 2: dentro de la misma seccion, el concepto puede fusionarse
                // en un ancho de columnas distinto segun la fila (ej. A09/C: filas
                // simples fusionan A:C, 3 sub-bloques internos fusionan solo A:B
                // y dejan C como celda independiente con texto real). Las columnas
                // que caen dentro del ancho de fusion declarado en el propio
                // encabezado (fila filaHeader), mas alla de concept_column, se
                // resuelven por fila en parseSheet() -- ver ColumnRoleResolverService.
                // Excluye subcategoryColumn/detailColumn: esas dos ya tienen su
                // propio mecanismo de cadena con herencia vertical (ver mas abajo
                // y en parseSheet()), no deben procesarse dos veces.
                $excludedForOverflow = array_values(array_unique(array_filter([$professionalColumn, $subcategoryColumn, $detailColumn, ...$totalColumns])));
                $conceptOverflowColumns = $this->columnRoleResolver->resolveConceptOverflowColumns(
                    $cellRows,
                    $filaHeader,
                    $conceptColumn,
                    $excludedForOverflow,
                );

                $maps[$sheet][] = [
                    'code' => $code,
                    'data_start_row' => $start,
                    'data_end_row' => $end,
                    'concept_column' => $conceptColumn,
                    'professional_column' => $professionalColumn,
                    'total_column' => $totalColumn,
                    'total_columns' => $totalColumns,
                    'numeric_columns' => $numericColumns,
                    'subcategory_column' => $subcategoryColumn,
                    'detail_column' => $detailColumn,
                    'concept_overflow_columns' => $conceptOverflowColumns,
                ];

                // El cell_data crudo de esta seccion (potencialmente varios MB
                // ya decodificados) ya cumplio su unico proposito aqui --
                // calcular el mapa derivado de arriba. Liberarlo ahora evita
                // que el cache de CellDataStorageService retenga las 379
                // secciones de la estructura activa simultaneamente (causa
                // confirmada de un OOM real con el limite por defecto de PHP,
                // 512M -- ver App\Support\MemoryProbe). parseSheet() puede
                // volver a pedir el cell_data de esta misma seccion mas
                // adelante (para resolver concept_overflow_columns/
                // subcategory_column/detail_column fila por fila) -- en ese
                // caso simplemente se vuelve a leer y decodificar desde
                // disco, mismo resultado, sin mantenerla en memoria mientras
                // tanto.
                $this->cellDataStorage->forgetSection($sheet, $code);
            }
        }

        return $maps;
    }

    private function serieFromRemType(string $remType): string
    {
        if (preg_match('/serie[_\s-]*([a-z])/i', $remType, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^([a-z])/i', $remType, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'A';
    }

    private function findSectionContextForRow(array $sectionMap, int $row): ?array
    {
        foreach ($sectionMap as $section) {
            if ($row >= (int) $section['data_start_row'] && $row <= (int) $section['data_end_row']) {
                return $section;
            }
        }

        return null;
    }

    /**
     * Determina si una fila vacía debe persistirse igualmente porque tiene al menos
     * una celda numérica con evidencia real (cell_data) de ser digitable: editable,
     * no bloqueada y no derivada de fórmula. Sin esa evidencia, encabezados, filas
     * TOTAL/subtotal y notas quedan excluidas porque sus celdas están bloqueadas o
     * son fórmula. Si la hoja/sección no tiene cell_data escaneado, no persiste nada
     * (comportamiento sin cambios para hojas sin cell_data).
     */
    private function rowHasApplicableEditableCells(string $sheet, string $section, int $row, array $numericColumns): bool
    {
        if (!$this->cellDataStorage->hasCellData($sheet, $section)) {
            return false;
        }

        foreach ($numericColumns as $column) {
            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $row);
            if ($cell === null) {
                continue;
            }

            if (($cell['esta_bloqueada'] ?? false) === true) {
                continue;
            }

            if (($cell['es_editable'] ?? false) !== true) {
                continue;
            }

            if (($cell['es_formula'] ?? false) === true) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function loadSectionCellRows(string $sheet, string $section): array
    {
        if (!$this->cellDataStorage->hasCellData($sheet, $section)) {
            return [];
        }

        $rows = [];
        foreach ($this->cellDataStorage->getAllCellData($sheet, $section) as $coord => $cell) {
            if (!preg_match('/^([A-Z]+)(\d+)$/', $coord, $matches)) {
                continue;
            }
            $rows[(int) $matches[2]][$matches[1]] = $cell;
        }

        return $rows;
    }

    private function detectHorizontalFormulaTotals(array $cellRows): array
    {
        $totals = [];
        foreach ($cellRows as $row => $cells) {
            foreach ($cells as $column => $cell) {
                if (!($cell['es_formula'] ?? false)) {
                    continue;
                }

                $dependencies = $cell['dependencias'] ?? [];
                $dependencyColumns = [];
                $sameRow = true;
                foreach ($dependencies as $dependency) {
                    if (!preg_match('/^([A-Z]+)(\d+)$/', strtoupper((string) $dependency), $matches)) {
                        $sameRow = false;
                        break;
                    }
                    if ((int) $matches[2] !== (int) $row) {
                        $sameRow = false;
                        break;
                    }
                    $dependencyColumns[] = $matches[1];
                }

                $dependencyColumns = array_values(array_unique($dependencyColumns));
                if ($sameRow && !empty($dependencyColumns) && $dependencyColumns !== [strtoupper((string) $column)]) {
                    $totals[] = strtoupper((string) $column);
                }
            }
        }

        return $this->sortColumns(array_values(array_unique($totals)));
    }

    private function structureTotalColumns(array $section): array
    {
        $columns = [];
        foreach ($section['fields'] ?? [] as $field) {
            if (!empty($field['esTotal'])) {
                $columns[] = strtoupper((string) ($field['letra'] ?? ''));
            }
        }

        return $this->sortColumns(array_values(array_filter(array_unique($columns))));
    }

    private function firstFieldWithLabel(array $section, array $needles): ?string
    {
        foreach ($section['fields'] ?? [] as $field) {
            $label = $this->normalizeLabel((string) ($field['label'] ?? ''));
            foreach ($needles as $needle) {
                if (str_contains($label, $needle)) {
                    return strtoupper((string) ($field['letra'] ?? ''));
                }
            }
        }

        return null;
    }

    /**
     * Detecta la columna que identifica AL profesional (quien atendio), no
     * cualquier columna cuyo encabezado simplemente mencione la palabra
     * "profesional(es)" como parte de una descripcion mas larga (ej.
     * "Atenciones por profesionales a Honorarios", "Total de profesionales
     * que participaron..."). Antes se usaba str_contains($label,'profesional')
     * sin mas contexto, lo que excluia por completo del parseo columnas de
     * captura real solo por esa coincidencia de subcadena (caso confirmado:
     * A08/A.2/AN "Atenciones por profesionales a Honorarios").
     *
     * Criterio, calibrado contra las etiquetas reales de A01-A34 (columnas
     * "PROFESIONAL" genuinas vs. las que solo mencionan la palabra de paso):
     * la etiqueta debe (a) contener "profesional"/"profesionales" como
     * palabra completa, (b) tener a lo sumo 4 palabras, y (c) no empezar con
     * una palabra de enumeracion de categoria ("un", "dos", "otro(s)", etc.)
     * -- estas ultimas señalan una columna de conteo por categoria ("Un
     * profesional", "Dos o mas profesionales", "Otros profesionales"), no un
     * identificador de profesional.
     */
    private function firstProfessionalIdentifierColumn(array $section): ?string
    {
        $enumerationStarters = ['un', 'una', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'otro', 'otros', 'otra', 'otras', 'mas', 'más'];

        foreach ($section['fields'] ?? [] as $field) {
            $label = $this->normalizeLabel((string) ($field['label'] ?? ''));
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

    /**
     * Detecta, con evidencia real de cell_data, una columna de la seccion que
     * funciona como etiqueta de sub-categoria (ej. "Riesgo Leve (N1)", "Normal")
     * en vez de un dato numerico capturable. Requiere que la celda sea
     * consistentemente bloqueada, no editable, no formula, zona=zona_etiquetas
     * (tipo_celda puede ser 'etiqueta' o 'vacio' -- la misma celda de etiqueta
     * puede aparecer sin texto en filas puntuales de un archivo real) en TODAS
     * las filas de datos observadas de la seccion -- si hay inconsistencia
     * estructural entre filas, o no hay cell_data, no se
     * clasifica (se mantiene el comportamiento actual, la columna sigue
     * intentandose parsear como numerica).
     */
    private function detectLabelSubcategoryColumn(
        array $cellRows,
        array $section,
        string $conceptColumn,
        ?string $professionalColumn,
        array $totalColumns,
        int $dataStartRow,
        int $dataEndRow,
    ): ?string {
        if (empty($cellRows)) {
            return null;
        }

        $excluded = array_unique(array_filter(array_merge(
            [$conceptColumn, $professionalColumn],
            $totalColumns,
        )));

        $candidateColumns = [];
        foreach ($section['fields'] ?? [] as $field) {
            $letter = strtoupper((string) ($field['letra'] ?? ''));
            if ($letter === '' || in_array($letter, $excluded, true) || in_array($letter, $candidateColumns, true)) {
                continue;
            }
            $candidateColumns[] = $letter;
        }

        foreach ($candidateColumns as $column) {
            $sawEvidence = false;
            $consistentLabel = true;

            foreach ($cellRows as $row => $cellsInRow) {
                if ($row < $dataStartRow || $row > $dataEndRow) {
                    continue;
                }

                $cell = $cellsInRow[$column] ?? null;
                if ($cell === null) {
                    continue;
                }

                $sawEvidence = true;

                // zona=zona_etiquetas es la senal estructural confiable (region
                // fija del template). tipo_celda puede ser 'etiqueta' (con texto
                // real en este archivo) o 'vacio' (misma celda, sin texto en esta
                // fila puntual, ej. filas iniciales de un bloque combinado) -- en
                // ambos casos sigue siendo la misma celda de etiqueta, nunca una
                // celda de captura numerica.
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

    private function sectionNumericColumns(array $section, string $conceptColumn, ?string $professionalColumn, array $totalColumns): array
    {
        $columns = [];
        $excluded = array_filter([$conceptColumn, $professionalColumn]);
        foreach ($section['fields'] ?? [] as $field) {
            $letter = strtoupper((string) ($field['letra'] ?? ''));
            if ($letter === '' || in_array($letter, $excluded, true)) {
                continue;
            }
            $columns[] = $letter;
        }

        foreach ($totalColumns as $totalColumn) {
            if (!in_array($totalColumn, $columns, true)) {
                $columns[] = $totalColumn;
            }
        }

        return $this->sortColumns(array_values(array_unique($columns)));
    }

    private function sortColumns(array $columns): array
    {
        usort($columns, fn($a, $b) => Coordinate::columnIndexFromString($a) <=> Coordinate::columnIndexFromString($b));
        return $columns;
    }

    private function nextColumnLetter(string $column): string
    {
        return Coordinate::stringFromColumnIndex(Coordinate::columnIndexFromString($column) + 1);
    }

    /**
     * Resuelve una columna de un eslabon de la cadena de jerarquia
     * (subcategory_column, detail_column, o una columna de
     * concept_overflow_columns) para una fila concreta: si esta fusionada
     * con la celda "padre" (concepto, o el eslabon anterior de la cadena),
     * queda absorbida (rol 'merged', valor vacio). Si es independiente con
     * texto real, aplica herencia vertical dentro de $lastOverflowValueBySection
     * (por seccion y por columna, nunca cruza secciones ni hojas): si esta
     * fila fusiona el mismo rango ya visto para esa columna, hereda el
     * texto de la fila ancla en vez de volver a leerlo; si es un rango
     * nuevo (o no esta fusionada), usa el valor recien leido del upload
     * real y, solo si esta fusionada, lo registra para las filas de
     * continuacion. Si esta vacia o es numerica, se trata igual que una
     * columna de datos normal (valor vacio aqui, el llamador la deja caer
     * al camino de validateCell()).
     *
     * @return array{role: string, value: string, cellData: ?array}
     */
    private function resolveChainedLevel(
        string $sheetName,
        string $sectionCode,
        string $columnLetter,
        int $row,
        ?array $parentCellData,
        Worksheet $worksheet,
        string $sectionKey,
        array &$lastOverflowValueBySection,
    ): array {
        $cellData = $this->cellDataStorage->getCellForCoordinate($sheetName, $sectionCode, $columnLetter . $row);
        $rawRead = trim((string) ($worksheet->getCell($columnLetter . $row)->getCalculatedValue() ?? ''));

        // Fusionada con el padre (concepto, o eslabon anterior de la cadena)
        // -- absorbida, no tiene identidad propia esta fila. Se evalua
        // primero, independiente de si esta fila especifica esta en blanco.
        if ($this->columnRoleResolver->isMergedRole($this->columnRoleResolver->classifyOverflowCell($cellData, $parentCellData, $rawRead))) {
            return ['role' => 'merged', 'value' => '', 'cellData' => $cellData];
        }

        $isMerged = $cellData['es_combinada'] ?? false;
        $cellRange = $cellData['rango_combinado'] ?? null;
        $tracked = $lastOverflowValueBySection[$sectionKey][$columnLetter] ?? null;

        // Herencia vertical: si esta celda fusiona un rango YA REGISTRADO
        // para esta columna (la fila ancla ya se proceso antes), hereda el
        // texto de esa fila ancla -- se evalua ANTES de mirar el valor
        // crudo de esta fila puntual, porque una fila de continuacion de
        // una fusion vertical esta necesariamente en blanco (no es su
        // fusion la que tiene el ancla) y clasificarla primero por valor
        // crudo la confundiria con una celda numerica vacia, perdiendo la
        // herencia (evidencia real: A27/A filas 13-15, continuacion de
        // B12:B15 "Nutricion").
        if ($isMerged && $cellRange !== null && $tracked !== null && $tracked['range'] === $cellRange) {
            return ['role' => 'subcategory', 'value' => $tracked['value'], 'cellData' => $cellData];
        }

        // No es continuacion de un rango ya conocido -- clasificar por el
        // valor crudo de esta fila, igual que cualquier columna nueva o
        // independiente.
        if ($rawRead !== '' && !is_numeric($rawRead)) {
            if ($isMerged && $cellRange !== null) {
                // Fila ancla nueva de una fusion vertical -- se registra
                // para que las filas de continuacion (siguientes o, en
                // teoria, ya vistas si el orden de columnas lo permitiera)
                // hereden este mismo valor.
                $lastOverflowValueBySection[$sectionKey][$columnLetter] = ['range' => $cellRange, 'value' => $rawRead];
            } else {
                // Celda independiente (no fusionada verticalmente) -- no
                // hay nada que heredar despues, se limpia cualquier rastro
                // previo de esta columna para no arrastrar un valor viejo
                // por error.
                unset($lastOverflowValueBySection[$sectionKey][$columnLetter]);
            }

            return ['role' => 'subcategory', 'value' => $rawRead, 'cellData' => $cellData];
        }

        // Vacia (sin evidencia de dato en esta fila puntual) vs. numerica real
        // (evidencia real: A05/U columna G, "0" como valor efectivamente
        // digitado) son casos distintos: una celda vacia nunca debe aparecer
        // en values (mismo comportamiento previo a este mecanismo, para no
        // reintroducir claves "fantasma" en filas que nunca tuvieron dato en
        // esta columna), mientras que un valor numerico real digitado debe
        // caer al parseo normal de mas abajo -- perderlo en silencio (como
        // ocurria antes de distinguir 'empty' de 'numeric') es la perdida de
        // dato real que este ajuste corrige.
        if ($rawRead === '') {
            return ['role' => 'empty', 'value' => '', 'cellData' => $cellData];
        }

        return ['role' => 'numeric', 'value' => '', 'cellData' => $cellData];
    }

    /**
     * Excepcion temporal y documentada al fix de fallback profesional (ver
     * resolveEffectiveProfessionalColumn()). De las 6 secciones originales
     * (auditoria A01-A34, upload de prueba #99), 5 quedaron migradas a
     * `subcategory` con la Capa 1 (deteccion general de subcategoria, ver
     * ColumnRoleResolverService::detectSubcategoryColumn()) y la Capa 2
     * (concept_overflow_columns) y se retiraron de esta lista. Solo queda
     * A19a/A.3: bloqueada por un bug PRE-EXISTENTE e independiente en la
     * deteccion de concept_column (mismo patron que A04/I.1, ver backlog
     * de concept_column) -- el campo "FAMILIA" (columna B, subcategoria
     * real) nunca llega a evaluarse porque concept_column resolvio "C"
     * ("Total Actividades", capturado por coincidencia de subcadena
     * "actividad" en firstFieldWithLabel) en vez de "A" ("Temas
     * prioridad"). Retirar esta ultima entrada cuando ese bug se corrija.
     */
    private const PROTECTED_PROFESSIONAL_FALLBACK_SECTIONS = [
        'A19a/A.3',
    ];

    private function resolveEffectiveProfessionalColumn(string $sheetName, ?array $sectionContext, ?string $legacyProfessionalCol): ?string
    {
        if ($sectionContext === null) {
            // Sin mapa de secciones para esta hoja (fuera del alcance de
            // este fix) -- se preserva el comportamiento legado tal cual.
            return $legacyProfessionalCol;
        }

        if ($sectionContext['professional_column'] !== null) {
            return $sectionContext['professional_column'];
        }

        // La deteccion real (por etiqueta "profesional") confirmo que esta
        // seccion no tiene columna profesional. El fallback de nivel de
        // hoja es un valor fijo legado (RemTemplate->config), no una
        // deteccion real -- auditoria A01-A34 confirmo que en 1110 de 1176
        // filas afectadas ese fallback solo duplica el concepto o la
        // columna TOTAL de la propia seccion. No debe usarse, salvo la
        // excepcion temporal explicita de arriba.
        $sectionKey = $sheetName . '/' . ($sectionContext['code'] ?? '');
        if (in_array($sectionKey, self::PROTECTED_PROFESSIONAL_FALLBACK_SECTIONS, true)) {
            return $legacyProfessionalCol;
        }

        return null;
    }

    /**
     * Excepcion temporal y documentada, mismo patron que
     * PROTECTED_PROFESSIONAL_FALLBACK_SECTIONS/resolveEffectiveProfessionalColumn().
     * Auditoria A01-A34 (upload de prueba #99) confirmo 51 secciones donde
     * total_columns resuelve explicitamente vacio (sin columna total real)
     * pero el fallback legado de hoja igual asignaba total_column/total --
     * en 45 de esas 51 la columna heredada ya se detecta e incluye en
     * `values` por otra via (campo declarado o formula real), por lo que
     * retirar el fallback ahi no pierde ningun dato, solo corrige una
     * etiqueta enganosa (esa columna nunca fue "el total", es una categoria
     * de captura real ya presente en `values`). Las 6 secciones de esta
     * lista son la excepcion: la columna heredada NO esta en
     * `numeric_columns` real, por lo que es la unica via por la que ese
     * dato llega a `values`/`total` -- A11a/A, A11a/H y A11a/I en
     * particular declaran unicamente el campo de concepto en
     * `section.fields` (vacio estructural, sin ningun campo de captura
     * real), perdiendo el 100% de sus datos si se retira el fallback sin
     * resguardo. Retirar cada entrada cuando su estructura se complete con
     * los campos reales faltantes (mismo camino que A09/G, A03/F).
     */
    private const PROTECTED_TOTAL_COLUMN_FALLBACK_SECTIONS = [
        'A04/H.1',
        'A11a/A',
        'A11a/H',
        'A11a/I',
        'A25/C.5',
        'A25/C.6',
    ];

    private function resolveEffectiveTotalColumn(string $sheetName, ?array $sectionContext, ?string $legacyTotalCol): ?string
    {
        if ($sectionContext === null) {
            // Sin mapa de secciones para esta hoja (fuera del alcance de
            // este fix) -- se preserva el comportamiento legado tal cual.
            return $legacyTotalCol;
        }

        if ($sectionContext['total_column'] !== null) {
            return $sectionContext['total_column'];
        }

        // La deteccion real (campos declarados + formula) confirmo que
        // esta seccion no tiene columna total. El fallback de nivel de
        // hoja es un valor fijo legado (RemTemplate->config), no una
        // deteccion real -- auditoria A01-A34 confirmo que en 45 de 51
        // secciones afectadas ese fallback solo duplica una columna de
        // captura ya presente en `values` bajo una etiqueta enganosa. No
        // debe usarse, salvo la excepcion temporal explicita de arriba.
        $sectionKey = $sheetName . '/' . ($sectionContext['code'] ?? '');
        if (in_array($sectionKey, self::PROTECTED_TOTAL_COLUMN_FALLBACK_SECTIONS, true)) {
            return $legacyTotalCol;
        }

        return null;
    }

    private function normalizeLabel(string $label): string
    {
        $normalized = strtolower(trim($label));
        $normalized = strtr($normalized, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ]);

        return $normalized;
    }

    private function validateCell(mixed $value, array $rules): array
    {
        if (is_array($value)) {
            return ['valid' => true, 'value' => null];
        }

        if ($value === null || $value === '') {
            if ($rules['allow_null'] ?? true) {
                return ['valid' => true, 'value' => null];
            }
            return ['valid' => false, 'value' => null, 'reason' => 'Valor vacio no permitido'];
        }

        $dataType = $rules['data_type'] ?? 'integer';

        if ($dataType === 'integer') {
            if (is_numeric($value)) {
                $intVal = (int)$value;
                if (is_float($value + 0) && ($value + 0) != $intVal) {
                    $intVal = (int)round($value);
                }
                $min = $rules['min'] ?? null;
                $max = $rules['max'] ?? null;
                if ($min !== null && $intVal < $min) {
                    return ['valid' => false, 'value' => $intVal, 'reason' => "Valor menor que minimo ({$min})"];
                }
                if ($max !== null && $intVal > $max) {
                    return ['valid' => false, 'value' => $intVal, 'reason' => "Valor mayor que maximo ({$max})"];
                }
                return ['valid' => true, 'value' => $intVal];
            }
            return ['valid' => false, 'value' => $value, 'reason' => 'No es un numero entero valido'];
        }

        return ['valid' => true, 'value' => $value];
    }

    private function buildErrorReport(array $errors): array
    {
        if (empty($errors)) {
            return [];
        }

        $structural = array_filter($errors, fn($e) => $e['type'] === 'structural');
        $dataErrors = array_filter($errors, fn($e) => $e['type'] === 'data');

        $report = [
            'summary' => [
                'total_errors' => count($errors),
                'structural_errors' => count($structural),
                'data_errors' => count($dataErrors),
            ],
        ];

        if (!empty($structural)) {
            $report['structural'] = array_values($structural);
        }

        if (!empty($dataErrors)) {
            $report['errors'] = array_values($dataErrors);
        }

        return $report;
    }
}
