<?php

namespace App\Console\Commands;

use App\Domain\REM\Models\RemData;
use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\CellDataStorageService;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use App\Domain\RuleEngine\Services\SectionCalibrationMatrixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3C-1 (CLAUDE.md punto 17.9/17.11): activa UNA regla individual de
 * Categoria A (vertical contigua, TOTAL tecnico excluido de rem_data,
 * compatible con el mecanismo ya piloteado en la regla 56 -- Fase 3B,
 * punto 17.8) escribiendo config.row_range + config.total_row.
 *
 * NUNCA recibe row_range ni total_row como argumento -- ambos se derivan
 * exclusivamente de evidencia real (Fase 1 para las 225 auto-descubiertas,
 * o el descubrimiento propio de este comando para el placeholder {0,0} de
 * la regla 56). Dry-run por defecto -- persistir exige --commit + --reason
 * + --by.
 *
 * DOS RAMAS DE ORIGEN DEL CANDIDATO:
 *
 * Rama 1 -- row_range YA real en config (225 reglas, la mayoria de
 * Categoria A): usa RuleBindingReconciliationService::classifySingleRule()
 * (Fase 1) para el candidato -- exige position=trailing, excluded=true
 * (a diferencia de rule:set-total-row, que exige excluded=false: aqui la
 * fila SI esta excluida de rem_data, por eso necesita rem_technical_totals,
 * Fase 3A/3B). row_range no se toca.
 *
 * Rama 2 -- row_range={0,0} (placeholder, ej. regla 56): Fase 1 nunca
 * intenta descubrir un candidato aqui (por diseno, ver
 * RuleBindingReconciliationService linea ~184). Este comando implementa un
 * descubrimiento propio, SEPARADO de discoverTotalRowCandidate() (nunca lo
 * modifica): escanea cada fila entre filaInicioDatos+1 y filaFinDatos de la
 * seccion viva buscando una formula en la MISMA columna que (a) sea
 * exclusivamente hacia atras, (b) cubra el rango COMPLETO y CONTIGUO
 * [filaInicioDatos : fila-1] sin huecos ni referencias externas, y (c) sea
 * confirmada por SectionCalibrationMatrixService::isEmbeddedBackwardSubtotalRow()
 * (mecanismo #12, ya publico, sin redefinir su logica). Si hay 0 o mas de 1
 * fila que cumpla, se aborta -- nunca se adivina. Este descubrimiento
 * ADEMAS actua como guard automatico contra los patrones ya auditados como
 * NO aptos (punto 17.9): 208/214 (A09/F.1, huecos) y 226-234 (A09/I,
 * periodicidad) fallan la verificacion de cobertura completa y quedan
 * rechazados sin necesidad de un caso especial.
 *
 * Guards comunes a ambas ramas, en este orden (cualquier fallo aborta):
 *  1. Regla existe, activa, rule_type=sum_equals.
 *  2. Clasificacion actual = BLOCKED_BY_ENGINE_GAP.
 *  3. config.total_row ausente.
 *  4. Candidato descubierto (unico, por la rama que corresponda).
 *  5. Candidato dentro de [filaInicioDatos:filaFinDatos] de la seccion viva
 *     -- guard explicito y separado (ver hallazgo de las 55 reglas
 *     A31/A32/A33 fuera de rango, punto 17.11 -- misma causa raiz que la
 *     regla 461, rechazadas aqui sin excepcion especial).
 *  6. Evidencia real en rem_data para el rango COMPONENTE (row_range),
 *     en cualquier carga historica -- informativo/obligatorio, pero nunca
 *     se exige evidencia de la fila TOTAL en si (esta, por definicion,
 *     esta excluida de rem_data -- Fase 3A/rem_technical_totals existe
 *     precisamente para eso).
 *  7. Simulacion en memoria (row_range + total_row propuestos, nada mas)
 *     clasifica EXACTAMENTE SAFE_1_TO_1.
 *  8. Ausencia de colision funcional con otra regla activa (mismo patron
 *     aprendido de 529 vs 530).
 *
 * NUNCA toca bindings, status, RuleExecutionLog, rem_validation_results,
 * calibraciones, rem_data, rem_technical_totals ni estructura -- solo
 * config.row_range (unicamente si cambia) y config.total_row.
 */
class RuleActivateCategoryATotalCommand extends Command
{
    protected $signature = 'rule:activate-category-a
                            {rule_id : ID de la regla}
                            {--reason= : Motivo -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste row_range/total_row. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Fase 3C-1: activa una regla de Categoria A escribiendo row_range/total_row derivados de evidencia real (dry-run por defecto)';

    public function __construct(
        private CellDataStorageService $cellDataStorage,
        private SectionCalibrationMatrixService $calibrationMatrix,
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
            $this->error('La regla (o el candidato descubierto) cambio entre la validacion y la escritura -- abortando sin persistir.');

            return self::FAILURE;
        }

        return $this->commit($revalidated, $reason, $by);
    }

    private function computeAndValidate(int $ruleId, RuleBindingReconciliationService $reconciliation): ?array
    {
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
            $this->error("La regla {$ruleId} no es rule_type=sum_equals (es '{$rule->rule_type}'). Fuera de alcance de Categoria A.");

            return null;
        }

        $structure = RemTemplateStructure::where('status', 'active')->first();
        if (!$structure) {
            $this->error('No hay ninguna estructura activa.');

            return null;
        }

        $diagnostic = $reconciliation->classifySingleRule($rule, $structure);
        if ($diagnostic['clasificacion'] !== RuleBindingReconciliationService::BLOCKED_BY_ENGINE_GAP) {
            $this->error("La regla {$ruleId} esta clasificada '{$diagnostic['clasificacion']}', no BLOCKED_BY_ENGINE_GAP. No aplica este comando.");

            return null;
        }

        $config = $rule->config;
        if (isset($config['total_row'])) {
            $this->error("La regla {$ruleId} ya tiene total_row en config (=" . $config['total_row'] . '). No hay nada que activar.');

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

        if ($isPlaceholderZeroZero) {
            $discovered = $this->discoverZeroZeroCandidate($sheet, $section, $column, $rawSection);
            if ($discovered === null) {
                $this->error("Regla {$ruleId}: row_range es el placeholder {0,0} y no se encontro un candidato unico de TOTAL contiguo hacia atras para {$sheet}/{$section} columna {$column}. No se procede (patrones como A09/F.1 o A09/I se rechazan aqui exactamente por esto).");

                return null;
            }
            $newRowRange = ['from' => $discovered['from'], 'to' => $discovered['to']];
            $candidate = $discovered['total_row'];
            $rangeChanged = true;
        } else {
            // Rama 1: row_range ya real -- usar el candidato de Fase 1.
            $candidate = $diagnostic['total_row_candidate'];
            if ($candidate === null) {
                $this->error("La Fase 1 no encontro un candidato unico de total_row para la regla {$ruleId}. No se puede activar automaticamente.");

                return null;
            }
            if ($diagnostic['total_row_position'] !== 'trailing') {
                $this->error("El candidato de la regla {$ruleId} es '{$diagnostic['total_row_position']}', no 'trailing'. Este comando (Fase 3C-1, Categoria A) solo opera sobre candidatos trailing -- los leading son Categoria C, fuera de alcance de esta fase.");

                return null;
            }
            if ($diagnostic['total_row_excluded'] !== true) {
                $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} NO esta excluido de rem_data -- no es un caso de Categoria A/deuda tecnica #5. Fuera de alcance de este comando.");

                return null;
            }
            $newRowRange = $rowRange;
            $rangeChanged = false;
        }

        // Guard: candidato dentro de limites estructurales vivos.
        if ($inicio === null || $fin === null || $candidate < $inicio || $candidate > $fin) {
            $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} cae fuera del rango vivo de la seccion [{$inicio}:{$fin}]. Rechazado -- misma causa raiz que la regla 461 (punto 16.13) y las 55 reglas de A31/A32/A33 auditadas en el punto 17.11. No se procede sin autorizacion/diseno aparte.");

            return null;
        }

        // Guard: evidencia real en rem_data para el rango COMPONENTE (nunca
        // se exige evidencia de la fila TOTAL, que por definicion esta
        // excluida de rem_data).
        $hasComponentEvidence = RemData::where('section', $sheet)->get()->contains(function ($r) use ($section, $newRowRange) {
            $rn = $r->data['row_number'] ?? null;
            return ($r->data['rem_section_code'] ?? null) === $section
                && $rn !== null && $rn >= $newRowRange['from'] && $rn <= $newRowRange['to'];
        });
        if (!$hasComponentEvidence) {
            $this->error("No existe evidencia real en rem_data para ninguna fila del rango componente {$sheet}/{$section} [{$newRowRange['from']}:{$newRowRange['to']}]. No se procede sin evidencia persistida.");

            return null;
        }

        $newConfig = $config;
        $newConfig['row_range'] = $newRowRange;
        $newConfig['total_row'] = $candidate;

        $simulatedRule = $rule->replicate();
        $simulatedRule->id = $rule->id;
        $simulatedRule->config = $newConfig;

        $simulatedDiagnostic = $reconciliation->classifySingleRule($simulatedRule, $structure);
        if ($simulatedDiagnostic['clasificacion'] !== RuleBindingReconciliationService::SAFE_1_TO_1) {
            $this->error("Simulando row_range=[{$newRowRange['from']}:{$newRowRange['to']}]/total_row={$candidate}, la regla {$ruleId} clasifica '{$simulatedDiagnostic['clasificacion']}' (motivo: {$simulatedDiagnostic['motivo']}), no SAFE_1_TO_1. No se procede.");

            return null;
        }

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
            $this->error("La regla {$ruleId} colisionaria con la regla {$collision->id} (misma clave funcional {$sheet}/{$section}/{$column}/sum_equals). No se procede -- ver caso 529 vs 530.");

            return null;
        }

        return [
            'rule' => $rule,
            'rule_id' => $ruleId,
            'sheet' => $sheet,
            'section' => $section,
            'old_config' => $config,
            'new_config' => $newConfig,
            'range_changed' => $rangeChanged,
            'candidate' => $candidate,
            'row_range' => $newRowRange,
            'structure' => $structure,
            'before_clasificacion' => $diagnostic['clasificacion'],
            'after_clasificacion' => $simulatedDiagnostic['clasificacion'],
        ];
    }

    /**
     * Descubrimiento propio para row_range={0,0} -- SEPARADO de
     * RuleBindingReconciliationService::discoverTotalRowCandidate(), que
     * nunca se modifica. Busca una unica fila, en [filaInicioDatos+1 :
     * filaFinDatos], cuya formula en $column sea EXCLUSIVAMENTE hacia atras
     * y cubra el rango COMPLETO Y CONTIGUO desde filaInicioDatos hasta
     * fila-1, confirmada ademas por isEmbeddedBackwardSubtotalRow(). Cero o
     * mas de una fila calificada => sin candidato (nunca se adivina).
     */
    private function discoverZeroZeroCandidate(string $sheet, string $section, string $column, ?array $rawSection): ?array
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

        $matches = [];
        for ($row = $inicio + 1; $row <= $fin; $row++) {
            $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $row);
            if ($cell === null || ($cell['es_formula'] ?? false) !== true) {
                continue;
            }

            $formula = (string) ($cell['formula'] ?? '');
            $parsed = $this->parseFormulaRows($formula, $column);
            if (!empty($parsed['other_column_refs'])) {
                continue;
            }

            $expected = range($inicio, $row - 1);
            if ($parsed['rows'] !== $expected) {
                continue;
            }

            if (!$this->calibrationMatrix->isEmbeddedBackwardSubtotalRow($sheet, $section, $row, $rawSection)) {
                continue;
            }

            $matches[] = $row;
        }

        if (count($matches) !== 1) {
            return null;
        }

        return ['from' => $inicio, 'to' => $matches[0] - 1, 'total_row' => $matches[0]];
    }

    /**
     * Expande sintaxis de rango Excel (COL#:COL#) y refs individuales;
     * devuelve las filas cubiertas (ordenadas, unicas) y cualquier
     * referencia a una columna distinta de la esperada.
     */
    /**
     * Delegado a FormulaRangeCoverageAnalyzer (extraido en Fase 3C-1B,
     * punto 17.14/17.15, para que este comando y
     * RuleBindingReconciliationService::isLegitimateTrailingTotalBeyondBounds()
     * usen exactamente el mismo heuristico, sin duplicarlo). Firma y
     * comportamiento sin cambios -- mismos call-sites, mismo resultado.
     */
    private function parseFormulaRows(string $formula, string $expectedColumn): array
    {
        return \App\Domain\RuleEngine\Services\FormulaRangeCoverageAnalyzer::analyze($formula, $expectedColumn);
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
        $this->info("Regla {$d['rule_id']} ({$d['rule']->rule_key}) -- row_range propuesto: [{$d['row_range']['from']}:{$d['row_range']['to']}], total_row propuesto: {$d['candidate']}" . ($d['range_changed'] ? ' (row_range CAMBIA respecto al actual)' : ' (row_range sin cambio)'));
        $this->newLine();

        $this->line('Config ANTES:');
        $this->line('  ' . json_encode($d['old_config'], JSON_UNESCAPED_UNICODE));
        $this->line('Config PROPUESTA:');
        $this->line('  ' . json_encode($d['new_config'], JSON_UNESCAPED_UNICODE));
        $this->newLine();

        $this->line("Clasificacion ANTES: {$d['before_clasificacion']}");
        $this->line("Clasificacion SIMULADA DESPUES (nada persistido): {$d['after_clasificacion']}");
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
        $this->line('Este comando NUNCA crea/modifica bindings, calibraciones, rem_data, rem_technical_totals ni estructura.');
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  rem_rules.id={$d['rule_id']}.config->row_range = [{$d['row_range']['from']}:{$d['row_range']['to']}]" . ($d['range_changed'] ? '' : ' (identico al actual)'));
        $this->line("  rem_rules.id={$d['rule_id']}.config->total_row = {$d['candidate']}");
        $this->line('  + 1 fila nueva en rem_rule_versions (snapshot del config ANTERIOR)');
        $this->line('  + 1 entrada en el activity log (rule_category_a_activated)');
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
                    'changelog' => "rule:activate-category-a: row_range=[{$d['row_range']['from']}:{$d['row_range']['to']}], total_row={$d['candidate']}. Motivo: {$reason}. Responsable: {$by}. " . now()->toIso8601String(),
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
                        'row_range_set' => $d['row_range'],
                        'total_row_set' => $d['candidate'],
                        'range_changed' => $d['range_changed'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_category_a_activated');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: row_range/total_row activados. RuleVersion de auditoria creado. Activity log registrado.");

        return self::SUCCESS;
    }
}
