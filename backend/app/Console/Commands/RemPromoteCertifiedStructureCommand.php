<?php

namespace App\Console\Commands;

use App\Domain\RemParser\Exceptions\PromotionAbortedException;
use App\Domain\RemParser\Services\CertifiedStructurePromotionService;
use Illuminate\Console\Command;

/**
 * Promueve el paquete generado por `rem:export-certified-promotion` al
 * entorno donde este comando se ejecute. Dry-run por defecto -- SOLO
 * reporta. Escribe unicamente con --commit, y solo si el reporte no
 * encontro ningun conflicto (ver CertifiedStructurePromotionService).
 *
 * Uso previsto: correr primero en LOCAL para validar el mecanismo; correr
 * en produccion recien despues de desplegar el codigo que trae este mismo
 * comando, nunca antes.
 */
class RemPromoteCertifiedStructureCommand extends Command
{
    protected $signature = 'rem:promote-certified-structure
                            {--package= : Ruta al paquete JSON (default: database/seeders/data/rem-certified-promotion.json)}
                            {--approved-by= : Email del usuario de ESTE entorno que aprueba la activacion -- obligatorio para --commit}
                            {--commit : Persiste los cambios. Sin este flag, el comando SOLO reporta (dry-run).}';

    protected $description = 'Promueve el estado certificado (una estructura nueva + catalogo de reglas + bindings en alcance) al entorno donde corre, sin sobrescribir estructuras historicas.';

    public function handle(CertifiedStructurePromotionService $service): int
    {
        $path = $this->option('package') ?: base_path('database/seeders/data/rem-certified-promotion.json');

        if (! file_exists($path)) {
            $this->error("Paquete no encontrado: {$path}. Corra 'php artisan rem:export-certified-promotion' primero.");

            return self::FAILURE;
        }

        $package = json_decode(file_get_contents($path), true);
        if (! is_array($package)) {
            $this->error("Paquete invalido (JSON no decodificable): {$path}");

            return self::FAILURE;
        }

        try {
            $plan = $service->plan($package);
        } catch (PromotionAbortedException $e) {
            $this->error('Paquete rechazado: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($plan, $package);

        if ($plan['abort']) {
            $this->newLine();
            $this->error('ABORTADO -- se detectaron conflictos no previstos, no es seguro continuar:');
            foreach ($plan['abort_reasons'] as $reason) {
                $this->error("  - {$reason}");
            }

            return self::FAILURE;
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->comment('DRY-RUN: no se persistio ningun cambio. Ejecute con --commit --approved-by=<email> para persistir.');

            return self::SUCCESS;
        }

        $approvedBy = $this->option('approved-by');
        if (! $approvedBy) {
            $this->error('--commit requiere --approved-by=<email de un usuario de este entorno>.');

            return self::FAILURE;
        }

        try {
            $result = $service->commit($package, $approvedBy);
        } catch (PromotionAbortedException $e) {
            $this->error('Promoción abortada al comitear: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✅ Promoción completada.');
        $this->table(
            ['Resultado', 'Valor'],
            [
                ['Nueva estructura: id', $result['structure_id']],
                ['Nueva estructura: version_number', $result['structure_version_number']],
                ['Estructura superada (id)', $result['superseded_id'] ?? '(ninguna previa)'],
                ['Reglas creadas / actualizadas', $result['rules']['created'].' / '.$result['rules']['updated']],
                ['Rule versions creadas / actualizadas', $result['rule_versions']['created'].' / '.$result['rule_versions']['updated']],
                ['Bindings creados / actualizados', $result['bindings']['created'].' / '.$result['bindings']['updated']],
            ]
        );

        return self::SUCCESS;
    }

    private function printReport(array $plan, array $package): void
    {
        $s = $plan['structure'];
        $this->info('=== Estructura ===');
        if ($s['category'] === CertifiedStructurePromotionService::CONFLICTO) {
            $this->error("CONFLICTO: {$s['reason']}");
        } else {
            $this->line("NUEVO: se creara anio={$package['structure']['anio']} serie={$package['structure']['serie']} version_number={$s['next_version_number']} (hash=".substr($package['structure']['hash_estructura'], 0, 12).'...)');
            $this->line('Estructura activa actual en este entorno: '.($s['current_active_id']
                ? "id={$s['current_active_id']} version_number={$s['current_active_version_number']} -- pasara a 'superseded', contenido SIN modificar"
                : '(ninguna -- esta seria la primera)'));
        }

        foreach (['rules' => 'Reglas', 'rule_versions' => 'Rule versions', 'bindings' => 'Bindings'] as $key => $label) {
            $p = $plan[$key];
            $this->newLine();
            $this->info("=== {$label} ===");
            $this->line('NUEVO: '.count($p['nuevo']));
            $this->line('IDENTICO (sin cambios): '.count($p['identico']));
            $this->line('ACTUALIZAR: '.count($p['actualizar']));
            $this->line('Registros existentes fuera del paquete, quedan intactos: '.$p['intact_count']);

            if (! empty($p['actualizar'])) {
                $this->line('Detalle de cambios (ACTUALIZAR):');
                foreach (array_slice($p['actualizar'], 0, 20) as $row) {
                    $label2 = $row['rule_key'] ?? $row['key'];
                    $fields = implode(', ', array_keys($row['changes']));
                    $this->line("  - {$label2}: campos distintos -> {$fields}");
                }
                if (count($p['actualizar']) > 20) {
                    $this->line('  ... ('.(count($p['actualizar']) - 20).' mas)');
                }
            }

            if (! empty($p['rule_key_not_in_package'] ?? [])) {
                $this->error("rule_key referenciadas en {$label} pero ausentes del paquete: ".implode(', ', $p['rule_key_not_in_package']));
            }
        }

        $this->newLine();
        $this->line('Bindings excluidos del paquete (historial intermedio local, sin destino en este entorno): '.($plan['excluded_bindings_in_package'] ?? '?'));
    }
}
