<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
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
 * CLAUDE.md punto 17.49. Activa UNA regla individual cuyo TOTAL tecnico
 * leading cae EXACTAMENTE en `filaInicioDatos - 1`, sin etiqueta textual
 * "TOTAL" propia de la fila -- patron auditado y disenado en el punto
 * 17.46, confirmado por el barrido exhaustivo de 381 secciones como unico
 * (regla 461, `A30/F`). Mirror exacto de
 * `rule:activate-trailing-total-beyond-bounds` (Fase 3C-1B) para la
 * direccion opuesta -- distinto y separado de `rule:activate-category-c-leading`
 * (Categoria C, candidatos leading DENTRO de limites, que ya usan #6 con
 * etiqueta textual).
 *
 * NUNCA recibe `total_row` como argumento -- se deriva exclusivamente del
 * candidato ya descubierto por Fase 1 (`classifySingleRule()`). NUNCA
 * modifica `row_range`.
 *
 * Depende de `RuleBindingReconciliationService::isLegitimateLeadingTotalBeyondBounds()`
 * (metodo aislado, nuevo, punto 17.49) para que la simulacion final
 * clasifique `SAFE_1_TO_1` -- ese metodo delega en el mecanismo NUEVO
 * `isLeadingFormulaBasedTotalBeyondBounds()` (hermano de #6, nunca lo
 * modifica, nunca exige etiqueta textual) -- el bounds-check generico de
 * `classifyRule()` permanece intacto para cualquier otro caso.
 *
 * Guards, en este orden exacto (cualquier fallo aborta sin escribir):
 *  1. Regla existe, activa, rule_type=sum_equals.
 *  2. Clasificacion actual = BLOCKED_BY_ENGINE_GAP.
 *  3. Hoja NO marcada 'no_utilizada'.
 *  4. config.total_row ausente.
 *  5. row_range real en config (nunca el placeholder {0,0}).
 *  6. Candidato descubierto por Fase 1, position=leading (independiente de
 *     `excluded` -- ese flag refleja el mecanismo #6, no el nuevo
 *     mecanismo formula-based, que se re-verifica de forma independiente
 *     en el guard 9).
 *  7. Candidato === filaInicioDatos - 1 EXACTO (nunca -2 ni mas -- guard
 *     explicito y separado, mensaje especifico).
 *  8. Fila candidata NO reclamada por ninguna otra seccion declarada de la
 *     misma hoja.
 *  9. Mecanismo NUEVO (isLeadingFormulaBasedTotalBeyondBounds) confirmado
 *     en vivo -- NUNCA se consulta #6 aqui.
 *  10. Formula real completa/contigua/sin referencias externas para la
 *      COLUMNA PROPIA de la regla, verificada de forma independiente
 *      (FormulaRangeCoverageAnalyzer).
 *  11. Simulacion final (SOLO total_row=candidato, row_range sin tocar)
 *      clasifica EXACTAMENTE SAFE_1_TO_1.
 *  12. Ausencia de colision funcional con otra regla activa (patron 529 vs
 *      530).
 *
 * NUNCA toca bindings, status, rem_data, rem_technical_totals,
 * calibraciones, estructura ni el guard generico de classifyRule() --
 * solo `config.total_row` de la regla indicada.
 */
class RuleActivateLeadingFormulaTotalBeyondBoundsCommand extends Command
{
    protected $signature = 'rule:activate-leading-formula-total-beyond-bounds
                            {rule_id : ID de la regla}
                            {--reason= : Motivo -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste config.total_row. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Punto 17.49: activa una regla cuyo TOTAL leading cae exactamente en filaInicioDatos-1, sin etiqueta textual (patron disenado en 17.46)';

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
            || $revalidated['candidate'] !== $data['candidate']
        ) {
            $this->error('La regla (o su candidato) cambio entre la validacion y la escritura -- abortando sin persistir.');

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
            $this->error("La regla {$ruleId} no es rule_type=sum_equals (es '{$rule->rule_type}'). Fuera de alcance.");

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
        if ($diagnostic['hoja_no_utilizada'] === true) {
            $this->error("La hoja de la regla {$ruleId} esta marcada 'no_utilizada' -- fuera de alcance de cualquier activacion funcional. No se procede.");

            return null;
        }

        $config = $rule->config;
        if (isset($config['total_row'])) {
            $this->error("La regla {$ruleId} ya tiene total_row en config (=" . $config['total_row'] . '). No hay nada que activar.');

            return null;
        }

        $rowRange = $config['row_range'] ?? null;
        $isPlaceholderZeroZero = $rowRange === null
            || ((int) ($rowRange['from'] ?? -1) === 0 && (int) ($rowRange['to'] ?? -1) === 0);
        if ($isPlaceholderZeroZero) {
            $this->error("La regla {$ruleId} no tiene row_range real en config -- fuera de alcance de este comando (Categoria B/F requieren diseno propio).");

            return null;
        }

        $candidate = $diagnostic['total_row_candidate'];
        if ($candidate === null) {
            $this->error("La Fase 1 no encontro un candidato unico de total_row para la regla {$ruleId}. No se puede activar.");

            return null;
        }
        if ($diagnostic['total_row_position'] !== 'leading') {
            $this->error("El candidato de la regla {$ruleId} es '{$diagnostic['total_row_position']}', no 'leading'. Este comando (punto 17.49) solo opera sobre candidatos leading fuera de limites -- los trailing son Fase 3C-1B, fuera de alcance.");

            return null;
        }

        $sheet = strtoupper($config['sheet'] ?? '');
        $section = strtoupper($config['section'] ?? '');
        $column = strtoupper($config['column'] ?? '');
        $rawSection = $this->findRawSectionData($structure, $sheet, $section);
        $inicio = $rawSection['filaInicioDatos'] ?? null;

        // Guard 7: candidato === inicio-1 EXACTO (nunca -2 ni mas).
        if ($inicio === null || $candidate !== ((int) $inicio) - 1) {
            $this->error("El candidato (fila {$candidate}) de la regla {$ruleId} NO es exactamente filaInicioDatos-1 (filaInicioDatos={$inicio}). Este comando exclusivamente acepta el patron inicio-1 disenado en el punto 17.46 -- cualquier otra distancia queda fuera de alcance.");

            return null;
        }

        // Guard 8: fila candidata no reclamada por ninguna otra seccion de la misma hoja.
        $est = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;
        foreach ($est['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            foreach ($form['sections'] ?? [] as $sec) {
                $ini = $sec['filaInicioDatos'] ?? null;
                $secFin = $sec['filaFinDatos'] ?? null;
                if ($ini !== null && $secFin !== null && $candidate >= (int) $ini && $candidate <= (int) $secFin) {
                    $this->error("La fila candidata ({$candidate}) de la regla {$ruleId} esta reclamada por la seccion '{$sec['codigo']}' de la hoja {$sheet} -- no es un TOTAL tecnico huerfano. No se procede.");

                    return null;
                }
            }
        }

        // Guard 9: mecanismo NUEVO confirmado en vivo -- NUNCA se consulta #6 aqui.
        if ($rawSection === null || !$this->calibrationMatrix->isLeadingFormulaBasedTotalBeyondBounds($sheet, $section, $candidate, $rawSection)) {
            $this->error("isLeadingFormulaBasedTotalBeyondBounds() no confirma la fila {$candidate} de la regla {$ruleId} como TOTAL tecnico leading. No se procede.");

            return null;
        }

        // Guard 10: formula completa/contigua/sin referencias externas para la columna propia, verificada de forma independiente.
        $from = (int) $rowRange['from'];
        $to = (int) $rowRange['to'];
        $cell = $this->cellDataStorage->getCellForCoordinate($sheet, $section, $column . $candidate);
        $formula = (string) ($cell['formula'] ?? '');
        if ($cell === null || ($cell['es_formula'] ?? false) !== true || !FormulaRangeCoverageAnalyzer::isCompleteContiguous($formula, $column, $from, $to)) {
            $this->error("La formula real en {$sheet}/{$section} {$column}{$candidate} no cubre de forma completa y contigua [{$from}:{$to}] (o referencia otra columna). No se procede.");

            return null;
        }

        // Guard 11: simulacion final -- SOLO total_row cambia.
        $newConfig = $config;
        $newConfig['total_row'] = $candidate;

        $simulatedRule = $rule->replicate();
        $simulatedRule->id = $rule->id;
        $simulatedRule->config = $newConfig;

        $simulatedDiagnostic = $reconciliation->classifySingleRule($simulatedRule, $structure);
        if ($simulatedDiagnostic['clasificacion'] !== RuleBindingReconciliationService::SAFE_1_TO_1) {
            $this->error("Simulando total_row={$candidate}, la regla {$ruleId} clasifica '{$simulatedDiagnostic['clasificacion']}' (motivo: {$simulatedDiagnostic['motivo']}), no SAFE_1_TO_1. No se procede.");

            return null;
        }

        // Guard 12: ausencia de colision funcional (patron 529 vs 530).
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
            'old_config' => $config,
            'new_config' => $newConfig,
            'candidate' => $candidate,
            'inicio' => $inicio,
            'structure' => $structure,
            'before_clasificacion' => $diagnostic['clasificacion'],
            'after_clasificacion' => $simulatedDiagnostic['clasificacion'],
        ];
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
        $this->info("Regla {$d['rule_id']} ({$d['rule']->rule_key}) -- total_row propuesto: {$d['candidate']} (= filaInicioDatos {$d['inicio']} - 1, patron disenado punto 17.46)");
        $this->newLine();

        $this->line('Config ANTES:');
        $this->line('  ' . json_encode($d['old_config'], JSON_UNESCAPED_UNICODE));
        $this->line('Config PROPUESTA:');
        $this->line('  ' . json_encode($d['new_config'], JSON_UNESCAPED_UNICODE));
        $this->line("Diff exacto: total_row: (ausente) -> {$d['candidate']} (unica clave que cambia -- row_range NO se toca)");
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
        $this->line('Este comando NUNCA crea/modifica bindings, calibraciones, rem_data, rem_technical_totals, estructura ni el guard generico de classifyRule().');
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  rem_rules.id={$d['rule_id']}.config->total_row = {$d['candidate']}");
        $this->line('  + 1 fila nueva en rem_rule_versions (snapshot del config ANTERIOR)');
        $this->line('  + 1 entrada en el activity log (rule_leading_formula_total_beyond_bounds_activated)');
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
                    'changelog' => "rule:activate-leading-formula-total-beyond-bounds: total_row={$d['candidate']} (inicio-1, patron 17.46/17.49). Motivo: {$reason}. Responsable: {$by}. " . now()->toIso8601String(),
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
                        'total_row_set' => $d['candidate'],
                        'inicio' => $d['inicio'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_leading_formula_total_beyond_bounds_activated');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: config.total_row activado con {$d['candidate']}. RuleVersion de auditoria creado. Activity log registrado.");

        return self::SUCCESS;
    }
}
