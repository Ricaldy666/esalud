<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\FormulaRangeCoverageAnalyzer;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 4 (CLAUDE.md punto 17.42) -- generalizacion CONSERVADORA de
 * `rule:expand-b2-aggregation` (17.38-17.40, renombrado porque su nombre
 * original ya no reflejaria su alcance real). Crea UNA regla nueva,
 * derivada de una de las 9 reglas origen de `A09/I` con placeholder
 * row_range={0,0} (`226,227,228,229,230,231,232,233,234` -- columnas
 * AM/AN/AQ/AR/AS/AT/AU/AV/AX), representando UNA de sus posiciones
 * periodicas reales (filas TOTAL 331-336), reutilizando EXACTAMENTE el
 * mismo mecanismo `source_rows`+`total_row` ya implementado en Fase
 * 3C-3A/3C-3B (17.22/17.23) y el gate de identidad full-signature (17.37)
 * -- SIN crear un segundo mecanismo, SIN tocar el motor/evaluador/
 * clasificador.
 *
 * NUNCA recibe `source_rows` como argumento -- se deriva exclusivamente de
 * la formula Excel real en `cell_data` (columna + `total_row`), reutilizando
 * `FormulaRangeCoverageAnalyzer::analyze()` sin duplicar heuristico.
 * `total_row` SI es un argumento (uno de los 6 valores reales 331-336)
 * porque hay 6 candidatos igualmente validos por columna -- el operador
 * elige cual posicion crear en cada invocacion, nunca todas de una vez.
 *
 * UNICO guard nuevo respecto al comando original (17.42, ver auditoria
 * 17.41): el conjunto de filas derivado de la formula real debe coincidir
 * EXACTAMENTE con el patron periodico completo esperado para ese total_row
 * (13 terminos, paso 6, dentro del bloque componente [253:330] ya auditado)
 * -- puramente aritmetico, sin nombrar ninguna columna. Esto es
 * INDISPENSABLE (no redundante) para rechazar los patrones parciales/con
 * residuo incorrecto de la regla 230/AS (filas 331,332,333,336 con solo 2
 * de 13 terminos; fila 334 referenciando el residuo de otra posicion) --
 * ninguno de esos casos es detectado por los guards ya existentes
 * (`isEmbeddedBackwardSubtotalRow()` ya confirma esas filas como validas
 * para OTRAS columnas, ver 17.38-17.40), asi que sin este guard nuevo la
 * generalizacion aceptaria incorrectamente esas combinaciones ambiguas.
 *
 * Guards, en este orden (cualquier fallo aborta sin escribir):
 *  1. Regla origen existe, activa, rule_type=sum_equals.
 *  2. sheet=A09, section=I, columna en {AM,AN,AQ,AR,AS,AT,AU,AV,AX} Y
 *     rule_key coincide EXACTAMENTE con una de las 9 conocidas -- rechaza
 *     cualquier otra regla del sistema, sin excepcion.
 *  3. config.row_range es EXACTAMENTE el placeholder {"from":0,"to":0}.
 *  4. config.total_row y config.source_rows ausentes en la regla origen.
 *  5. total_row (argumento) es uno de los 6 valores reales {331..336}.
 *  6. `rule_key` propuesto no colisiona con ninguna regla existente.
 *  7. Ninguna regla ACTIVA ya existe con la misma combinacion exacta
 *     sheet+section+columna+tipo+total_row.
 *  8. La celda {columna}{total_row} en cell_data tiene una formula real.
 *  9. La formula no referencia ninguna otra columna.
 *  10. La formula referencia >=2 filas, todas estrictamente anteriores a
 *      total_row.
 *  11. (NUEVO, 17.42) El conjunto de filas coincide EXACTAMENTE con el
 *      patron periodico completo esperado (13 terminos, paso 6) para ese
 *      total_row -- rechaza sumas parciales y residuos incorrectos.
 *  12. isEmbeddedBackwardSubtotalRow() confirma la fila (mecanismo #12).
 *  13. Simulacion de clasificacion del config propuesto (Rule NO
 *      persistida) = EXACTAMENTE SAFE_1_TO_1.
 *
 * Al comitear: crea la fila `rem_rules` nueva (source='a09_i_expansion',
 * metadata con `derived_from_rule_id`/`derived_from_rule_key`/`total_row`
 * para trazabilidad), 1 activity log en la regla NUEVA
 * (`rule_a09_i_aggregation_created`) + 1 activity log en la regla ORIGEN
 * (`rule_a09_i_aggregation_derived`) -- la regla origen NUNCA se modifica.
 *
 * Nota de compatibilidad: las 25 reglas ya creadas por el comando ORIGINAL
 * (`rule:expand-b2-aggregation`, 17.40, ids 868-892) conservan su
 * `source='b2_expansion'`/`metadata.b2_total_row`/`created_via='rule:expand-
 * b2-aggregation'` histórico intacto -- este comando nunca las toca ni
 * reescribe su metadata retroactivamente, consistente con el principio ya
 * establecido en toda la campaña de nunca reescribir historia.
 *
 * Disposicion futura de la regla origen (NO ejecutada por este comando,
 * solo documentada): una vez creadas todas sus posiciones viables, la
 * regla origen quedaria sin ningun proposito funcional real -- candidata a
 * `status=inactive`, mismo patron que toda la campaña, vinculado por
 * `metadata.derived_from_rule_id` en cada una de sus reglas hijas. Esa
 * desactivacion NO se ejecuta aqui.
 */
class RuleExpandA09IAggregationCommand extends Command
{
    private const VALID_ORIGIN_KEYS_BY_COLUMN = [
        'AM' => 'a09_i_am_sum_equals',
        'AN' => 'a09_i_an_sum_equals',
        'AQ' => 'a09_i_aq_sum_equals',
        'AR' => 'a09_i_ar_sum_equals',
        'AS' => 'a09_i_as_sum_equals',
        'AT' => 'a09_i_at_sum_equals',
        'AU' => 'a09_i_au_sum_equals',
        'AV' => 'a09_i_av_sum_equals',
        'AX' => 'a09_i_ax_sum_equals',
    ];

    private const VALID_TOTAL_ROWS = [331, 332, 333, 334, 335, 336];

    private const PERIOD_STEP = 6;
    private const PERIOD_TERM_COUNT = 13;

    protected $signature = 'rule:expand-a09-i-aggregation
                            {origin_rule_id : ID de una de las 9 reglas origen de A09/I (226,227,228,229,230,231,232,233,234)}
                            {total_row : Fila TOTAL real a representar (331-336)}
                            {--reason= : Motivo -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste la regla nueva. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Fase 4: crea UNA regla derivada de una de las 9 reglas origen periodicas de A09/I, representando una de sus posiciones (dry-run por defecto)';

    public function __construct(
        private CellDataStorageService $cellDataStorage,
        private SectionCalibrationMatrixService $calibrationMatrix,
    ) {
        parent::__construct();
    }

    public function handle(RuleBindingReconciliationService $reconciliation): int
    {
        $originId = (int) $this->argument('origin_rule_id');
        $totalRow = (int) $this->argument('total_row');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        $data = $this->computeAndValidate($originId, $totalRow, $reconciliation);
        if ($data === null) {
            return self::FAILURE;
        }

        $this->printReport($data);

        if (!$commit) {
            $this->newLine();
            $this->info('DRY-RUN: no se persistio ninguna regla nueva. Ejecuta con --commit --reason=... --by=... para persistir.');

            return self::SUCCESS;
        }

        if (!$reason || !$by) {
            $this->error('--reason y --by son obligatorios para --commit.');

            return self::FAILURE;
        }

        $revalidated = $this->computeAndValidate($originId, $totalRow, $reconciliation);
        if ($revalidated === null
            || $revalidated['new_config'] !== $data['new_config']
            || $revalidated['proposed_rule_key'] !== $data['proposed_rule_key']
        ) {
            $this->error('El estado (origen o descubrimiento) cambio entre la validacion y la escritura -- abortando sin persistir.');

            return self::FAILURE;
        }

        return $this->commit($revalidated, $reason, $by);
    }

    private function expectedPeriodicSourceRows(int $totalRow): array
    {
        $span = self::PERIOD_STEP * self::PERIOD_TERM_COUNT;

        return range($totalRow - $span, $totalRow - self::PERIOD_STEP, self::PERIOD_STEP);
    }

    private function computeAndValidate(int $originId, int $totalRow, RuleBindingReconciliationService $reconciliation): ?array
    {
        // Guard 1.
        $origin = Rule::find($originId);
        if (!$origin) {
            $this->error("No existe ninguna regla con id={$originId}.");

            return null;
        }
        if ($origin->status !== 'active') {
            $this->error("La regla origen {$originId} no esta activa (status={$origin->status}).");

            return null;
        }
        if ($origin->rule_type !== 'sum_equals') {
            $this->error("La regla origen {$originId} no es rule_type=sum_equals.");

            return null;
        }

        $config = $origin->config;
        $sheet = strtoupper($config['sheet'] ?? '');
        $section = strtoupper($config['section'] ?? '');
        $column = strtoupper($config['column'] ?? '');

        // Guard 2.
        if ($sheet !== 'A09' || $section !== 'I' || !isset(self::VALID_ORIGIN_KEYS_BY_COLUMN[$column])
            || self::VALID_ORIGIN_KEYS_BY_COLUMN[$column] !== $origin->rule_key
        ) {
            $this->error("La regla {$originId} (rule_key={$origin->rule_key}) no es una de las 9 reglas origen periodicas de A09/I (226,227,228,229,230,231,232,233,234 -- columnas AM/AN/AQ/AR/AS/AT/AU/AV/AX). Fuera de alcance: cualquier otra regla del sistema, aunque comparta el placeholder {0,0}.");

            return null;
        }

        // Guard 3.
        $rowRange = $config['row_range'] ?? null;
        $isPlaceholder = $rowRange !== null
            && (int) ($rowRange['from'] ?? -1) === 0
            && (int) ($rowRange['to'] ?? -1) === 0;
        if (!$isPlaceholder) {
            $this->error("La regla origen {$originId} ya no tiene row_range={0,0} (config ya fue modificado). No se procede.");

            return null;
        }

        // Guard 4.
        if (isset($config['total_row']) || isset($config['source_rows'])) {
            $this->error("La regla origen {$originId} ya tiene total_row/source_rows en config. No se procede.");

            return null;
        }

        // Guard 5.
        if (!in_array($totalRow, self::VALID_TOTAL_ROWS, true)) {
            $this->error('total_row=' . $totalRow . ' no es uno de los 6 valores reales validos (331,332,333,334,335,336).');

            return null;
        }

        $proposedRuleKey = 'a09_i_' . strtolower($column) . '_row' . $totalRow . '_sum_equals';

        // Guard 6.
        if (Rule::where('rule_key', $proposedRuleKey)->exists()) {
            $this->error("Ya existe una regla (de cualquier status) con rule_key={$proposedRuleKey}. No se procede.");

            return null;
        }

        // Guard 7.
        $alreadyExists = Rule::where('status', 'active')
            ->get(['id', 'config', 'rule_type'])
            ->contains(function (Rule $r) use ($sheet, $section, $column, $totalRow) {
                $c = $r->config ?? [];

                return $r->rule_type === 'sum_equals'
                    && strtoupper($c['sheet'] ?? '') === $sheet
                    && strtoupper($c['section'] ?? '') === $section
                    && strtoupper((string) ($c['column'] ?? '')) === $column
                    && isset($c['total_row'])
                    && (int) $c['total_row'] === $totalRow;
            });
        if ($alreadyExists) {
            $this->error("Ya existe una regla activa para {$sheet}/{$section} columna {$column} con total_row={$totalRow}. No se procede (evita doble-creacion).");

            return null;
        }

        $structure = RemTemplateStructure::where('status', 'active')->first();
        if (!$structure) {
            $this->error('No hay ninguna estructura activa.');

            return null;
        }

        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        $rawSection = null;
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                if (strtoupper((string) ($sec['codigo'] ?? '')) === $section) {
                    $rawSection = $sec;
                    break 2;
                }
            }
        }
        if ($rawSection === null) {
            $this->error("No se encontro la seccion {$sheet}/{$section} en la estructura activa.");

            return null;
        }

        // Guard 8.
        $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $totalRow);
        if ($cell === null || ($cell['es_formula'] ?? false) !== true) {
            $this->error("La celda {$column}{$totalRow} de {$sheet}/{$section} no tiene formula real en cell_data. No se procede.");

            return null;
        }
        $formula = (string) ($cell['formula'] ?? '');

        // Guard 9.
        $parsed = FormulaRangeCoverageAnalyzer::analyze($formula, $column);
        if (!empty($parsed['other_column_refs'])) {
            $this->error("La formula real en {$column}{$totalRow} referencia otra columna (" . implode(',', $parsed['other_column_refs']) . "). No se procede.");

            return null;
        }

        // Guard 10.
        $sourceRows = $parsed['rows'];
        if (count($sourceRows) < 2) {
            $this->error("La formula real en {$column}{$totalRow} referencia menos de 2 filas ([" . implode(',', $sourceRows) . ']) -- no parece una agregacion real. No se procede.');

            return null;
        }
        foreach ($sourceRows as $r) {
            if ($r >= $totalRow) {
                $this->error("La formula real en {$column}{$totalRow} referencia la fila {$r}, que no es anterior a total_row -- no es una agregacion hacia atras valida. No se procede.");

                return null;
            }
        }

        // Guard 11 (NUEVO, 17.42): coincidencia EXACTA con el patron
        // periodico completo esperado -- rechaza sumas parciales y
        // residuos incorrectos (ver docblock de la clase).
        $expected = $this->expectedPeriodicSourceRows($totalRow);
        if ($sourceRows !== $expected) {
            $this->error("La formula real en {$column}{$totalRow} referencia [" . implode(',', $sourceRows) . '], pero el patron periodico completo esperado para total_row=' . $totalRow . ' es [' . implode(',', $expected) . '] -- no coincide exactamente (suma parcial, termino externo, o residuo incorrecto). No se procede.');

            return null;
        }

        // Guard 12.
        if (!$this->calibrationMatrix->isEmbeddedBackwardSubtotalRow($sheet, $section, $totalRow, $rawSection)) {
            $this->error("isEmbeddedBackwardSubtotalRow() no confirma la fila {$totalRow} como subtotal tecnico excluido (mecanismo #12). No se procede.");

            return null;
        }

        $newRowRange = ['from' => min($sourceRows), 'to' => max($sourceRows)];
        $newConfig = [
            'sheet' => $sheet,
            'section' => $section,
            'column' => $column,
            'row_range' => $newRowRange,
            'source_rows' => $sourceRows,
            'total_row' => $totalRow,
            'rule_logic' => "Suma({$column}) = Columna {$column}",
        ];

        // Guard 13: simulacion via instancia NO persistida.
        $simulated = new Rule([
            'rule_key' => $proposedRuleKey,
            'rule_type' => 'sum_equals',
            'source' => 'a09_i_expansion',
            'name' => $origin->name . " (fila TOTAL {$totalRow})",
            'description' => $origin->description,
            'category' => $origin->category,
            'severity' => $origin->severity,
            'scope' => 'row_range',
            'config' => $newConfig,
            'status' => 'active',
            'version' => '1.0.0',
        ]);
        $simulatedClassification = $reconciliation->classifySingleRule($simulated, $structure);
        if ($simulatedClassification['clasificacion'] !== RuleBindingReconciliationService::SAFE_1_TO_1) {
            $this->error("La simulacion de la regla propuesta clasifica '{$simulatedClassification['clasificacion']}' (motivo: {$simulatedClassification['motivo']}), no SAFE_1_TO_1. No se procede.");

            return null;
        }

        $originClassification = $reconciliation->classifySingleRule($origin, $structure);

        return [
            'origin_id' => $originId,
            'origin_rule_key' => $origin->rule_key,
            'origin_config' => $config,
            'origin_classification' => $originClassification['clasificacion'],
            'sheet' => $sheet, 'section' => $section, 'column' => $column,
            'total_row' => $totalRow,
            'formula' => $formula,
            'source_rows' => $sourceRows,
            'new_row_range' => $newRowRange,
            'new_config' => $newConfig,
            'proposed_rule_key' => $proposedRuleKey,
            'simulated_classification' => $simulatedClassification['clasificacion'],
        ];
    }

    private function printReport(array $d): void
    {
        $this->line("Regla origen: id={$d['origin_id']} rule_key={$d['origin_rule_key']} clasificacion_actual={$d['origin_classification']}");
        $this->line("Origen config (sin cambios): " . json_encode($d['origin_config']));
        $this->newLine();
        $this->line("Formula real en {$d['sheet']}/{$d['section']} {$d['column']}{$d['total_row']}: {$d['formula']}");
        $this->line('source_rows derivado: [' . implode(',', $d['source_rows']) . '] (' . count($d['source_rows']) . ' filas)');
        $this->line("row_range propuesto (envolvente): [{$d['new_row_range']['from']}:{$d['new_row_range']['to']}]");
        $this->line("total_row propuesto: {$d['total_row']}");
        $this->line("rule_key propuesto: {$d['proposed_rule_key']}");
        $this->newLine();
        $this->line("Clasificacion SIMULADA de la regla propuesta (nada persistido): {$d['simulated_classification']}");
        $this->newLine();
        $this->line('Este comando NUNCA modifica config/status/bindings de la regla origen, ni calibraciones, rem_data, estructura, el evaluador, el prefiltro ni el clasificador.');
        $this->line('Disposicion futura de la regla origen (NO ejecutada aqui): candidata a status=inactive una vez creadas todas sus posiciones viables -- requiere su propio comando (rule:set-rule-status) y su propia autorizacion explicita.');
        $this->newLine();
        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line('  1 fila nueva en rem_rules: ' . json_encode($d['new_config']));
        $this->line("  metadata.derived_from_rule_id = {$d['origin_id']}, metadata.derived_from_rule_key = {$d['origin_rule_key']}, metadata.total_row = {$d['total_row']}");
        $this->line('  + 1 entrada en el activity log de la regla NUEVA (rule_a09_i_aggregation_created)');
        $this->line('  + 1 entrada en el activity log de la regla ORIGEN (rule_a09_i_aggregation_derived), sin tocar su config/status');
    }

    private function commit(array $d, string $reason, string $by): int
    {
        $newRuleId = null;

        try {
            DB::transaction(function () use ($d, $reason, $by, &$newRuleId): void {
                $origin = Rule::find($d['origin_id']);
                if (!$origin || $origin->config !== $d['origin_config']) {
                    throw new \RuntimeException('La regla origen cambio entre la validacion y la escritura -- abortando sin persistir.');
                }
                if (Rule::where('rule_key', $d['proposed_rule_key'])->exists()) {
                    throw new \RuntimeException('El rule_key propuesto ya existe -- abortando sin persistir.');
                }

                $newRule = Rule::create([
                    'rule_key' => $d['proposed_rule_key'],
                    'rule_type' => 'sum_equals',
                    'source' => 'a09_i_expansion',
                    'name' => $origin->name . " (fila TOTAL {$d['total_row']})",
                    'description' => $origin->description,
                    'category' => $origin->category,
                    'severity' => $origin->severity,
                    'scope' => 'row_range',
                    'config' => $d['new_config'],
                    'status' => 'active',
                    'version' => '1.0.0',
                    'metadata' => [
                        'derived_from_rule_id' => $d['origin_id'],
                        'derived_from_rule_key' => $d['origin_rule_key'],
                        'total_row' => $d['total_row'],
                        'created_via' => 'rule:expand-a09-i-aggregation',
                        'reason' => $reason,
                        'by' => $by,
                        'created_at' => now()->toIso8601String(),
                    ],
                ]);

                $newRuleId = $newRule->id;

                activity()
                    ->performedOn($newRule)
                    ->withProperties([
                        'rule_id' => $newRule->id,
                        'rule_key' => $newRule->rule_key,
                        'derived_from_rule_id' => $d['origin_id'],
                        'derived_from_rule_key' => $d['origin_rule_key'],
                        'total_row' => $d['total_row'],
                        'source_rows' => $d['source_rows'],
                        'row_range' => $d['new_row_range'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_a09_i_aggregation_created');

                activity()
                    ->performedOn($origin)
                    ->withProperties([
                        'rule_id' => $origin->id,
                        'rule_key' => $origin->rule_key,
                        'new_rule_id' => $newRule->id,
                        'new_rule_key' => $newRule->rule_key,
                        'total_row' => $d['total_row'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_a09_i_aggregation_derived');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla nueva creada: id={$newRuleId} rule_key={$d['proposed_rule_key']}. Origen {$d['origin_id']} sin cambios. Activity log registrado en ambas.");

        return self::SUCCESS;
    }
}
