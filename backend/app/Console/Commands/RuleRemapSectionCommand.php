<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleBinding;
use App\Domain\RuleEngine\Models\RuleVersion;
use App\Domain\RuleEngine\Services\RuleBindingReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remapea UNA regla individual a una nueva seccion, dentro de la MISMA hoja,
 * cambiando UNICAMENTE config.section -- nunca columnas, row_range,
 * rule_type, scope, ni ningun otro campo de config o de la regla.
 *
 * Disenado para el caso REQUIRES_REMAP donde la seccion historica de una
 * regla (ej. "F") fue dividida en varias secciones vivas (ej. F1/F2) y el
 * nuevo destino es estructuralmente inequivoco por evidencia real -- nunca
 * solo por row_range, nunca por analogia (ver auditoria A32/F regla 529,
 * 2026-08-27, y la deuda tecnica #5 documentada en CLAUDE.md sobre no asumir
 * remaps sin verificar exclusion por mecanismos #6/#8/#12 primero).
 *
 * Dry-run por defecto (mismo patron que rule:rebind-safe-to-structure y
 * rule:tag-mismatch-resolution) -- persistir exige --commit + --reason +
 * --by explicitos.
 *
 * Guards obligatorios, TODOS evaluados antes de permitir cualquier
 * escritura (cualquier falla aborta sin tocar nada):
 *  1. La regla existe y esta activa.
 *  2. La seccion destino difiere de la actual (si no, no hay remap que hacer).
 *  3. Existe una estructura activa.
 *  4. La seccion destino existe en esa estructura, en la MISMA hoja.
 *  5. Todas las columnas que la regla referencia (fuente + destino, derivadas
 *     por la misma logica que usa el clasificador -- nunca reimplementadas
 *     aqui) existen en la seccion destino.
 *  6. El row_range de la regla (si es real, no el placeholder {0,0}) cae
 *     COMPLETAMENTE dentro del rango de filas vivo de la seccion destino.
 *  7. Ninguna OTRA seccion de la misma hoja satisface simultaneamente los
 *     guards 5 y 6 -- si mas de una seccion calificara (ambiguedad real,
 *     no solo la que reporta el clasificador para la seccion historica
 *     inexistente), se aborta. Esto es lo que descarta explicitamente un
 *     candidato como F2 cuando su rango de filas no cubre la fila de la
 *     regla, en vez de asumirlo solo porque el clasificador original
 *     reporto "candidatos: F1,F2" contra la seccion historica ausente.
 *  8. La regla, simulada en memoria (nunca persistida) con config.section
 *     apuntando al destino, reclasifica exactamente SAFE_1_TO_1 contra la
 *     estructura activa, con destino == {hoja}/{seccion_destino}.
 *
 * Este comando JAMAS crea, modifica ni desactiva ningun RuleBinding -- eso
 * sigue siendo responsabilidad exclusiva y deliberadamente separada de
 * rule:rebind-safe-to-structure (un paso posterior, explicito, nunca
 * automatico desde aqui). Tampoco toca calibraciones, rem_data, ni
 * reglas-funcionales.json -- unicamente rem_rules.config.section de la
 * regla indicada, mas un RuleVersion de auditoria con el config anterior y
 * un registro en el activity log.
 */
class RuleRemapSectionCommand extends Command
{
    protected $signature = 'rule:remap-section
                            {rule_id : ID de la regla a remapear}
                            {new_section : Codigo de la seccion destino, en la misma hoja (ej. F1)}
                            {--reason= : Motivo del remap -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste el cambio de config.section. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Remapea config.section de una regla individual a un destino verificado (dry-run por defecto, --commit para persistir)';

    public function handle(RuleBindingReconciliationService $reconciliation): int
    {
        $ruleId = (int) $this->argument('rule_id');
        $newSection = (string) $this->argument('new_section');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        $data = $this->computeAndValidate($ruleId, $newSection, $reconciliation);
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

        // Segunda validacion, inmediatamente antes de escribir -- mismo
        // patron de doble-chequeo que isStillSafe() en
        // rule:rebind-safe-to-structure. Protege contra cualquier cambio
        // concurrente de la regla o de la estructura activa entre el
        // reporte de arriba y la escritura real.
        $revalidated = $this->computeAndValidate($ruleId, $newSection, $reconciliation);
        if ($revalidated === null
            || $revalidated['old_config'] !== $data['old_config']
            || $revalidated['new_config'] !== $data['new_config']
            || $revalidated['structure']->id !== $data['structure']->id
        ) {
            $this->error('Uno o mas guards cambiaron entre la validacion y la escritura -- abortando sin persistir nada.');

            return self::FAILURE;
        }

        return $this->commitRemap($revalidated, $reason, $by);
    }

    /**
     * Corre TODOS los guards y devuelve los datos necesarios para el
     * reporte y la escritura, o null (con el error ya impreso via
     * $this->error()) si cualquier guard falla. No persiste nada.
     */
    private function computeAndValidate(int $ruleId, string $newSection, RuleBindingReconciliationService $reconciliation): ?array
    {
        $rule = Rule::find($ruleId);
        if (!$rule) {
            $this->error("No existe ninguna regla activa con id={$ruleId}.");

            return null;
        }

        if ($rule->status !== 'active') {
            $this->error("La regla {$ruleId} no esta activa (status={$rule->status}). No se remapea.");

            return null;
        }

        $oldConfig = $rule->config;
        $sheet = strtoupper((string) ($oldConfig['sheet'] ?? ''));
        $oldSection = (string) ($oldConfig['section'] ?? '');

        if ($sheet === '') {
            $this->error("La regla {$ruleId} no tiene 'sheet' en su config. No se puede procesar.");

            return null;
        }

        if (strtoupper($oldSection) === strtoupper($newSection)) {
            $this->error("La seccion destino ({$newSection}) coincide con la seccion actual ({$oldSection}) -- no hay remap que realizar.");

            return null;
        }

        $structure = RemTemplateStructure::where('status', 'active')->first();
        if (!$structure) {
            $this->error('No hay ninguna estructura activa.');

            return null;
        }

        $estructura = is_string($structure->estructura) ? json_decode($structure->estructura, true) : $structure->estructura;

        $sheetSections = [];
        $sheetNameOriginal = null;
        foreach ($estructura['forms'] ?? [] as $form) {
            if (strtoupper((string) ($form['sheetName'] ?? '')) !== $sheet) {
                continue;
            }
            $sheetNameOriginal = $form['sheetName'];
            foreach ($form['sections'] ?? [] as $sec) {
                $codigo = $sec['codigo'] ?? null;
                if ($codigo === null) {
                    continue;
                }
                $sheetSections[$codigo] = [
                    'fields' => array_map(fn ($f) => strtoupper((string) ($f['letra'] ?? '')), $sec['fields'] ?? []),
                    'inicio' => $sec['filaInicioDatos'] ?? null,
                    'fin' => $sec['filaFinDatos'] ?? null,
                ];
            }
        }

        if ($sheetNameOriginal === null || empty($sheetSections)) {
            $this->error("La hoja {$sheet} no existe en la estructura activa {$structure->id}/v{$structure->version_number}.");

            return null;
        }

        if (!isset($sheetSections[$newSection])) {
            $this->error("El destino {$sheet}/{$newSection} no existe en la estructura activa {$structure->id}/v{$structure->version_number}. Secciones vivas de {$sheet}: " . implode(',', array_keys($sheetSections)) . '.');

            return null;
        }

        // Columnas y row_range derivados por la MISMA logica que usa el
        // clasificador (deriveSourceLetters + columna destino) -- nunca
        // reimplementada aqui, para no divergir de lo que realmente decide
        // SAFE_1_TO_1/REQUIRES_REMAP en el resto del sistema.
        $beforeClassification = $reconciliation->classifySingleRule($rule, $structure);
        $requiredColumns = $beforeClassification['columnas'] ?? [];
        $rowRange = $beforeClassification['row_range'] ?? null;

        $destInfo = $sheetSections[$newSection];

        $missingColumns = array_values(array_diff($requiredColumns, $destInfo['fields']));
        if (!empty($missingColumns)) {
            $this->error("Columnas faltantes en el destino {$sheet}/{$newSection}: [" . implode(',', $missingColumns) . ']. No se remapea.');

            return null;
        }

        $hasRealRowRange = $rowRange !== null
            && !((int) ($rowRange['from'] ?? -1) === 0 && (int) ($rowRange['to'] ?? -1) === 0);

        if ($hasRealRowRange) {
            $from = (int) ($rowRange['from'] ?? -1);
            $to = (int) ($rowRange['to'] ?? -1);
            if ($destInfo['inicio'] === null || $destInfo['fin'] === null
                || $from < $destInfo['inicio'] || $to > $destInfo['fin'] || $from <= 0
            ) {
                $this->error("El row_range [{$from}:{$to}] de la regla {$ruleId} no cae dentro del rango vivo de {$sheet}/{$newSection} [" . ($destInfo['inicio'] ?? '?') . ':' . ($destInfo['fin'] ?? '?') . ']. No se remapea.');

                return null;
            }
        }

        // Ambiguedad real: ¿hay otra seccion de la MISMA hoja que tambien
        // cumpla columnas + row_range? Si el destino solicitado no es el
        // UNICO candidato posible, se aborta -- nunca se asume el destino
        // solo porque el clasificador lo listo como "candidato" contra la
        // seccion historica inexistente.
        $candidates = [];
        foreach ($sheetSections as $codigo => $info) {
            $missing = array_diff($requiredColumns, $info['fields']);
            if (!empty($missing)) {
                continue;
            }
            if ($hasRealRowRange) {
                $from = (int) ($rowRange['from'] ?? -1);
                $to = (int) ($rowRange['to'] ?? -1);
                if ($info['inicio'] === null || $info['fin'] === null || $from < $info['inicio'] || $to > $info['fin'] || $from <= 0) {
                    continue;
                }
            }
            $candidates[] = $codigo;
        }

        if (count($candidates) !== 1 || $candidates[0] !== $newSection) {
            $this->error(
                "Ambiguedad real en {$sheet}: mas de una seccion (o una seccion distinta a {$newSection}) satisface columnas+row_range. "
                . 'Candidatos encontrados: [' . implode(',', $candidates) . ']. No se remapea sin desambiguar explicitamente.'
            );

            return null;
        }

        // Config propuesta: ONICAMENTE 'section' cambia. Se actualiza in-place
        // sobre una copia del array para preservar el orden y el resto de
        // claves byte-identicas.
        $newConfig = $oldConfig;
        $newConfig['section'] = $newSection;

        $simulatedRule = $rule->replicate();
        $simulatedRule->id = $rule->id;
        $simulatedRule->config = $newConfig;

        $afterClassification = $reconciliation->classifySingleRule($simulatedRule, $structure);

        $expectedDestino = "{$sheetNameOriginal}/{$newSection}";
        if ($afterClassification['clasificacion'] !== RuleBindingReconciliationService::SAFE_1_TO_1
            || $afterClassification['destino'] !== $expectedDestino
        ) {
            $this->error(
                "La regla simulada con section={$newSection} NO clasifica SAFE_1_TO_1 exclusivamente hacia {$expectedDestino}. "
                . "Obtenido: clasificacion={$afterClassification['clasificacion']}, destino=" . ($afterClassification['destino'] ?? 'N/A') . ", motivo={$afterClassification['motivo']}. No se remapea."
            );

            return null;
        }

        return [
            'rule' => $rule,
            'rule_id' => $ruleId,
            'sheet' => $sheet,
            'sheet_name_original' => $sheetNameOriginal,
            'old_section' => $oldSection,
            'new_section' => $newSection,
            'old_config' => $oldConfig,
            'new_config' => $newConfig,
            'structure' => $structure,
            'required_columns' => $requiredColumns,
            'row_range' => $rowRange,
            'dest_info' => $destInfo,
            'candidates' => $candidates,
            'before_classification' => $beforeClassification,
            'after_classification' => $afterClassification,
        ];
    }

    private function printReport(array $d): void
    {
        $this->info("Regla {$d['rule_id']} ({$d['rule']->rule_key}) -- remap propuesto: {$d['sheet']}/{$d['old_section']} -> {$d['sheet']}/{$d['new_section']}");
        $this->newLine();

        $this->line('Config ANTES:');
        $this->line('  ' . json_encode($d['old_config'], JSON_UNESCAPED_UNICODE));
        $this->line('Config PROPUESTA:');
        $this->line('  ' . json_encode($d['new_config'], JSON_UNESCAPED_UNICODE));
        $this->line("Diff exacto: section: \"{$d['old_section']}\" -> \"{$d['new_section']}\" (unica clave que cambia)");
        $this->newLine();

        $this->line("Estructura destino: ID {$d['structure']->id} (v{$d['structure']->version_number}, {$d['structure']->anio}/{$d['structure']->serie}, status={$d['structure']->status})");
        $this->line('Columnas requeridas (fuente+destino, derivadas del clasificador): [' . implode(',', $d['required_columns']) . ']');
        $rr = $d['row_range'] ? "[{$d['row_range']['from']}:{$d['row_range']['to']}]" : '(sin row_range real)';
        $this->line("row_range de la regla: {$rr}");
        $this->line("Rango vivo de la seccion destino {$d['new_section']}: [" . ($d['dest_info']['inicio'] ?? '?') . ':' . ($d['dest_info']['fin'] ?? '?') . ']');
        $this->line('Candidatos unicos que satisfacen columnas+row_range en la hoja: [' . implode(',', $d['candidates']) . '] (debe ser exactamente [' . $d['new_section'] . '])');
        $this->newLine();

        $this->line("Clasificacion ANTES (regla real, section={$d['old_section']}): {$d['before_classification']['clasificacion']} -- {$d['before_classification']['motivo']}");
        $this->line("Clasificacion DESPUES (simulada en memoria, section={$d['new_section']}): {$d['after_classification']['clasificacion']} -- destino={$d['after_classification']['destino']} -- {$d['after_classification']['motivo']}");
        $this->newLine();

        $bindings = RuleBinding::where('rule_id', $d['rule_id'])->get();
        if ($bindings->isEmpty()) {
            $this->line('Bindings actuales de la regla: (ninguno)');
        } else {
            $this->table(
                ['binding_id', 'bindable_type', 'bindable_id', 'active'],
                $bindings->map(fn ($b) => [$b->id, $b->bindable_type, $b->bindable_id, $b->active ? 'si' : 'no'])->all()
            );
        }
        $this->line('Este comando NUNCA crea/modifica bindings -- eso requiere, por separado y de forma deliberada, rule:rebind-safe-to-structure --commit.');
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  UPDATE rem_rules SET config->'\$.section' = '{$d['new_section']}' WHERE id = {$d['rule_id']}  (resto de config sin cambios)");
        $this->line('  + 1 fila nueva en rem_rule_versions (snapshot del config ANTERIOR, para auditoria/rollback)');
        $this->line('  + 1 entrada en el activity log (rule_config_remap_section)');
        $this->line('  Ningun RuleBinding se crea/modifica/borra. Ninguna calibracion, rem_data, ni reglas-funcionales.json se toca.');
    }

    private function commitRemap(array $d, string $reason, string $by): int
    {
        try {
            DB::transaction(function () use ($d, $reason, $by): void {
                $rule = Rule::find($d['rule_id']);
                if (!$rule || $rule->config !== $d['old_config']) {
                    throw new \RuntimeException('La regla cambio entre la validacion y la escritura -- abortando sin persistir.');
                }

                // 'created_by'/'updated_by' son foreignId hacia users.id
                // (nullable) -- este flujo no tiene un usuario autenticado
                // resoluble, igual que el resto de comandos de esta campaña
                // (ver RuleMigrateVettedCommand). La trazabilidad real del
                // responsable ($by) queda en el changelog de RuleVersion y
                // en las properties del activity log, no en esa columna.
                RuleVersion::create([
                    'rule_id' => $rule->id,
                    'version' => $rule->version,
                    'config' => $d['old_config'],
                    'changelog' => "rule:remap-section: config.section '{$d['old_section']}' -> '{$d['new_section']}'. Motivo: {$reason}. Responsable: {$by}. " . now()->toIso8601String(),
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
                        'old_section' => $d['old_section'],
                        'new_section' => $d['new_section'],
                        'reason' => $reason,
                        'by' => $by,
                        'target_structure_id' => $d['structure']->id,
                    ])
                    ->log('rule_config_remap_section');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: config.section actualizado de '{$d['old_section']}' a '{$d['new_section']}'. RuleVersion de auditoria creado con el config anterior. Activity log registrado.");
        $this->warn('No se creo ningun binding -- ejecuta rule:rebind-safe-to-structure por separado (dry-run primero) para vincular la regla a la estructura activa.');

        return self::SUCCESS;
    }
}
