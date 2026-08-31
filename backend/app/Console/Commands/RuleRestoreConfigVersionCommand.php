<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Models\Rule;
use App\Domain\RuleEngine\Models\RuleVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restaura rem_rules.config de UNA regla a un snapshot guardado previamente
 * en rem_rule_versions -- mecanismo generico para deshacer una escritura de
 * config puntual (ej. un remap que resulto redundante, ver 529/530,
 * 2026-08-27) usando el propio snapshot que la escritura original ya dejo
 * como evidencia, en vez de un UPDATE manual sin trazabilidad.
 *
 * Deliberadamente DISTINTO de rule:remap-section: ese comando exige que el
 * destino exista en la estructura ACTIVA (correcto para remaps hacia
 * adelante), lo que lo hace inutilizable para revertir hacia una seccion
 * historica que ya no existe estructuralmente (ej. "F", eliminada como
 * entrada fantasma del mecanismo #9 desde la estructura 63). Este comando
 * no valida contra ninguna estructura -- solo restaura config tal como
 * quedo guardado, exigiendo que el snapshot pertenezca genuinamente a la
 * regla indicada.
 *
 * Dry-run por defecto -- persistir exige --commit + --reason + --by. Antes
 * de escribir, crea SIEMPRE un RuleVersion adicional con el config que se
 * esta REEMPLAZANDO (el estado pre-restore), de modo que la cadena de
 * snapshots quede completa y la operacion sea a su vez reversible.
 *
 * Guards:
 *  1. La regla existe.
 *  2. El RuleVersion indicado existe y pertenece a esa regla (rule_id
 *     coincide) -- nunca se acepta un version_id de otra regla.
 *  3. El config actual de la regla difiere del snapshot (si son iguales,
 *     no hay nada que restaurar).
 *  4. Re-validacion inmediata antes de escribir (mismo patron de doble
 *     chequeo que rule:remap-section) -- aborta si el config cambio entre
 *     el dry-run/reporte y la escritura real.
 *
 * NUNCA toca bindings, calibraciones, rem_data, ni ningun otro campo de la
 * regla mas alla de 'config'.
 */
class RuleRestoreConfigVersionCommand extends Command
{
    protected $signature = 'rule:restore-config-version
                            {rule_id : ID de la regla a restaurar}
                            {version_id : ID del RuleVersion (rem_rule_versions) cuyo config se restaura}
                            {--reason= : Motivo del restore -- obligatorio para --commit}
                            {--by= : Responsable -- obligatorio para --commit}
                            {--commit : Persiste la restauracion. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Restaura rem_rules.config de una regla a un snapshot de rem_rule_versions (dry-run por defecto, --commit para persistir)';

    public function handle(): int
    {
        $ruleId = (int) $this->argument('rule_id');
        $versionId = (int) $this->argument('version_id');
        $reason = $this->option('reason');
        $by = $this->option('by');
        $commit = (bool) $this->option('commit');

        $data = $this->computeAndValidate($ruleId, $versionId);
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

        $revalidated = $this->computeAndValidate($ruleId, $versionId);
        if ($revalidated === null || $revalidated['current_config'] !== $data['current_config']) {
            $this->error('El config de la regla cambio entre la validacion y la escritura -- abortando sin persistir.');

            return self::FAILURE;
        }

        return $this->commitRestore($revalidated, $reason, $by);
    }

    private function computeAndValidate(int $ruleId, int $versionId): ?array
    {
        $rule = Rule::find($ruleId);
        if (!$rule) {
            $this->error("No existe ninguna regla con id={$ruleId}.");

            return null;
        }

        $version = RuleVersion::find($versionId);
        if (!$version) {
            $this->error("No existe ningun RuleVersion con id={$versionId}.");

            return null;
        }

        if ($version->rule_id !== $rule->id) {
            $this->error("El RuleVersion {$versionId} pertenece a la regla {$version->rule_id}, no a la regla {$ruleId}. No se restaura.");

            return null;
        }

        $currentConfig = $rule->config;
        $snapshotConfig = $version->config;

        if ($currentConfig === $snapshotConfig) {
            $this->error("El config actual de la regla {$ruleId} ya es identico al snapshot del RuleVersion {$versionId}. No hay nada que restaurar.");

            return null;
        }

        return [
            'rule' => $rule,
            'rule_id' => $ruleId,
            'version' => $version,
            'version_id' => $versionId,
            'current_config' => $currentConfig,
            'snapshot_config' => $snapshotConfig,
        ];
    }

    private function printReport(array $d): void
    {
        $this->info("Regla {$d['rule_id']} ({$d['rule']->rule_key}) -- restore propuesto desde RuleVersion {$d['version_id']}");
        $this->newLine();

        $this->line('Config ACTUAL:');
        $this->line('  ' . json_encode($d['current_config'], JSON_UNESCAPED_UNICODE));
        $this->line("Config del snapshot (RuleVersion {$d['version_id']}, creado {$d['version']->created_at}):");
        $this->line('  ' . json_encode($d['snapshot_config'], JSON_UNESCAPED_UNICODE));

        $this->line('Diff exacto (claves que cambiarian):');
        foreach ($d['current_config'] as $key => $val) {
            $snapshotVal = $d['snapshot_config'][$key] ?? null;
            if ($snapshotVal !== $val) {
                $this->line("  {$key}: " . json_encode($val, JSON_UNESCAPED_UNICODE) . ' -> ' . json_encode($snapshotVal, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->newLine();

        $this->line('Escritura EXACTA que ejecutaria --commit:');
        $this->line("  rem_rules.id={$d['rule_id']}.config <- snapshot de rem_rule_versions.id={$d['version_id']}");
        $this->line('  + 1 fila nueva en rem_rule_versions (snapshot del config ACTUAL, antes de sobreescribirlo -- la restauracion queda a su vez deshacible)');
        $this->line('  + 1 entrada en el activity log (rule_config_restore_version)');
        $this->line('  Ningun RuleBinding, calibracion, rem_data ni otra regla se toca.');
    }

    private function commitRestore(array $d, string $reason, string $by): int
    {
        try {
            DB::transaction(function () use ($d, $reason, $by): void {
                $rule = Rule::find($d['rule_id']);
                if (!$rule || $rule->config !== $d['current_config']) {
                    throw new \RuntimeException('La regla cambio entre la validacion y la escritura -- abortando sin persistir.');
                }

                // Snapshot del estado PRE-restore (lo que se esta
                // reemplazando), para que esta operacion sea a su vez
                // reversible -- misma disciplina que rule:remap-section.
                RuleVersion::create([
                    'rule_id' => $rule->id,
                    'version' => $rule->version,
                    'config' => $d['current_config'],
                    'changelog' => "rule:restore-config-version: restaurado desde RuleVersion {$d['version_id']}. Motivo: {$reason}. Responsable: {$by}. " . now()->toIso8601String(),
                    'created_by' => null,
                ]);

                $rule->update([
                    'config' => $d['snapshot_config'],
                    'updated_by' => null,
                ]);

                activity()
                    ->performedOn($rule)
                    ->withProperties([
                        'rule_id' => $rule->id,
                        'rule_key' => $rule->rule_key,
                        'restored_from_version_id' => $d['version_id'],
                        'previous_config' => $d['current_config'],
                        'restored_config' => $d['snapshot_config'],
                        'reason' => $reason,
                        'by' => $by,
                    ])
                    ->log('rule_config_restore_version');
            });
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Regla {$d['rule_id']}: config restaurado desde RuleVersion {$d['version_id']}. Snapshot del estado anterior creado. Activity log registrado.");

        return self::SUCCESS;
    }
}
