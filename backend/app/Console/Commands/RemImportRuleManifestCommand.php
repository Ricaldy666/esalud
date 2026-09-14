<?php

namespace App\Console\Commands;

use App\Domain\RuleEngine\Exceptions\RuleManifestImportException;
use App\Domain\RuleEngine\Services\RemRuleManifestImporterService;
use Illuminate\Console\Command;

/**
 * BM-6.2. Importa un manifiesto versionado de reglas (JSON en
 * database/seeders/data/). Genérico -- no hardcodea BM ni ninguna serie: la
 * serie/año/hojas/secciones se leen del propio manifiesto y se validan
 * contra la estructura activa real del entorno.
 *
 * Sin --commit (comportamiento por defecto): DRY RUN, 100% read-only --
 * ninguna escritura, ni siquiera de logs de ejecucion. Con --commit: crea
 * las reglas + bindings pendientes dentro de una unica transaccion,
 * abortando por completo si el plan tiene cualquier regla invalida o en
 * conflicto.
 */
class RemImportRuleManifestCommand extends Command
{
    protected $signature = 'rem:import-rule-manifest
                            {manifest : Ruta al archivo JSON del manifiesto}
                            {--commit : Persiste las reglas/bindings pendientes. Sin este flag, el comando es 100% read-only (dry-run).}';

    protected $description = 'Valida (y opcionalmente persiste, con --commit) un manifiesto versionado de reglas contra la estructura activa real de su serie/año.';

    public function handle(RemRuleManifestImporterService $importer): int
    {
        $manifestPath = $this->argument('manifest');
        $commit = (bool) $this->option('commit');

        try {
            $plan = $importer->plan($manifestPath);
        } catch (RuleManifestImportException $e) {
            $this->error('ABORTADO (fallo estructural del manifiesto o del entorno): ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->printPlanReport($plan, $manifestPath);

        if (!empty($plan['invalid']) || !empty($plan['conflicts'])) {
            $this->newLine();
            $this->error('El plan tiene reglas invalidas y/o en conflicto -- no puede commitearse tal cual.');

            return self::FAILURE;
        }

        if (!$commit) {
            $this->newLine();
            $this->warn('DRY-RUN: nada se persistio. Vuelva a ejecutar con --commit para escribir el plan mostrado arriba.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Persistiendo el plan (--commit) dentro de una unica transaccion...');

        try {
            $result = $importer->commit($manifestPath);
        } catch (RuleManifestImportException $e) {
            $this->error('COMMIT ABORTADO, ninguna escritura realizada: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Commit completado: ' . count($result['created_rule_ids']) . ' reglas creadas, ' . count($result['created_binding_ids']) . ' bindings creados.');
        if (!empty($result['skipped'])) {
            $this->line('Omitidas (ya existian con contenido identico): ' . implode(', ', $result['skipped']));
        }

        return self::SUCCESS;
    }

    private function printPlanReport(array $plan, string $manifestPath): void
    {
        $this->info("Manifiesto: {$manifestPath}");
        $this->info("Serie={$plan['serie']} Anio={$plan['anio']} Estructura activa resuelta={$plan['structure']['id']}/v{$plan['structure']['version_number']}");
        $this->newLine();

        $this->table(['Metrica', 'Valor'], [
            ['manifest_rules', $plan['manifest_rule_count']],
            ['expected_rule_count', $plan['expected_rule_count']],
            ['valid', $plan['valid']],
            ['would_create', count($plan['would_create'])],
            ['would_skip', count($plan['would_skip'])],
            ['conflicts', count($plan['conflicts'])],
            ['invalid', count($plan['invalid'])],
            ['bindings_would_create', $plan['bindings_would_create']],
        ]);

        if (!empty($plan['invalid'])) {
            $this->newLine();
            $this->error('Reglas INVALIDAS:');
            foreach ($plan['invalid'] as $i) {
                $this->line("  - {$i['rule_key']}: {$i['reason']}");
            }
        }

        if (!empty($plan['conflicts'])) {
            $this->newLine();
            $this->error('CONFLICTOS (rule_key ya existe con contenido distinto):');
            foreach ($plan['conflicts'] as $c) {
                $this->line("  - {$c['rule_key']}: {$c['reason']}");
            }
        }

        if (!empty($plan['would_skip'])) {
            $this->newLine();
            $this->line('Se omitirian (idempotentes, contenido ya identico): ' . implode(', ', $plan['would_skip']));
        }
    }
}
