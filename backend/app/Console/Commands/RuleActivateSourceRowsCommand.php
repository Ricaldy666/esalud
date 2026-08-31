<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemData;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Evaluators\SumEqualsEvaluator;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FormulaRangeCoverageAnalyzer;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3C-3A/3C-3B (CLAUDE.md punto 17.21/17.22/17.23). Activa UNA regla
 * individual de Categoria B1 (`A09/F.1`, 208/214) o B4 (`A26/B`, 393-402)
 * escribiendo `config.source_rows` + `config.total_row` (+`config.row_range`
 * SOLO para B1 -- B4 conserva su row_range real sin cambios).
 *
 * NUNCA recibe `source_rows`, `row_range` ni `total_row` como argumento --
 * los tres se derivan exclusivamente de la formula Excel real (`cell_data`),
 * reutilizando `FormulaRangeCoverageAnalyzer::analyze()` (Fase 3C-1B) sin
 * duplicar heuristico.
 *
 * DOS RAMAS DE ORIGEN, determinadas por la forma de `row_range` en config,
 * nunca por el rule_id:
 *
 * Rama B1 -- row_range={0,0} (placeholder, ej. 208/214): descubrimiento
 * propio, separado de `discoverTotalRowCandidate()` y del descubrimiento de
 * `rule:activate-category-a` (que exige cobertura COMPLETA y CONTIGUA).
 * Guard anti-periodicidad OBLIGATORIO (hallazgo real durante la validacion
 * de este comando, ver `discoverSparseSourceRows()`): `A09/I` (Categoria
 * B2/B3, patron periodico de 6 filas TOTAL por columna) comparte el MISMO
 * placeholder `row_range={0,0}` que B1 -- exige que exista EXACTAMENTE UNA
 * fila con formula en la columna dentro de todo `[filaInicioDatos+1 :
 * filaFinDatos]` (sin importar si pasa el resto de los checks) antes de
 * intentar validar nada mas; un patron periodico (6+ filas con formula en
 * la misma columna) se rechaza aqui, sin necesidad de conocer de antemano
 * cuantas filas TOTAL tiene. Solo entonces se acepta esa fila candidata si
 * su formula, en la MISMA columna, es exclusivamente hacia atras y
 * referencia un subconjunto NO VACIO (no necesariamente contiguo) de
 * `[filaInicioDatos : fila-1]`, confirmada por
 * `isEmbeddedBackwardSubtotalRow()` -- ese subconjunto, tal cual lo derive
 * la formula, se convierte en `source_rows`; `row_range` propuesto es el
 * envolvente `[min(source_rows):max(source_rows)]`.
 *
 * Rama B4 -- row_range YA real (ej. 393-402): el candidato de `total_row`
 * NUNCA se toma del diagnostico de Fase 1
 * (`RuleBindingReconciliationService::discoverTotalRowCandidate()`) --
 * hallazgo confirmado durante el diseno: esa funcion exige que TODAS las
 * filas referenciadas por la formula esten dentro de `[row_from:row_to]`
 * ("withinRange"), asi que un termino externo (ej. B4/fila 50) rompe esa
 * condicion y Fase 1 NUNCA encuentra candidato para estas 10 reglas reales
 * (confirmado: `total_row_candidate=null`, ya documentadas como "sin
 * candidato" en el punto 17.9/17.20). El candidato se deriva de forma
 * independiente aqui, en la posicion convencional trailing (`row_to+1`,
 * mismo convenio que mecanismos #8/#12 y toda Categoria A), confirmado por
 * `isEmbeddedBackwardSubtotalRow()`. Se parsea la formula real del
 * candidato: TODAS las filas de `[row_from:row_to]` deben estar
 * referenciadas (sin huecos internos) -- las filas referenciadas FUERA de
 * ese rango se agregan como terminos externos. `source_rows` = union
 * ordenada de ambos. `row_range` no se toca.
 *
 * Guards comunes a ambas ramas, en este orden (cualquier fallo aborta):
 *  1. Regla existe, activa, rule_type=sum_equals.
 *  2. Clasificacion actual = BLOCKED_BY_ENGINE_GAP.
 *  3. Hoja NO marcada 'no_utilizada'.
 *  4. config.total_row ausente.
 *  5. config.source_rows ausente.
 *  6. Candidato + source_rows descubiertos por la rama que corresponda
 *     (unico, sin ambiguedad, sin referencias a otra columna).
 *  7. total_row dentro de `[filaInicioDatos:filaFinDatos]` de la seccion
 *     viva.
 *  8. Ninguna fila de source_rows esta reclamada por OTRA seccion
 *     declarada de la misma hoja.
 *  9. source_rows valido (array no vacio, enteros positivos, sin
 *     duplicados) -- defensivo, redundante con la Rama 6 por construccion.
 *  10. Evidencia real en rem_data para CADA componente individual
 *      (source_rows menos total_row) -- deliberadamente mas estricto que
 *      `rule:activate-category-a` (que solo exige evidencia para alguna
 *      fila del rango): un termino externo nunca capturado por ningun
 *      establecimiento se rechaza explicitamente.
 *  11. Simulacion de clasificacion (config propuesto) = EXACTAMENTE
 *      SAFE_1_TO_1.
 *  12. El EVALUADOR REAL (`SumEqualsEvaluator`, ya implementado y testeado
 *      en Fase 3C-3A/3C-3B) reproduce la formula real contra TODA carga
 *      historica disponible donde la fila total persista (aunque sea
 *      fantasma, anterior al mecanismo de exclusion) -- exige al menos 1
 *      instancia y CERO fallos/errores de config entre las encontradas.
 *      Este es el guard que mitiga el riesgo central documentado en el
 *      punto 17.21 (el clasificador, por si solo, nunca verifica
 *      correccion aritmetica).
 *  13. Ausencia de colision funcional con otra regla activa (patron
 *      529 vs 530).
 *
 * NUNCA toca bindings, status, rem_data, rem_technical_totals,
 * calibraciones, estructura, el evaluador, el prefiltro ni el clasificador
 * -- solo `config.source_rows`+`config.total_row` (+`config.row_range` en
 * la Rama B1) de la regla indicada.
 */
class RuleActivateSourceRowsCommand extends Command
{
    protected $signature = 'rule:activate-source-rows
                            {rule_id : ID de la regla}
                            {--reason= : Motivo -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste source_rows/total_row(/row_range). Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Fase 3C-3A/3C-3B: activa una regla B1/B4 escribiendo source_rows/total_row derivados de la formula real (dry-run por defecto)';

    public function __construct(
        private CellDataStorageService $cellDataStorage,
        private SectionCalibrationMatrixService $calibrationMatrix,
        private SumEqualsEvaluator $evaluator,
    ) {
        parent::__construct();
    }

    public function handle(RuleBindingReconciliationService $reconciliation): int
    {
        $ruleId = (int) $this->argument('rule_id');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        $data = $this->computeAndValidate($ruleId, $reconciliation);
        if ($data === null) {
            return self::FAILURE;
        }

        $this->printReport($data);

        if (!$commit) {
            $this->newLine();
            $this->info('DRY-RUN: no se persistio ningun cambio. Ejecuta con --commit --reason=... --by=... para persistir.');

            return self::SUCCESS;
        }

        if (!$reason || !$by) {
            $this->error('--reason y --by son obligatorios para --commit.');

            return self::FAILURE;
        }

        $revalidated = $this->computeAndValidate($ruleId, $reconciliation);
        if ($revalidated === null
            || $revalidated['old_config'] !== $data['old_config']
            || $revalidated['new_config'] !== $data['new_config']
        ) {
            $this->error('La regla (o lo descubierto) cambio entre la validacion y la escritura -- abortando sin persistir.');

            return self::FAILURE;
        }

        return $this->commit($revalidated, $reason, $by);
    }

    private function computeAndValidate(int $ruleId, RuleBindingReconciliationService $reconciliation): ?array
    {
        // Guard 1.
        $rule = Rule::find($ruleId);
        if (!$rule) {
            $this->error("No existe ninguna regla con id={$ruleId}.");

            return null;
        }
        if ($rule->status !== 'active') {
            $this->error("La regla {$ruleId} no esta activa (status={$rule->status}).");

            return null;
        }
        if ($rule->rule_type !== 'sum_equals') {
            $this->error("La regla {$ruleId} no es rule_type=sum_equals (es '{$rule->rule_type}'). Fuera de alcance.");

            return null;
        }

        $structure = RemTemplateStructure::where('status', 'active')->first();
        if (!$structure) {
            $this->error('No hay ninguna estructura activa.');

            return null;
        }

        $diagnostic = $reconciliation->classifySingleRule($rule, $structure);

        // Guard 2.
        if ($diagnostic['clasificacion'] !== RuleBindingReconciliationService::BLOCKED_BY_ENGINE_GAP) {
            $this->error("La regla {$ruleId} esta clasificada '{$diagnostic['clasificacion']}', no BLOCKED_BY_ENGINE_GAP. No aplica este comando.");

            return null;
        }

        // Guard 3.
        if ($diagnostic['hoja_no_utilizada'] === true) {
            $this->error("La hoja de la regla {$ruleId} esta marcada 'no_utilizada' -- fuera de alcance de cualquier activacion funcional. No se procede.");

            return null;
        }

        $config = $rule->config;

        // Guard 4.
        if (isset($config['total_row'])) {
            $this->error("La regla {$ruleId} ya tiene total_row en config (=" . $config['total_row'] . '). No hay nada que activar.');

            return null;
        }

        // Guard 5.
        if (isset($config['source_rows'])) {
            $this->error("La regla {$ruleId} ya tiene source_rows en config. No hay nada que activar.");

            return null;
        }

        $sheet = strtoupper($config['sheet'] ?? '');
        $section = strtoupper($config['section'] ?? '');
        $column = strtoupper($config['column'] ?? '');
        $rawSection = $this->findRawSectionData($structure, $sheet, $section);
        $inicio = $rawSection['filaInicioDatos'] ?? null;
        $fin = $rawSection['filaFinDatos'] ?? null;

        $rowRange = $config['row_range'] ?? null;
        $isPlaceholderZeroZero = $rowRange !== null
            && (int) ($rowRange['from'] ?? -1) === 0
            && (int) ($rowRange['to'] ?? -1) === 0;

        // Guard 6.
        if ($isPlaceholderZeroZero) {
            $discovered = $this->discoverSparseSourceRows($sheet, $section, $column, $rawSection);
            if ($discovered === null) {
                $this->error("Regla {$ruleId} (Rama B1): row_range es el placeholder {0,0} y no se encontro un candidato UNICO cuya formula referencie un subconjunto no vacio, exclusivamente hacia atras, de {$sheet}/{$section} columna {$column}. No se procede.");

                return null;
            }
            $candidate = $discovered['total_row'];
            $sourceRows = $discovered['source_rows'];
            $newRowRange = ['from' => min($sourceRows), 'to' => max($sourceRows)];
            $rangeChanged = true;
        } else {
            if ($rowRange === null || !isset($rowRange['from'], $rowRange['to'])) {
                $this->error("La regla {$ruleId} no tiene row_range utilizable en config. Fuera de alcance.");

                return null;
            }

            // Rama B4: el candidato NUNCA se toma del diagnostico de Fase 1
            // (discoverTotalRowCandidate()) -- hallazgo confirmado durante
            // el diseno de esta rama: esa funcion exige que TODAS las filas
            // referenciadas esten dentro de [row_from:row_to] ("withinRange"),
            // asi que un termino externo (ej. B4/fila 50) rompe esa
            // condicion y Fase 1 NUNCA encuentra candidato para estas
            // reglas (confirmado: total_row_candidate=null para las 10
            // reglas reales de B4, ya documentado como "sin candidato" en
            // el punto 17.9). El candidato se deriva de forma independiente
            // aqui, en la posicion convencional trailing (row_to+1, mismo
            // convenio que mecanismos #8/#12 y toda Categoria A).
            $from = (int) $rowRange['from'];
            $to = (int) $rowRange['to'];
            $candidate = $to + 1;
            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $candidate);
            $formula = (string) ($cell['formula'] ?? '');
            if ($cell === null || ($cell['es_formula'] ?? false) !== true) {
                $this->error("La celda {$column}{$candidate} de {$sheet}/{$section} (candidato convencional row_to+1) no tiene formula real. No se procede.");

                return null;
            }
            $parsed = FormulaRangeCoverageAnalyzer::analyze($formula, $column);
            if (!empty($parsed['other_column_refs'])) {
                $this->error("La formula real en {$sheet}/{$section} {$column}{$candidate} referencia otra columna (" . implode(',', $parsed['other_column_refs']) . "). No se procede.");

                return null;
            }
            $missing = array_values(array_diff(range($from, $to), $parsed['rows']));
            if (!empty($missing)) {
                $this->error("La formula real en {$sheet}/{$section} {$column}{$candidate} NO cubre por completo el row_range declarado [{$from}:{$to}] -- faltan las filas: " . implode(',', $missing) . '. No se procede (hueco interno inesperado).');

                return null;
            }
            if (empty($parsed['rows'])) {
                $this->error("La formula real en {$sheet}/{$section} {$column}{$candidate} no referencia ninguna fila valida. No se procede.");

                return null;
            }
            if (!$this->calibrationMatrix->isEmbeddedBackwardSubtotalRow($sheet, $section, $candidate, $rawSection)) {
                $this->error("isEmbeddedBackwardSubtotalRow() no confirma la fila {$candidate} de la regla {$ruleId} como subtotal tecnico excluido (mecanismo #12). No se procede.");

                return null;
            }

            $sourceRows = $parsed['rows'];
            $newRowRange = $rowRange;
            $rangeChanged = false;
        }

        // Guard 7: total_row dentro de los limites vivos de la seccion.
        if ($inicio === null || $fin === null || $candidate < $inicio || $candidate > $fin) {
            $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} cae fuera del rango vivo de la seccion [{$inicio}:{$fin}]. No se procede.");

            return null;
        }

        // Guard 8: ninguna fila de source_rows reclamada por OTRA seccion.
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtoupper((string) ($sec['codigo'] ?? '')) === $section) {
                    continue; // la propia seccion
                }
                $secIni = $sec['filaInicioDatos'] ?? null;
                $secFin = $sec['filaFinDatos'] ?? null;
                if ($secIni === null || $secFin === null) {
                    continue;
                }
                foreach ($sourceRows as $sr) {
                    if ($sr >= (int) $secIni && $sr <= (int) $secFin) {
                        $this->error("La fila {$sr} de source_rows (regla {$ruleId}) esta reclamada por la seccion '{$sec['codigo']}' de la hoja {$sheet}. No se procede.");

                        return null;
                    }
                }
            }
        }

        // Guard 9: source_rows valido (defensivo, redundante por construccion).
        $shapeError = $this->validateSourceRowsShape($sourceRows);
        if ($shapeError !== null) {
            $this->error("source_rows derivado para la regla {$ruleId} es invalido: {$shapeError}");

            return null;
        }

        // Guard 10: evidencia real en rem_data para CADA componente (no solo
        // "alguno") -- deliberadamente mas estricto que
        // rule:activate-category-a (guard 6, que solo exige evidencia para
        // ALGUNA fila del rango): source_rows puede incluir terminos
        // externos (B4) que nunca formaron parte del rango original, asi
        // que cada uno se verifica individualmente -- un termino externo
        // sin ningun registro historico (nunca capturado por ningun
        // establecimiento) se rechaza aqui explicitamente, en vez de
        // aceptarse solo porque el resto del rango si tiene evidencia.
        $componentRows = array_values(array_diff($sourceRows, [$candidate]));
        $sectionRemData = RemData::where('section', $sheet)->get()->filter(
            fn ($r) => ($r->data['rem_section_code'] ?? null) === $section
        );
        $rowsWithEvidence = $sectionRemData->pluck('data.row_number')->map(fn ($v) => (int) $v)->unique();
        $missingEvidence = array_values(array_diff($componentRows, $rowsWithEvidence->all()));
        if (!empty($missingEvidence)) {
            $this->error("No existe evidencia real en rem_data para las filas " . implode(',', $missingEvidence) . " de source_rows (regla {$ruleId}) -- cada componente, incluidos los terminos externos, requiere al menos un registro historico. No se procede.");

            return null;
        }

        $newConfig = $config;
        $newConfig['row_range'] = $newRowRange;
        $newConfig['total_row'] = $candidate;
        $newConfig['source_rows'] = $sourceRows;

        // Guard 11: simulacion de clasificacion.
        $simulatedRule = $rule->replicate();
        $simulatedRule->id = $rule->id;
        $simulatedRule->config = $newConfig;

        $simulatedDiagnostic = $reconciliation->classifySingleRule($simulatedRule, $structure);
        if ($simulatedDiagnostic['clasificacion'] !== RuleBindingReconciliationService::SAFE_1_TO_1) {
            $this->error("Simulando la config propuesta, la regla {$ruleId} clasifica '{$simulatedDiagnostic['clasificacion']}' (motivo: {$simulatedDiagnostic['motivo']}), no SAFE_1_TO_1. No se procede.");

            return null;
        }

        // Guard 12: el evaluador REAL reproduce la formula real contra
        // evidencia historica (aunque sea fantasma, anterior al mecanismo
        // de exclusion) -- mitiga el riesgo del punto 17.21 (el
        // clasificador nunca verifica correccion aritmetica por si solo).
        $evaluatorCheck = $this->verifyAgainstRealEvaluator($sheet, $section, $column, $sourceRows, $candidate, $inicio, $fin);
        if ($evaluatorCheck['instances'] === 0) {
            $this->error("No se encontro ninguna carga historica con la fila total ({$candidate}) persistida para {$sheet}/{$section} columna {$column} -- sin evidencia para verificar el evaluador real. No se procede.");

            return null;
        }
        if ($evaluatorCheck['failures'] > 0) {
            $this->error("El evaluador real, contra la config propuesta, NO reproduce correctamente {$evaluatorCheck['failures']} de {$evaluatorCheck['instances']} cargas historicas reales. Detalle: " . json_encode(array_slice($evaluatorCheck['failure_details'], 0, 3)) . '. No se procede.');

            return null;
        }

        // Guard 13: sin colision funcional (patron 529 vs 530).
        $collision = Rule::where('status', 'active')
            ->where('id', '!=', $rule->id)
            ->get()
            ->first(function ($other) use ($sheet, $section, $column) {
                $oc = $other->config;

                return strtoupper($oc['sheet'] ?? '') === $sheet
                    && strtoupper($oc['section'] ?? '') === $section
                    && strtoupper($oc['column'] ?? '') === $column
                    && $other->rule_type === 'sum_equals';
            });
        if ($collision !== null) {
            $this->error("La regla {$ruleId} colisionaria con la regla {$collision->id} (misma clave funcional {$sheet}/{$section}/{$column}/sum_equals). No se procede.");

            return null;
        }

        return [
            'rule' => $rule,
            'rule_id' => $ruleId,
            'sheet' => $sheet,
            'section' => $section,
            'column' => $column,
            'old_config' => $config,
            'new_config' => $newConfig,
            'range_changed' => $rangeChanged,
            'candidate' => $candidate,
            'row_range' => $newRowRange,
            'source_rows' => $sourceRows,
            'structure' => $structure,
            'before_clasificacion' => $diagnostic['clasificacion'],
            'after_clasificacion' => $simulatedDiagnostic['clasificacion'],
            'evaluator_check' => $evaluatorCheck,
        ];
    }

    /**
     * Rama B1 (row_range={0,0}). Busca UNA fila candidata en
     * [filaInicioDatos+1 : filaFinDatos] cuya formula en $column sea
     * exclusivamente hacia atras y referencie un subconjunto NO VACIO de
     * [filaInicioDatos : fila-1] (no exige contiguidad -- a diferencia de
     * `RuleActivateCategoryATotalCommand::discoverZeroZeroCandidate()`,
     * que SI la exige). 0 o mas de 1 candidato => null.
     */
    private function discoverSparseSourceRows(string $sheet, string $section, string $column, ?array $rawSection): ?array
    {
        if ($rawSection === null) {
            return null;
        }

        $inicio = (int) ($rawSection['filaInicioDatos'] ?? 0);
        $fin = (int) ($rawSection['filaFinDatos'] ?? 0);
        if ($inicio <= 0 || $fin <= 0 || $fin <= $inicio) {
            return null;
        }

        if (!$this->cellDataStorage->hasCellData($sheet, $section)) {
            return null;
        }

        // Guard anti-periodicidad (hallazgo real durante la validacion de
        // este comando contra las 78 reglas reales: `A09/I`, patron
        // periodico de 6 filas TOTAL por bloque, comparte el mismo
        // placeholder row_range={0,0} que B1 -- sin este guard, el
        // descubrimiento aceptaba la PRIMERA de las 6 filas periodicas
        // como si fuera un candidato B1 valido, porque es la unica con
        // etiqueta "TOTAL" en columna A -- las otras 5 no tienen concepto
        // propio pero SI tienen formula continuando el mismo patron).
        // Antes de aceptar cualquier candidato, se exige que exista
        // EXACTAMENTE UNA fila con formula en $column en TODO el rango de
        // escaneo [filaInicioDatos+1:filaFinDatos] -- sin importar si esa
        // fila pasa o no el resto de los checks. Un patron periodico (6+
        // filas con formula en la misma columna) se rechaza aqui, sin
        // necesidad de conocer de antemano cuantas filas TOTAL tiene.
        $formulaBearingRows = [];
        for ($row = $inicio + 1; $row <= $fin; $row++) {
            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $row);
            if ($cell !== null && ($cell['es_formula'] ?? false) === true) {
                $formulaBearingRows[] = $row;
            }
        }
        if (count($formulaBearingRows) !== 1) {
            return null;
        }

        $row = $formulaBearingRows[0];
        $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $row);
        $formula = (string) ($cell['formula'] ?? '');
        $parsed = FormulaRangeCoverageAnalyzer::analyze($formula, $column);
        if (empty($parsed['rows']) || !empty($parsed['other_column_refs'])) {
            return null;
        }

        foreach ($parsed['rows'] as $r) {
            if ($r < $inicio || $r >= $row) {
                return null;
            }
        }

        if (!$this->calibrationMatrix->isEmbeddedBackwardSubtotalRow($sheet, $section, $row, $rawSection)) {
            return null;
        }

        return ['total_row' => $row, 'source_rows' => $parsed['rows']];
    }

    private function validateSourceRowsShape(array $sourceRows): ?string
    {
        if (empty($sourceRows)) {
            return 'array vacio.';
        }
        foreach ($sourceRows as $v) {
            if (!is_int($v) || $v <= 0) {
                return 'contiene un valor no entero-positivo (' . json_encode($v) . ').';
            }
        }
        if (count($sourceRows) !== count(array_unique($sourceRows))) {
            return 'contiene filas duplicadas.';
        }

        return null;
    }

    /**
     * Guard 12: ejecuta el evaluador REAL (ya implementado en Fase
     * 3C-3A/3C-3B) contra CADA carga historica real donde la fila total
     * persiste (fantasma o no) para $sheet/$section, con la config
     * propuesta. Solo lectura -- nunca persiste nada.
     */
    private function verifyAgainstRealEvaluator(string $sheet, string $section, string $column, array $sourceRows, int $totalRow, ?int $inicio, ?int $fin): array
    {
        $allRows = RemData::where('section', $sheet)->get()->filter(
            fn ($r) => ($r->data['rem_section_code'] ?? null) === $section
        );
        $byUpload = $allRows->groupBy('rem_upload_id');

        $config = [
            '_rule_key' => 'source_rows_verification',
            'scope' => 'row_range',
            'source_letters' => [$column],
            'target_column' => $column,
            'row_from' => min($sourceRows),
            'row_to' => max($sourceRows),
            'total_row' => $totalRow,
            'source_rows' => $sourceRows,
        ];
        if ($inicio !== null && $fin !== null) {
            $config['_section_bounds'] = ['inicio' => $inicio, 'fin' => $fin];
        }

        $instances = 0;
        $failures = 0;
        $failureDetails = [];
        $invalidConfigReasons = ['invalid_source_rows_configuration', 'invalid_row_range_configuration'];

        foreach ($byUpload as $uploadId => $rows) {
            $byRow = $rows->keyBy(fn ($r) => $r->data['row_number'] ?? null);
            if (!isset($byRow[$totalRow])) {
                continue;
            }

            $wanted = array_unique(array_merge($sourceRows, [$totalRow]));
            $evalRows = $rows->filter(fn ($r) => in_array((int) ($r->data['row_number'] ?? -1), $wanted, true))->values();

            $result = $this->evaluator->evaluate($config, $evalRows);
            $instances++;

            if (in_array($result->reason, $invalidConfigReasons, true) || $result->failedRows > 0) {
                $failures++;
                $failureDetails[] = ['upload_id' => $uploadId, 'reason' => $result->reason, 'failed_rows' => $result->failedRows];
            }
        }

        return ['instances' => $instances, 'failures' => $failures, 'failure_details' => $failureDetails];
    }

    private function findRawSectionData(RemTemplateStructure $structure, string $sheet, string $section): ?array
    {
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtoupper((string) ($sec['codigo'] ?? '')) === $section) {
                    return $sec;
                }
            }
        }

        return null;
    }

    private function printReport(array $d): void
    {
        $this->info("Regla {$d['rule_id']} ({$d['rule']->rule_key}) -- rama: " . ($d['range_changed'] ? 'B1 (row_range={0,0} -> descubierto)' : 'B4 (row_range real, sin cambio)'));
        $this->newLine();

        $this->line('Config ANTES:');
        $this->line('  ' . json_encode($d['old_config'], JSON_UNESCAPED_UNICODE));
        $this->line('Config PROPUESTA:');
        $this->line('  ' . json_encode($d['new_config'], JSON_UNESCAPED_UNICODE));
        $diffKeys = [];
        foreach ($d['new_config'] as $k => $v) {
            if (!array_key_exists($k, $d['old_config']) || $d['old_config'][$k] !== $v) {
                $diffKeys[] = $k;
            }
        }
        $this->line('Diff exacto (claves que cambian): ' . implode(', ', $diffKeys));
        $this->newLine();

        $cell = $this->cellDataStorage->getCellForCoordinate($d['sheet'], $d['section'], $d['column'] . $d['candidate']);
        $this->line("Formula real en {$d['sheet']}/{$d['section']} {$d['column']}{$d['candidate']}: " . json_encode($cell['formula'] ?? null));
        $this->line('source_rows derivado: [' . implode(',', $d['source_rows']) . ']');
        $this->line("row_range propuesto: [{$d['row_range']['from']}:{$d['row_range']['to']}]" . ($d['range_changed'] ? ' (CAMBIA)' : ' (sin cambio)'));
        $this->line("total_row propuesto: {$d['candidate']}");
        $this->newLine();

        $this->line("Clasificacion ANTES: {$d['before_clasificacion']}");
        $this->line("Clasificacion SIMULADA DESPUES (nada persistido): {$d['after_clasificacion']}");
        $this->newLine();

        $ec = $d['evaluator_check'];
        $this->line("Resultado del evaluador REAL contra evidencia historica: {$ec['instances']} instancias verificadas, {$ec['failures']} fallos.");
        $this->newLine();

        $bindings = RuleBinding::where('rule_id', $d['rule_id'])->get();
        if ($bindings->isEmpty()) {
            $this->line('Bindings actuales: (ninguno)');
        } else {
            $this->table(
                ['binding_id', 'bindable_type', 'bindable_id', 'active'],
                $bindings->map(fn ($b) => [$b->id, $b->bindable_type, $b->bindable_id, $b->active ? 'si' : 'no'])->all()
            );
        }
        $this->line('Este comando NUNCA crea/modifica bindings, calibraciones, rem_data, rem_technical_totals, estructura, el evaluador, el prefiltro ni el clasificador.');
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  rem_rules.id={$d['rule_id']}.config->source_rows = [" . implode(',', $d['source_rows']) . ']');
        $this->line("  rem_rules.id={$d['rule_id']}.config->total_row = {$d['candidate']}");
        if ($d['range_changed']) {
            $this->line("  rem_rules.id={$d['rule_id']}.config->row_range = [{$d['row_range']['from']}:{$d['row_range']['to']}]");
        }
        $this->line('  + 1 fila nueva en rem_rule_versions (snapshot del config ANTERIOR)');
        $this->line('  + 1 entrada en el activity log (rule_source_rows_activated)');
    }

    private function commit(array $d, string $reason, string $by): int
    {
        try {
            DB::transaction(function () use ($d, $reason, $by): void {
                $rule = Rule::find($d['rule_id']);
                if (!$rule || $rule->config !== $d['old_config']) {
                    throw new \RuntimeException('La regla cambio entre la validacion y la escritura -- abortando sin persistir.');
                }

                RuleVersion::create([
                    'rule_id' => $rule->id,
                    'version' => $rule->version,
                    'config' => $d['old_config'],
                    'changelog' => 'rule:activate-source-rows: source_rows=[' . implode(',', $d['source_rows']) . "], total_row={$d['candidate']}" . ($d['range_changed'] ? ", row_range=[{$d['row_range']['from']}:{$d['row_range']['to']}]" : '') . ". Motivo: {$reason}. Responsable: {$by}. " . now()->toIso8601String(),
                    'created_by' => null,
                ]);

                $rule->update([
                    'config' => $d['new_config'],
                    'updated_by' => null,
                ]);

                activity()
                    ->performedOn($rule)
                    ->withProperties([
                        'rule_id' => $rule->id,
                        'rule_key' => $rule->rule_key,
                        'source_rows_set' => $d['source_rows'],
                        'total_row_set' => $d['candidate'],
                        'row_range_set' => $d['row_range'],
                        'range_changed' => $d['range_changed'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_source_rows_activated');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: source_rows/total_row" . ($d['range_changed'] ? '/row_range' : '') . ' activados. RuleVersion de auditoria creado. Activity log registrado.');

        return self::SUCCESS;
    }
}
